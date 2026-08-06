<?php
/**
 * GDPR erasure (FR-SYS-04).
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Privacy;

use Hiveclerk\Core\Audit\AuditLogger;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Domain\Email\EmailLogRepositoryInterface;
use Hiveclerk\Domain\Email\SuppressionRepositoryInterface;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Lead\LeadRepositoryInterface;
use Hiveclerk\Domain\Lead\VisitorRepositoryInterface;

/**
 * Removes a person from this plugin's tables on request.
 *
 * Registered on `wp_privacy_personal_data_erasers`, so it runs from Tools →
 * Erase Personal Data alongside every other plugin's eraser and is covered
 * by the confirmation email WordPress already sends.
 *
 * Two decisions here are worth more than the code:
 *
 * **Erasing a person is not deleting a lead.** `LeadRepository::delete()`
 * detaches conversations and visitors — `lead_id = NULL` — because an
 * operator removing a record from their pipeline should not lose the site's
 * traffic history with it. Reusing that for an erasure would leave the
 * transcript of everything the person typed on the site, orphaned from any
 * name and therefore unreachable through the admin, which reads as erased
 * and is not. Unreachable is not erased. The transcripts, visitors and
 * sessions are removed explicitly, and they are removed *before* the lead,
 * because once the lead row is gone the foreign keys that identify them
 * are gone too.
 *
 * **The suppression list survives.** If somebody unsubscribed and then asked
 * to be forgotten, deleting the record of their unsubscribe means the next
 * import re-subscribes them — the erasure would cause the exact harm it was
 * meant to prevent. What is kept is a SHA-256 of the address and nothing
 * else: the minimum needed to recognise "do not email this person" without
 * being able to reconstruct who they are. WordPress's eraser contract has
 * `items_retained` and a message field for precisely this, and both are
 * used, so the site owner is told rather than left to assume.
 */
final class PersonalDataEraser {

	/**
	 * Conversations removed per pass.
	 */
	private const BATCH = 500;

	/**
	 * Passes attempted before handing the rest back to WordPress.
	 *
	 * Ten thousand conversations for one person is already far outside
	 * anything this product expects. The ceiling exists so a pathological
	 * record cannot hold a request open until the execution limit kills it
	 * mid-erasure, not because reaching it is normal.
	 */
	private const MAX_PASSES = 20;

	/**
	 * Construct.
	 *
	 * @param LeadRepositoryInterface         $leads         Leads.
	 * @param ConversationRepositoryInterface $conversations Conversations.
	 * @param VisitorRepositoryInterface      $visitors      Visitors.
	 * @param EmailLogRepositoryInterface     $emailLog      Email log.
	 * @param SuppressionRepositoryInterface  $suppressions  Suppression list.
	 * @param AuditLogger                     $audit         Audit log.
	 */
	public function __construct(
		private readonly LeadRepositoryInterface $leads,
		private readonly ConversationRepositoryInterface $conversations,
		private readonly VisitorRepositoryInterface $visitors,
		private readonly EmailLogRepositoryInterface $emailLog,
		private readonly SuppressionRepositoryInterface $suppressions,
		private readonly AuditLogger $audit
	) {
	}

	/**
	 * Register with WordPress's privacy tools.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter(
			'wp_privacy_personal_data_erasers',
			function ( $erasers ) {
				if ( ! is_array( $erasers ) ) {
					return $erasers;
				}

				$erasers['hiveclerk'] = array(
					'eraser_friendly_name' => __( 'Hiveclerk conversations and leads', 'hiveclerk' ),
					'callback'             => array( $this, 'erase' ),
				);

				return $erasers;
			}
		);
	}

	/**
	 * Erase everything this plugin holds about an address.
	 *
	 * Normally one pass. A person with more conversations than a single
	 * request should delete reports `done: false` and is called again, and
	 * the lead row is deliberately left in place until its transcripts are
	 * gone — the lead is the only route to them, so deleting it first would
	 * strand whatever had not been reached yet. That is the failure this
	 * pagination exists to prevent, not one it introduces.
	 *
	 * The page number is ignored. Erasure is destructive, so every call
	 * starts from whatever is still there; an offset would skip rows that
	 * the previous pass had already shifted.
	 *
	 * @param string $email Address supplied by the site owner.
	 * @param int    $page  Page number, unused.
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public function erase( string $email, int $page = 1 ): array {
		unset( $page );

		$normalised = Lead::normaliseEmail( $email );

		if ( null === $normalised ) {
			return $this->result( false, false, array(), true );
		}

		$hash     = Lead::hashEmail( $normalised );
		$lead     = null === $hash ? null : $this->leads->findByEmailHash( $hash );
		$removed  = 0;
		$messages = array();
		$done     = true;

		if ( null !== $lead && null !== $lead->id ) {
			[ $removed, $done ] = $this->eraseLead( $lead->id );
		}

		$removed += $this->emailLog->deleteForEmail( $normalised );

		$retained = null !== $hash && $this->suppressions->isSuppressed( $hash );

		if ( $retained ) {
			$messages[] = __(
				'Hiveclerk kept a one-way hash of this address on its do-not-email list. Without it, the next import would start emailing this person again. No other detail was kept.',
				'hiveclerk'
			);
		}

		if ( $removed > 0 ) {
			/*
			 * Recorded because an erasure is the most consequential
			 * deletion the product performs and the least reversible. The
			 * address itself is not written to the log — a record of the
			 * erasure that stores what was erased defeats it — so what is
			 * kept is the count and the fact that it happened.
			 */
			$this->audit->record(
				'privacy.erased',
				array( 'records_removed' => $removed )
			);
		}

		return $this->result( $removed > 0, $retained, $messages, $done );
	}

	/**
	 * Remove a lead and everything hanging off it.
	 *
	 * @param int $leadId Lead storage id.
	 * @return array{0: int, 1: bool} Records removed, and whether the lead is finished.
	 */
	private function eraseLead( int $leadId ): array {
		$removed  = 0;
		$complete = false;

		/*
		 * Conversations and visitors first, while `lead_id` still points
		 * at them. `LeadRepository::delete()` nulls both columns, so a
		 * lead deleted first takes the only route to its own transcripts
		 * with it.
		 *
		 * Batched rather than fetched whole: the previous single read of
		 * a thousand rows silently left anything past it behind, and then
		 * deleted the lead, which made the remainder unreachable through
		 * any screen. An erasure that reports success while leaving
		 * transcripts on the site is the one outcome this class exists to
		 * rule out.
		 */
		for ( $pass = 0; $pass < self::MAX_PASSES; $pass++ ) {
			$conversationIds = array();

			foreach ( $this->conversations->forLead( $leadId, self::BATCH ) as $conversation ) {
				if ( null !== $conversation->id ) {
					$conversationIds[] = $conversation->id;
				}
			}

			if ( array() === $conversationIds ) {
				$complete = true;

				break;
			}

			$removed += $this->conversations->purge( $conversationIds );
		}

		// Out of passes with transcripts still present. The lead stays so
		// the next call can find it again by email hash and carry on.
		if ( ! $complete ) {
			return array( $removed, false );
		}

		$removed += $this->visitors->deleteForLead( $leadId );

		if ( $this->leads->delete( $leadId ) ) {
			++$removed;
		}

		return array( $removed, true );
	}

	/**
	 * Shape an eraser response.
	 *
	 * @param bool               $removed  Whether anything went.
	 * @param bool               $retained Whether anything stayed.
	 * @param array<int, string> $messages Notes for the site owner.
	 * @param bool               $done     Whether there is nothing left to do.
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	private function result( bool $removed, bool $retained, array $messages, bool $done ): array {
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => $done,
		);
	}
}
