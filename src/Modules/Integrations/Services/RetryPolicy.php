<?php
/**
 * Retry schedule.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Integrations\Services;

/**
 * The push, then five retries over roughly fifteen hours (FR-CRM-08, D9 §4).
 *
 * ## The schedule is retries, not attempts
 *
 * D9 §4 lists five intervals. They are the waits *before* each retry, so
 * a lead gets six chances in total: the immediate one, then 1 m, 5 m,
 * 30 m, 2 h and 12 h later. Counting the first push as one of the five
 * would leave the 12-hour entry unreachable and collapse the whole
 * schedule into two and a half hours — which is the difference between a
 * lead that syncs overnight and one that is lost by dinner. It is exactly
 * the kind of off-by-one that no failure ever reports, because the log
 * still shows attempts and retries and a plausible give-up.
 *
 * ## Why these intervals
 *
 * 1 m, 5 m, 30 m, 2 h, 12 h. The first two cover a provider having a bad
 * minute — a deploy, a rate limit, a network blip — and recover without
 * anybody noticing. The last one covers a provider having a bad night,
 * which is the case where a shorter schedule gives up before the outage
 * ends and a lead is lost for a reason the customer never sees.
 *
 * Fifteen hours is deliberately longer than a working day. A lead that
 * failed to sync at 6pm is in the CRM before the sales team arrives, and
 * the alternative — an operator finding a failed row in the morning and
 * pressing retry — is work the product exists to remove.
 *
 * ## Why there is no jitter
 *
 * Jitter exists to stop many clients retrying in lockstep after a shared
 * outage. Every install here has its own queue, its own schedule and its
 * own handful of leads; there is no herd to thunder. Adding randomness
 * would only make "when will it try again" unanswerable on a screen that
 * shows exactly that.
 */
final class RetryPolicy {

	/**
	 * Seconds to wait before each retry.
	 *
	 * @var array<int, int>
	 */
	private const SCHEDULE = array( 60, 300, 1800, 7200, 43200 );

	/**
	 * How many attempts are made in total, the first one included.
	 *
	 * @return int
	 */
	public function maxAttempts(): int {
		return count( self::SCHEDULE ) + 1;
	}

	/**
	 * Whether another attempt is due after this one failed.
	 *
	 * @param int  $attempt   1-based attempt that just failed.
	 * @param bool $retryable Whether the failure could resolve itself.
	 * @return bool
	 */
	public function shouldRetry( int $attempt, bool $retryable ): bool {
		return $retryable && $attempt < $this->maxAttempts();
	}

	/**
	 * Seconds to wait before the attempt after this one.
	 *
	 * @param int $attempt 1-based attempt that just failed.
	 * @return int
	 */
	public function delayAfter( int $attempt ): int {
		$index = max( 0, $attempt - 1 );

		return self::SCHEDULE[ $index ] ?? self::SCHEDULE[ count( self::SCHEDULE ) - 1 ];
	}

	/**
	 * When the attempt after this one is due.
	 *
	 * @param int $attempt 1-based attempt that just failed.
	 * @param int $now     Current Unix time.
	 * @return int
	 */
	public function nextAttemptAt( int $attempt, int $now ): int {
		return $now + $this->delayAfter( $attempt );
	}
}
