<?php
/**
 * The sequence tick.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Email\Services;

use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Email\EmailLogRepositoryInterface;
use Hiveclerk\Domain\Email\Enrollment;
use Hiveclerk\Domain\Email\EnrollmentRepositoryInterface;
use Hiveclerk\Domain\Email\SequenceRepositoryInterface;
use Hiveclerk\Domain\Email\SequenceStepRepositoryInterface;
use Hiveclerk\Domain\Lead\LeadRepositoryInterface;

/**
 * Advances every enrolment that is due (FR-EML-01).
 *
 * ## The batch is bounded and the job re-enqueues
 *
 * Twenty-five enrolments per run, each one a render and a `wp_mail()`.
 * The plugin's rule is that no job exceeds roughly twenty seconds, and
 * `wp_mail()` against a remote SMTP server is the slowest thing in this
 * module — a site whose relay takes half a second per message would blow
 * a sixty-second limit at fifty. `tick()` reports whether more is due and
 * the job re-enqueues itself, which is also what makes a backlog of two
 * thousand drain steadily instead of timing out forever at the same row.
 *
 * ## Six checks stand between an enrolment and a send
 *
 * The sequence is still active, the lead still exists, the lead has an
 * address, the address is not suppressed, no exit condition has been met,
 * and this exact step has not already been sent to this enrolment. The
 * last one is the guard against WP-Cron running the same job twice, which
 * it does on any site with overlapping requests — and a duplicate email
 * is the one failure in this module that the recipient sees.
 */
final class SequenceEngine {

	/**
	 * Enrolments advanced per run.
	 */
	public const BATCH = 25;

	/**
	 * Construct.
	 *
	 * @param EnrollmentRepositoryInterface   $enrollments Enrolment storage.
	 * @param SequenceRepositoryInterface     $sequences   Sequence storage.
	 * @param SequenceStepRepositoryInterface $steps       Step storage.
	 * @param EmailLogRepositoryInterface     $log         Send log.
	 * @param LeadRepositoryInterface         $leads       Lead lookup.
	 * @param EmailRenderer                   $renderer    Message rendering.
	 * @param EmailSender                     $sender      Sending.
	 * @param EnrolmentService                $enrolment   Exit conditions.
	 * @param ClockInterface                  $clock       Clock.
	 */
	public function __construct(
		private readonly EnrollmentRepositoryInterface $enrollments,
		private readonly SequenceRepositoryInterface $sequences,
		private readonly SequenceStepRepositoryInterface $steps,
		private readonly EmailLogRepositoryInterface $log,
		private readonly LeadRepositoryInterface $leads,
		private readonly EmailRenderer $renderer,
		private readonly EmailSender $sender,
		private readonly EnrolmentService $enrolment,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * Advance every due enrolment, up to the batch size.
	 *
	 * @return array{sent: int, skipped: int, remaining: int}
	 */
	public function tick(): array {
		$now       = $this->clock->nowSql();
		$remaining = $this->sender->remainingThisHour();

		if ( $remaining <= 0 ) {
			// Over the hourly ceiling. Nothing is dropped — every enrolment
			// keeps its due time and goes out on a later tick.
			return array(
				'sent'      => 0,
				'skipped'   => 0,
				'remaining' => $this->enrollments->countDue( $now ),
			);
		}

		$batch = $this->enrollments->due( $now, min( self::BATCH, $remaining ) );

		$sent    = 0;
		$skipped = 0;

		foreach ( $batch as $enrollment ) {
			if ( $this->advance( $enrollment ) ) {
				++$sent;

				continue;
			}

			++$skipped;
		}

		return array(
			'sent'      => $sent,
			'skipped'   => $skipped,
			'remaining' => $this->enrollments->countDue( $this->clock->nowSql() ),
		);
	}

	/**
	 * Send one enrolment's due step and schedule the next.
	 *
	 * @param Enrollment $enrollment Enrolment.
	 * @return bool Whether an email went out.
	 */
	public function advance( Enrollment $enrollment ): bool {
		$sequence = $this->sequences->find( $enrollment->sequenceId );

		if ( null === $sequence || ! $sequence->status->sends() || null === $sequence->id ) {
			// A paused sequence stops sending and keeps its enrolments.
			// The due time is left alone, so resuming picks up exactly
			// where it stopped rather than firing everything at once.
			return false;
		}

		$lead = $this->leads->find( $enrollment->leadId );

		if ( null === $lead || null === $lead->email ) {
			$this->stop( $enrollment, 'lead_missing' );

			return false;
		}

		$reason = $this->enrolment->exitReason( $sequence, $lead );

		if ( null !== $reason ) {
			$this->stop( $enrollment, $reason );

			return false;
		}

		$step = $this->steps->atPosition( $sequence->id, $enrollment->currentStep );

		if ( null === $step || null === $step->id ) {
			$enrollment->complete( $this->clock->now() );

			$this->enrollments->save( $enrollment );

			return false;
		}

		if ( ! $step->isSendable() ) {
			// An unapproved AI draft holds the whole enrolment rather than
			// being skipped. Skipping would send step three to somebody who
			// never received step two, and the operator would have no
			// signal that anything was wrong.
			return false;
		}

		if ( null !== $enrollment->id && $this->log->alreadySent( $enrollment->id, $step->id ) ) {
			$this->schedule( $enrollment, $sequence->id );

			return false;
		}

		$message = $this->renderer->render( $step, $sequence, $lead, $enrollment );

		if ( null === $message ) {
			$this->stop( $enrollment, 'no_address' );

			return false;
		}

		$result = $this->sender->send( $message );

		$this->schedule( $enrollment, $sequence->id );

		return $result->ok();
	}

	/**
	 * Move an enrolment to its next step, or finish it.
	 *
	 * @param Enrollment $enrollment Enrolment.
	 * @param int        $sequenceId Sequence.
	 * @return void
	 */
	private function schedule( Enrollment $enrollment, int $sequenceId ): void {
		$next = $this->steps->atPosition( $sequenceId, $enrollment->currentStep + 1 );

		if ( null === $next ) {
			$enrollment->complete( $this->clock->now() );
		} else {
			$enrollment->advance(
				$this->clock->now()->modify( '+' . max( 0, $next->delayMinutes ) . ' minutes' )
			);
		}

		$this->enrollments->save( $enrollment );
	}

	/**
	 * Close an enrolment.
	 *
	 * @param Enrollment $enrollment Enrolment.
	 * @param string     $reason     Why.
	 * @return void
	 */
	private function stop( Enrollment $enrollment, string $reason ): void {
		$enrollment->exit( $reason, $this->clock->now() );

		$this->enrollments->save( $enrollment );
	}
}
