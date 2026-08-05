<?php
/**
 * The do-not-email list.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Email\Services;

use Hiveclerk\Domain\Email\EnrollmentRepositoryInterface;
use Hiveclerk\Domain\Email\SuppressionReason;
use Hiveclerk\Domain\Email\SuppressionRepositoryInterface;
use Hiveclerk\Domain\Lead\Activity;
use Hiveclerk\Domain\Lead\ActivityRepositoryInterface;
use Hiveclerk\Domain\Lead\ActivityType;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Lead\LeadRepositoryInterface;
use Hiveclerk\Core\Support\ClockInterface;

/**
 * Honouring an unsubscribe, everywhere at once (FR-EML-06).
 *
 * ## Suppressing exits every sequence, not just the one they clicked from
 *
 * Somebody who unsubscribes from a follow-up email has not asked to stop
 * receiving *that sequence*. They have asked to stop receiving email. A
 * list that only stopped the current sequence would deliver the next one
 * a week later and be, correctly, reported as ignoring the request.
 *
 * The exit is written on the enrolments as well as the address being
 * suppressed. The suppression alone would be enough to block the send,
 * but it would leave forty enrolments sitting active forever, each one
 * waking the engine on schedule to decide not to send.
 */
final class SuppressionList {

	/**
	 * Construct.
	 *
	 * @param SuppressionRepositoryInterface $suppressions Storage.
	 * @param EnrollmentRepositoryInterface  $enrollments  Enrolment storage.
	 * @param LeadRepositoryInterface        $leads        Lead lookup.
	 * @param ActivityRepositoryInterface    $activities   Lead timeline.
	 * @param ClockInterface                 $clock        Clock.
	 */
	public function __construct(
		private readonly SuppressionRepositoryInterface $suppressions,
		private readonly EnrollmentRepositoryInterface $enrollments,
		private readonly LeadRepositoryInterface $leads,
		private readonly ActivityRepositoryInterface $activities,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * Whether an address must not be written to.
	 *
	 * @param string $email Address.
	 * @return bool
	 */
	public function blocks( string $email ): bool {
		$hash = Lead::hashEmail( $email );

		// An address that is not an address is blocked. Sending to it
		// cannot work, and letting it through would make every send
		// attempt a bounce the site is judged on.
		return null === $hash || $this->suppressions->isSuppressed( $hash );
	}

	/**
	 * Whether an address hash is suppressed.
	 *
	 * @param string $emailHash Address hash.
	 * @return bool
	 */
	public function blocksHash( string $emailHash ): bool {
		return $this->suppressions->isSuppressed( $emailHash );
	}

	/**
	 * Suppress an address and stop everything in flight for it.
	 *
	 * @param string            $emailHash Address hash.
	 * @param SuppressionReason $reason    Why.
	 * @return bool Whether a lead was found and unenrolled.
	 */
	public function suppressHash( string $emailHash, SuppressionReason $reason ): bool {
		$this->suppressions->suppress( $emailHash, $reason );

		$lead = $this->leads->findByEmailHash( $emailHash );

		if ( null === $lead || null === $lead->id ) {
			// A suppression with no matching lead is still recorded. The
			// address may belong to a lead that was erased, or to somebody
			// forwarded the email by a colleague — both are people who
			// have asked not to be written to.
			return false;
		}

		foreach ( $this->enrollments->openForLead( $lead->id ) as $enrollment ) {
			$enrollment->exit( $reason->value, $this->clock->now() );

			$this->enrollments->save( $enrollment );
		}

		$this->activities->record(
			new Activity(
				id: null,
				type: ActivityType::NoteAdded,
				title: sprintf(
					/* translators: %s: suppression reason. */
					__( 'Removed from email: %s', 'hiveclerk' ),
					$reason->label()
				),
				leadId: $lead->id,
				metadata: array( 'reason' => $reason->value ),
				createdAt: $this->clock->now(),
			)
		);

		/**
		 * Fires when an address is added to the suppression list.
		 *
		 * @param Lead              $lead   The lead, when one matched.
		 * @param SuppressionReason $reason Why.
		 */
		do_action( 'hiveclerk/email/suppressed', $lead, $reason );

		return true;
	}

	/**
	 * Suppress a plain address.
	 *
	 * @param string            $email  Address.
	 * @param SuppressionReason $reason Why.
	 * @return bool
	 */
	public function suppress( string $email, SuppressionReason $reason ): bool {
		$hash = Lead::hashEmail( $email );

		return null !== $hash && $this->suppressHash( $hash, $reason );
	}

	/**
	 * How many addresses are suppressed.
	 *
	 * @return int
	 */
	public function count(): int {
		return $this->suppressions->countAll();
	}
}
