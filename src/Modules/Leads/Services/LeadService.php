<?php
/**
 * Lead lifecycle.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Leads\Services;

use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Domain\Lead\Activity;
use Hiveclerk\Domain\Lead\ActivityRepositoryInterface;
use Hiveclerk\Domain\Lead\ActivityType;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Lead\LeadRepositoryInterface;
use Hiveclerk\Domain\Lead\LeadStageRepositoryInterface;
use Hiveclerk\Domain\Lead\LeadStatus;
use Hiveclerk\Domain\Lead\ScoreEventRepositoryInterface;
use Hiveclerk\Domain\Lead\VisitorRepositoryInterface;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Modules\Leads\Support\LeadException;

/**
 * Everything an operator does to a lead, and the record of it.
 *
 * Lead changes are written to the lead's own timeline rather than to the
 * audit log. The audit log answers "who changed the configuration of this
 * site", and a salesperson moving a card between two columns forty times
 * a day would bury the one entry in it that matters — the API key. The
 * pipeline's own history belongs on the screen where somebody asks about
 * it, which is the lead.
 */
final class LeadService {

	/**
	 * Construct.
	 *
	 * @param LeadRepositoryInterface         $leads         Lead storage.
	 * @param LeadStageRepositoryInterface    $stages        Stage storage.
	 * @param ActivityRepositoryInterface     $activities    Timeline.
	 * @param ScoreEventRepositoryInterface   $events        Score log.
	 * @param VisitorRepositoryInterface      $visitors      Visitor storage.
	 * @param ConversationRepositoryInterface $conversations Conversation storage.
	 * @param ScoringService                  $scoring       Scoring.
	 * @param ClockInterface                  $clock         Clock.
	 */
	public function __construct(
		private readonly LeadRepositoryInterface $leads,
		private readonly LeadStageRepositoryInterface $stages,
		private readonly ActivityRepositoryInterface $activities,
		private readonly ScoreEventRepositoryInterface $events,
		private readonly VisitorRepositoryInterface $visitors,
		private readonly ConversationRepositoryInterface $conversations,
		private readonly ScoringService $scoring,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * Find by public identifier.
	 *
	 * @param Uuid $uuid Public identifier.
	 * @return Lead|null
	 */
	public function findByUuid( Uuid $uuid ): ?Lead {
		return $this->leads->findByUuid( $uuid );
	}

	/**
	 * Create a lead by hand.
	 *
	 * @param array<string, mixed> $input Cleaned fields.
	 * @param int|null             $userId Who created it.
	 * @return Lead
	 *
	 * @throws LeadException When the address already belongs to somebody.
	 */
	public function create( array $input, ?int $userId = null ): Lead {
		$email = isset( $input['email'] ) ? (string) $input['email'] : '';
		$hash  = '' === $email ? null : Lead::hashEmail( $email );

		if ( '' !== $email && null === $hash ) {
			throw new LeadException( __( 'That is not an email address.', 'hiveclerk' ) );
		}

		if ( null !== $hash && null !== $this->leads->findByEmailHash( $hash ) ) {
			throw LeadException::conflict(
				__( 'A lead with that email address already exists. Open it instead of creating a second one.', 'hiveclerk' )
			);
		}

		$lead = new Lead(
			id: null,
			uuid: Uuid::generate(),
			email: null === $hash ? null : Lead::normaliseEmail( $email ),
			emailHash: $hash,
			stageId: $this->defaultStageId(),
			source: 'manual',
			firstSeenAt: $this->clock->now(),
			lastActiveAt: $this->clock->now(),
		);

		$this->applyFields( $lead, $input );

		$lead = $this->leads->save( $lead );

		$this->note(
			$lead,
			ActivityType::LeadCaptured,
			__( 'Lead added by hand', 'hiveclerk' ),
			null,
			$userId
		);

		return $lead;
	}

	/**
	 * Change a lead's fields.
	 *
	 * @param Lead                 $lead   The lead.
	 * @param array<string, mixed> $input  Cleaned fields.
	 * @param int|null             $userId Who changed it.
	 * @return Lead
	 *
	 * @throws LeadException When a new address belongs to somebody else.
	 */
	public function update( Lead $lead, array $input, ?int $userId = null ): Lead {
		if ( array_key_exists( 'email', $input ) ) {
			$this->changeEmail( $lead, (string) $input['email'] );
		}

		$this->applyFields( $lead, $input );

		if ( array_key_exists( 'status', $input ) ) {
			$status = LeadStatus::tryFrom( (string) $input['status'] );

			if ( null !== $status && $status !== $lead->status ) {
				$this->applyStatus( $lead, $status, $userId );
			}
		}

		if ( array_key_exists( 'owner_user_id', $input ) ) {
			$owner = $input['owner_user_id'];

			$lead->ownerUserId = is_numeric( $owner ) && (int) $owner > 0 ? (int) $owner : null;
		}

		return $this->leads->save( $lead );
	}

	/**
	 * Move a lead to a stage (FR-LED-05).
	 *
	 * @param Lead     $lead    The lead.
	 * @param int|null $stageId Destination, or null to unstage.
	 * @param int|null $userId  Who moved it.
	 * @return Lead
	 *
	 * @throws LeadException When the stage does not exist.
	 */
	public function moveToStage( Lead $lead, ?int $stageId, ?int $userId = null ): Lead {
		$stage = null;

		if ( null !== $stageId ) {
			$stage = $this->stages->find( $stageId );

			if ( null === $stage ) {
				throw LeadException::notFound( __( 'That pipeline stage does not exist.', 'hiveclerk' ) );
			}
		}

		if ( $lead->stageId === $stageId ) {
			return $lead;
		}

		$from          = null === $lead->stageId ? null : $this->stages->find( $lead->stageId );
		$lead->stageId = $stageId;
		$implied       = $stage?->impliedStatus();
		$statusChanged = null !== $implied && $implied !== $lead->status;

		if ( $statusChanged ) {
			// Only the terminal columns speak for the status. Moving a card
			// into "Demo booked" says nothing about whether the lead is
			// qualified, and guessing would overwrite what scoring decided.
			$this->applyStatus( $lead, $implied, $userId );
		}

		$this->leads->save( $lead );

		$this->note(
			$lead,
			ActivityType::StageChanged,
			sprintf(
				/* translators: 1: previous stage name, 2: new stage name. */
				__( 'Stage %1$s → %2$s', 'hiveclerk' ),
				null === $from ? __( 'unassigned', 'hiveclerk' ) : $from->name,
				null === $stage ? __( 'unassigned', 'hiveclerk' ) : $stage->name
			),
			null,
			$userId,
			array(
				'from' => $from?->slug,
				'to'   => $stage?->slug,
			)
		);

		/**
		 * Fires when a lead moves between pipeline stages.
		 *
		 * @param Lead $lead    The lead.
		 * @param int|null $stageId New stage.
		 */
		do_action( 'hiveclerk/lead/stage_changed', $lead, $stageId );

		return $lead;
	}

	/**
	 * Merge one lead into another (FR-LED-08).
	 *
	 * Everything moves: conversations, score events, activities and the
	 * visitors behind them. The score is then rebuilt from the combined
	 * event log rather than added together, because two leads that each
	 * scored "gave a business email" would otherwise be worth thirty
	 * points for one address — and a `once` rule cannot un-fire.
	 *
	 * @param Lead     $winner Lead that survives.
	 * @param Lead     $loser  Lead being merged away.
	 * @param int|null $userId Who did it.
	 * @return Lead
	 *
	 * @throws LeadException When the two are the same lead.
	 */
	public function merge( Lead $winner, Lead $loser, ?int $userId = null ): Lead {
		if ( null === $winner->id || null === $loser->id || $winner->id === $loser->id ) {
			throw new LeadException( __( 'Pick two different leads to merge.', 'hiveclerk' ) );
		}

		foreach ( array( 'firstName', 'lastName', 'phone', 'company', 'jobTitle', 'website' ) as $field ) {
			$value = $loser->{$field};

			if ( is_string( $value ) ) {
				$winner->fillIfEmpty( $field, $value );
			}
		}

		// The loser's answers fill gaps only. The surviving lead is the one
		// somebody has been working, and its answers are the ones they have
		// been reading.
		foreach ( $loser->customFields as $key => $value ) {
			if ( ! array_key_exists( $key, $winner->customFields ) ) {
				$winner->customFields[ $key ] = $value;
			}
		}

		if ( null === $winner->emailHash && null !== $loser->emailHash ) {
			$winner->email     = $loser->email;
			$winner->emailHash = $loser->emailHash;
		}

		$this->conversations->reassignLead( $loser->id, $winner->id );
		$this->events->reassign( $loser->id, $winner->id );
		$this->activities->reassign( $loser->id, $winner->id );
		$this->visitors->reassign( $loser->id, $winner->id );

		// The loser is deleted before the winner is saved, because both
		// hold an email hash under a unique index and the winner may have
		// just taken the loser's.
		$this->leads->delete( $loser->id );

		$winner = $this->leads->save( $winner );

		$this->scoring->recalculate( $winner );

		$this->note(
			$winner,
			ActivityType::LeadMerged,
			sprintf(
				/* translators: %s: name or email of the lead that was merged in. */
				__( 'Merged %s into this lead', 'hiveclerk' ),
				$loser->displayName()
			),
			null,
			$userId
		);

		return $winner;
	}

	/**
	 * Delete a lead and everything attributed to it.
	 *
	 * @param Lead $lead The lead.
	 * @return bool
	 */
	public function delete( Lead $lead ): bool {
		return null !== $lead->id && $this->leads->delete( $lead->id );
	}

	/**
	 * The lead's timeline, newest first (FR-LED-06).
	 *
	 * @param Lead $lead  The lead.
	 * @param int  $limit Maximum rows.
	 * @return array<int, Activity>
	 */
	public function timeline( Lead $lead, int $limit = 100 ): array {
		if ( null === $lead->id ) {
			return array();
		}

		$visitorIds = array();

		foreach ( $this->visitors->forLead( $lead->id ) as $visitor ) {
			if ( null !== $visitor->id ) {
				$visitorIds[] = $visitor->id;
			}
		}

		return $this->activities->timeline( $lead->id, $visitorIds, $limit );
	}

	/**
	 * Add a line to the timeline.
	 *
	 * @param Lead                 $lead     The lead.
	 * @param ActivityType         $type     What happened.
	 * @param string               $title    One-line summary.
	 * @param string|null          $body     Detail.
	 * @param int|null             $userId   Who did it.
	 * @param array<string, mixed> $metadata Structured detail.
	 * @return Activity
	 */
	public function note(
		Lead $lead,
		ActivityType $type,
		string $title,
		?string $body = null,
		?int $userId = null,
		array $metadata = array()
	): Activity {
		return $this->activities->record(
			new Activity(
				id: null,
				type: $type,
				title: $title,
				leadId: $lead->id,
				wpUserId: $userId,
				body: $body,
				metadata: $metadata,
				createdAt: $this->clock->now(),
			)
		);
	}

	/**
	 * Apply a status change and stamp the conversion time with it.
	 *
	 * @param Lead       $lead   The lead.
	 * @param LeadStatus $status New status.
	 * @param int|null   $userId Who changed it.
	 * @return void
	 */
	private function applyStatus( Lead $lead, LeadStatus $status, ?int $userId ): void {
		$previous     = $lead->status;
		$lead->status = $status;

		if ( LeadStatus::Converted === $status && null === $lead->convertedAt ) {
			$lead->convertedAt = $this->clock->now();
		}

		$this->note(
			$lead,
			ActivityType::StatusChanged,
			sprintf(
				/* translators: 1: previous status, 2: new status. */
				__( 'Status %1$s → %2$s', 'hiveclerk' ),
				$previous->label(),
				$status->label()
			),
			null,
			$userId,
			array(
				'from' => $previous->value,
				'to'   => $status->value,
			)
		);
	}

	/**
	 * Change the address a lead is deduplicated by.
	 *
	 * @param Lead   $lead  The lead.
	 * @param string $email New address, or empty to clear it.
	 * @return void
	 *
	 * @throws LeadException When the address belongs to another lead.
	 */
	private function changeEmail( Lead $lead, string $email ): void {
		if ( '' === trim( $email ) ) {
			$lead->email     = null;
			$lead->emailHash = null;

			return;
		}

		$hash = Lead::hashEmail( $email );

		if ( null === $hash ) {
			throw new LeadException( __( 'That is not an email address.', 'hiveclerk' ) );
		}

		if ( $hash === $lead->emailHash ) {
			return;
		}

		$existing = $this->leads->findByEmailHash( $hash );

		if ( null !== $existing && $existing->id !== $lead->id ) {
			throw LeadException::conflict(
				__( 'Another lead already has that email address. Merge them instead.', 'hiveclerk' )
			);
		}

		$lead->email     = Lead::normaliseEmail( $email );
		$lead->emailHash = $hash;
	}

	/**
	 * Copy the plain writable fields onto a lead.
	 *
	 * @param Lead                 $lead  The lead.
	 * @param array<string, mixed> $input Cleaned fields.
	 * @return void
	 */
	private function applyFields( Lead $lead, array $input ): void {
		$map = array(
			'first_name' => 'firstName',
			'last_name'  => 'lastName',
			'phone'      => 'phone',
			'company'    => 'company',
			'job_title'  => 'jobTitle',
			'website'    => 'website',
		);

		foreach ( $map as $key => $property ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			$value = trim( (string) $input[ $key ] );

			$lead->{$property} = '' === $value ? null : $value;
		}

		if ( isset( $input['custom_fields'] ) && is_array( $input['custom_fields'] ) ) {
			$lead->customFields = array_merge( $lead->customFields, $input['custom_fields'] );
		}
	}

	/**
	 * The leftmost stage, which is where a new lead lands.
	 *
	 * @return int|null
	 */
	private function defaultStageId(): ?int {
		foreach ( $this->stages->all() as $stage ) {
			if ( ! $stage->isTerminal() ) {
				return $stage->id;
			}
		}

		return null;
	}
}
