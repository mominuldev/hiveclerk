<?php
/**
 * How long conversations are kept.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Chat\Services;

use DateTimeImmutable;
use Hiveclerk\Core\Settings\SettingsRepository;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Domain\Conversation\SessionRepositoryInterface;

/**
 * The retention policy and the deletion it implies (FR-CNV-07).
 *
 * The cutoff is computed from the setting on every run rather than
 * stamped onto each conversation when it starts. That is a deliberate
 * choice with a visible consequence: shortening the policy purges history
 * that already exists, which is what an operator means when they set a
 * retention policy — usually because a customer or a regulator asked them
 * to. A stamped `purge_after` would only apply to conversations that had
 * not happened yet, which is the opposite of the promise.
 *
 * Deleting is irreversible and unattended, so the count of what a policy
 * would remove is available before it runs, and the admin shows it.
 */
final class RetentionService {

	/**
	 * Conversations deleted in one pass of the job.
	 *
	 * Each one takes three statements inside a transaction. A hundred is
	 * a few seconds on a shared host, which keeps the job well inside the
	 * twenty-second ceiling every job in this product works to.
	 */
	public const BATCH = 100;

	/**
	 * Longest retention a site may configure, in months.
	 *
	 * Five years. Past that the setting is indistinguishable from "keep
	 * forever", which is its own value and is spelled 0.
	 */
	public const MAX_MONTHS = 60;

	/**
	 * Construct.
	 *
	 * @param ConversationRepositoryInterface $conversations Conversation storage.
	 * @param SessionRepositoryInterface      $sessions      Session storage.
	 * @param SettingsRepository              $settings      Settings.
	 * @param ClockInterface                  $clock         Clock.
	 */
	public function __construct(
		private readonly ConversationRepositoryInterface $conversations,
		private readonly SessionRepositoryInterface $sessions,
		private readonly SettingsRepository $settings,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * Months of history kept, or 0 for forever.
	 *
	 * @return int
	 */
	public function months(): int {
		$value = $this->settings->get( 'privacy.retention_months', 12 );

		if ( ! is_numeric( $value ) ) {
			return 12;
		}

		return max( 0, min( self::MAX_MONTHS, (int) $value ) );
	}

	/**
	 * The instant before which conversations are deleted, or null.
	 *
	 * @return DateTimeImmutable|null
	 */
	public function cutoff(): ?DateTimeImmutable {
		$months = $this->months();

		if ( 0 === $months ) {
			return null;
		}

		return $this->clock->now()->modify( sprintf( '-%d months', $months ) );
	}

	/**
	 * How many conversations the current policy would delete.
	 *
	 * @return int
	 */
	public function pending(): int {
		$cutoff = $this->cutoff();

		if ( null === $cutoff ) {
			return 0;
		}

		return $this->conversations->countStartedBefore( $cutoff->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Delete one batch of expired conversations.
	 *
	 * @param int $limit Batch size.
	 * @return int Conversations deleted.
	 */
	public function purgeBatch( int $limit = self::BATCH ): int {
		$cutoff = $this->cutoff();

		if ( null === $cutoff ) {
			return 0;
		}

		$ids = $this->conversations->idsStartedBefore(
			$cutoff->format( 'Y-m-d H:i:s' ),
			max( 1, $limit )
		);

		if ( array() === $ids ) {
			return 0;
		}

		$deleted = $this->conversations->purge( $ids );

		/**
		 * Fires after a batch of conversations is purged by the policy.
		 *
		 * @param int               $deleted How many went.
		 * @param DateTimeImmutable $cutoff  The cutoff applied.
		 */
		do_action( 'hiveclerk/retention/purged', $deleted, $cutoff );

		return $deleted;
	}

	/**
	 * Delete session rows that have already expired.
	 *
	 * Not governed by the retention policy: an expired session is a dead
	 * token hash and a foreign key, useful to nobody the moment it lapses.
	 * It rides along with the retention job because the alternative is a
	 * second nightly job doing one DELETE.
	 *
	 * @param int $limit Rows per pass.
	 * @return int
	 */
	public function purgeSessions( int $limit = 500 ): int {
		return $this->sessions->purgeExpired( $this->clock->nowSql(), $limit );
	}
}
