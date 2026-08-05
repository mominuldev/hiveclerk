<?php
/**
 * Sequence management.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Email\Services;

use Hiveclerk\Core\Audit\AuditLogger;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Email\EmailLogRepositoryInterface;
use Hiveclerk\Domain\Email\EmailSequence;
use Hiveclerk\Domain\Email\EnrollmentRepositoryInterface;
use Hiveclerk\Domain\Email\ExitCondition;
use Hiveclerk\Domain\Email\SequenceRepositoryInterface;
use Hiveclerk\Domain\Email\SequenceStatus;
use Hiveclerk\Domain\Email\SequenceStep;
use Hiveclerk\Domain\Email\SequenceStepRepositoryInterface;
use Hiveclerk\Domain\Email\TriggerType;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Modules\Email\Support\EmailException;

/**
 * Creating, editing and activating sequences.
 *
 * ## Activation is a gate, not a status change
 *
 * A sequence goes active only when it has at least one step, every step
 * has a subject and a body, and every AI-drafted step has been approved.
 * Refusing here — with a message naming the step that is not ready — is
 * the difference between an operator finding out now and finding out from
 * a recipient who received an empty email.
 *
 * ## Editing an approved AI draft withdraws its approval
 *
 * Approval attaches to words. A step approved on Tuesday and reworded on
 * Wednesday has not been read by anybody, and leaving the approval in
 * place would turn the FR-EML-03 gate into a formality that any edit
 * walks past.
 */
final class SequenceService {

	public const CREATED     = 'email.sequence.created';
	public const UPDATED     = 'email.sequence.updated';
	public const ACTIVATED   = 'email.sequence.activated';
	public const PAUSED      = 'email.sequence.paused';
	public const DELETED     = 'email.sequence.deleted';
	public const STEP_SAVED  = 'email.step.saved';
	public const STEP_OKAYED = 'email.step.approved';

	/**
	 * Steps one sequence may hold.
	 *
	 * Twelve is more than any follow-up sequence should be and few enough
	 * that the builder stays readable on one screen.
	 */
	public const MAX_STEPS = 12;

	/**
	 * Construct.
	 *
	 * @param SequenceRepositoryInterface     $sequences   Sequence storage.
	 * @param SequenceStepRepositoryInterface $steps       Step storage.
	 * @param EnrollmentRepositoryInterface   $enrollments Enrolment storage.
	 * @param EmailLogRepositoryInterface     $log         Send log.
	 * @param AuditLogger                     $audit       Audit log.
	 * @param ClockInterface                  $clock       Clock.
	 */
	public function __construct(
		private readonly SequenceRepositoryInterface $sequences,
		private readonly SequenceStepRepositoryInterface $steps,
		private readonly EnrollmentRepositoryInterface $enrollments,
		private readonly EmailLogRepositoryInterface $log,
		private readonly AuditLogger $audit,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * Create a sequence.
	 *
	 * @param array<string, mixed> $input Cleaned fields.
	 * @return EmailSequence
	 *
	 * @throws EmailException When the name is missing.
	 */
	public function create( array $input ): EmailSequence {
		$name = trim( (string) ( $input['name'] ?? '' ) );

		if ( '' === $name ) {
			throw EmailException::invalid( __( 'Give the sequence a name.', 'hiveclerk' ) );
		}

		$sequence = $this->sequences->save(
			new EmailSequence(
				id: null,
				uuid: Uuid::generate(),
				name: $name,
				// Always draft. A sequence that went live the moment it was
				// named would start emailing before it had an email in it.
				status: SequenceStatus::Draft,
				trigger: TriggerType::fromStorage( isset( $input['trigger'] ) ? (string) $input['trigger'] : null ),
				triggerConfig: $this->triggerConfig( $input ),
				exitConditions: $this->exitConditions( $input['exit_conditions'] ?? null ),
				fromName: $this->nullable( $input['from_name'] ?? null ),
				fromEmail: $this->nullable( $input['from_email'] ?? null ),
				replyTo: $this->nullable( $input['reply_to'] ?? null ),
				createdAt: $this->clock->now(),
			)
		);

		$this->audit->record( self::CREATED, array( 'name' => $name ), 'email_sequence', $sequence->id );

		return $sequence;
	}

	/**
	 * Change a sequence.
	 *
	 * @param EmailSequence        $sequence Sequence.
	 * @param array<string, mixed> $input    Cleaned fields.
	 * @return EmailSequence
	 */
	public function update( EmailSequence $sequence, array $input ): EmailSequence {
		if ( isset( $input['name'] ) && '' !== trim( (string) $input['name'] ) ) {
			$sequence->name = trim( (string) $input['name'] );
		}

		if ( isset( $input['trigger'] ) ) {
			$sequence->trigger = TriggerType::fromStorage( (string) $input['trigger'] );
		}

		$sequence->triggerConfig = array_merge( $sequence->triggerConfig, $this->triggerConfig( $input ) );

		if ( array_key_exists( 'exit_conditions', $input ) ) {
			$sequence->exitConditions = $this->exitConditions( $input['exit_conditions'] );
		}

		foreach ( array(
			'from_name'  => 'fromName',
			'from_email' => 'fromEmail',
			'reply_to'   => 'replyTo',
		) as $key => $property ) {
			if ( array_key_exists( $key, $input ) ) {
				$sequence->{$property} = $this->nullable( $input[ $key ] );
			}
		}

		$sequence = $this->sequences->save( $sequence );

		$this->audit->record( self::UPDATED, array( 'name' => $sequence->name ), 'email_sequence', $sequence->id );

		return $sequence;
	}

	/**
	 * Put a sequence live.
	 *
	 * @param EmailSequence $sequence Sequence.
	 * @return EmailSequence
	 *
	 * @throws EmailException When a step is not ready.
	 */
	public function activate( EmailSequence $sequence ): EmailSequence {
		if ( null === $sequence->id ) {
			throw EmailException::invalid( __( 'Save the sequence before activating it.', 'hiveclerk' ) );
		}

		$steps = $this->steps->forSequence( $sequence->id );

		if ( array() === $steps ) {
			throw EmailException::invalid( __( 'Add at least one email before activating this sequence.', 'hiveclerk' ) );
		}

		foreach ( $steps as $index => $step ) {
			$blocker = $step->blocker();

			if ( null !== $blocker ) {
				throw EmailException::invalid(
					sprintf(
						/* translators: 1: step number, 2: what needs doing. */
						__( 'Email %1$d is not ready: %2$s', 'hiveclerk' ),
						$index + 1,
						$blocker
					)
				);
			}
		}

		$sequence->status = SequenceStatus::Active;

		$sequence = $this->sequences->save( $sequence );

		$this->audit->record(
			self::ACTIVATED,
			array(
				'name'  => $sequence->name,
				'steps' => count( $steps ),
			),
			'email_sequence',
			$sequence->id
		);

		return $sequence;
	}

	/**
	 * Stop a sequence sending, keeping its enrolments.
	 *
	 * @param EmailSequence $sequence Sequence.
	 * @return EmailSequence
	 */
	public function pause( EmailSequence $sequence ): EmailSequence {
		$sequence->status = SequenceStatus::Paused;

		$sequence = $this->sequences->save( $sequence );

		$this->audit->record( self::PAUSED, array( 'name' => $sequence->name ), 'email_sequence', $sequence->id );

		return $sequence;
	}

	/**
	 * Soft-delete a sequence.
	 *
	 * @param EmailSequence $sequence Sequence.
	 * @return void
	 */
	public function delete( EmailSequence $sequence ): void {
		if ( null === $sequence->id ) {
			return;
		}

		// Everybody in it is taken out of it, one row at a time. The engine
		// checks the sequence before it sends, so leaving them active
		// would stop the email either way — but it would also leave rows
		// the due-work query keeps loading forever, on a sequence nobody
		// can open to find out why.
		$closed = 0;

		foreach ( $this->enrollments->openForSequence( $sequence->id ) as $enrollment ) {
			$enrollment->exit( 'sequence_deleted', $this->clock->now() );

			$this->enrollments->save( $enrollment );

			++$closed;
		}

		$this->sequences->softDelete( $sequence->id );

		$this->audit->record(
			self::DELETED,
			array(
				'name'      => $sequence->name,
				'unenroled' => $closed,
			),
			'email_sequence',
			$sequence->id
		);
	}

	/**
	 * Add or change one email.
	 *
	 * @param EmailSequence        $sequence Sequence.
	 * @param SequenceStep|null    $step     Existing step, or null to add.
	 * @param array<string, mixed> $input    Cleaned fields.
	 * @return SequenceStep
	 *
	 * @throws EmailException When the sequence is full.
	 */
	public function saveStep( EmailSequence $sequence, ?SequenceStep $step, array $input ): SequenceStep {
		if ( null === $sequence->id ) {
			throw EmailException::invalid( __( 'Save the sequence before adding emails to it.', 'hiveclerk' ) );
		}

		if ( null === $step ) {
			if ( $this->steps->countFor( $sequence->id ) >= self::MAX_STEPS ) {
				throw EmailException::invalid(
					sprintf(
						/* translators: %d: maximum number of steps. */
						__( 'A sequence holds at most %d emails.', 'hiveclerk' ),
						self::MAX_STEPS
					)
				);
			}

			$step = new SequenceStep(
				id: null,
				sequenceId: $sequence->id,
				position: $this->steps->countFor( $sequence->id ),
				// A first step defaults to no wait and every later one to a
				// day. Zero on step four means four emails in one minute,
				// which is the shape of an accident rather than a plan.
				delayMinutes: 0 === $this->steps->countFor( $sequence->id ) ? 0 : 1440,
				createdAt: $this->clock->now(),
			);
		}

		$copyChanged = false;

		if ( array_key_exists( 'subject', $input ) ) {
			$subject = (string) $input['subject'];

			if ( $subject !== $step->subject ) {
				$copyChanged = true;
			}

			$step->subject = $subject;
		}

		if ( array_key_exists( 'body_html', $input ) ) {
			$body = (string) $input['body_html'];

			if ( $body !== $step->bodyHtml ) {
				$copyChanged = true;
			}

			$step->bodyHtml = $body;
		}

		if ( array_key_exists( 'body_text', $input ) ) {
			$step->bodyText = $this->nullable( $input['body_text'] );
		}

		if ( isset( $input['delay_minutes'] ) && is_numeric( $input['delay_minutes'] ) ) {
			$step->delayMinutes = max( 0, (int) $input['delay_minutes'] );
		}

		if ( array_key_exists( 'ai_generated', $input ) ) {
			$step->aiGenerated = (bool) $input['ai_generated'];
		}

		if ( $copyChanged && $step->aiGenerated ) {
			$step->revokeApproval();
		}

		$step = $this->steps->save( $step );

		$this->audit->record(
			self::STEP_SAVED,
			array(
				'sequence' => $sequence->name,
				'position' => $step->position,
				'ai'       => $step->aiGenerated,
			),
			'email_step',
			$step->id
		);

		return $step;
	}

	/**
	 * Sign off an AI-drafted email.
	 *
	 * @param SequenceStep $step   Step.
	 * @param int          $userId Who approved it.
	 * @return SequenceStep
	 *
	 * @throws EmailException When the step is not ready to be approved.
	 */
	public function approveStep( SequenceStep $step, int $userId ): SequenceStep {
		if ( '' === trim( $step->subject ) || '' === trim( $step->bodyHtml ) ) {
			throw EmailException::invalid( __( 'Finish writing this email before approving it.', 'hiveclerk' ) );
		}

		$step->approve( $userId, $this->clock->now() );

		$step = $this->steps->save( $step );

		$this->audit->record( self::STEP_OKAYED, array( 'position' => $step->position ), 'email_step', $step->id );

		return $step;
	}

	/**
	 * Remove one email.
	 *
	 * @param EmailSequence $sequence Sequence.
	 * @param SequenceStep  $step     Step.
	 * @return void
	 */
	public function deleteStep( EmailSequence $sequence, SequenceStep $step ): void {
		if ( null === $step->id || null === $sequence->id ) {
			return;
		}

		$this->steps->delete( $step->id );

		// Positions are closed up rather than left with a hole. An
		// enrolment sitting on step 3 of a sequence whose step 3 was
		// deleted would otherwise finish silently and early.
		$remaining = array();

		foreach ( $this->steps->forSequence( $sequence->id ) as $existing ) {
			if ( null !== $existing->id ) {
				$remaining[] = $existing->id;
			}
		}

		$this->steps->reorder( $remaining );
	}

	/**
	 * How a sequence is performing.
	 *
	 * @param EmailSequence $sequence Sequence.
	 * @return array<string, int>
	 */
	public function stats( EmailSequence $sequence ): array {
		if ( null === $sequence->id ) {
			return array();
		}

		return array_merge(
			$this->enrollments->statusCounts( $sequence->id ),
			$this->log->statsFor( $sequence->id )
		);
	}

	/**
	 * Read the trigger settings out of submitted input.
	 *
	 * @param array<string, mixed> $input Cleaned fields.
	 * @return array<string, mixed>
	 */
	private function triggerConfig( array $input ): array {
		$config = array();

		foreach ( array( 'threshold', 'stage_id', 'abandon_after' ) as $key ) {
			if ( isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ) {
				$config[ $key ] = (int) $input[ $key ];
			}
		}

		return $config;
	}

	/**
	 * Clean a submitted exit-condition list.
	 *
	 * @param mixed $raw Raw conditions.
	 * @return array<int, array<string, mixed>>
	 */
	private function exitConditions( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array();

		foreach ( $raw as $condition ) {
			if ( ! is_array( $condition ) ) {
				continue;
			}

			$type = ExitCondition::fromStorage(
				isset( $condition['type'] ) ? sanitize_key( (string) $condition['type'] ) : null
			);

			if ( null === $type ) {
				continue;
			}

			$value = $condition['value'] ?? null;

			$clean[] = array(
				'type'  => $type->value,
				'value' => is_scalar( $value ) ? sanitize_text_field( (string) $value ) : null,
			);
		}

		return $clean;
	}

	/**
	 * A trimmed string, or null when it is blank.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private function nullable( mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$trimmed = trim( $value );

		return '' === $trimmed ? null : $trimmed;
	}
}
