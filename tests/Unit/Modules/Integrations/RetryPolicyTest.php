<?php
/**
 * Retry policy tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Modules\Integrations;

use Hiveclerk\Modules\Integrations\Services\RetryPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The backoff schedule D9 §4 specifies.
 *
 * The behaviour worth protecting is that a lead which failed to sync at
 * six in the evening is still being retried the next morning. A schedule
 * that quietly shortened would lose leads during exactly the outages it
 * exists for, and nothing would report it.
 *
 * @internal
 */
#[CoversClass( RetryPolicy::class )]
final class RetryPolicyTest extends TestCase {

	private RetryPolicy $policy;

	protected function setUp(): void {
		parent::setUp();

		$this->policy = new RetryPolicy();
	}

	public function test_it_makes_the_push_plus_five_retries(): void {
		// Six, not five. The five intervals D9 lists are the waits before
		// each retry; the first push has no wait before it.
		$this->assertSame( 6, $this->policy->maxAttempts() );
	}

	public function test_it_follows_the_documented_intervals(): void {
		$this->assertSame( 60, $this->policy->delayAfter( 1 ) );
		$this->assertSame( 300, $this->policy->delayAfter( 2 ) );
		$this->assertSame( 1800, $this->policy->delayAfter( 3 ) );
		$this->assertSame( 7200, $this->policy->delayAfter( 4 ) );
		$this->assertSame( 43200, $this->policy->delayAfter( 5 ) );
	}

	public function test_the_whole_schedule_outlasts_a_working_day(): void {
		$total = 0;

		for ( $attempt = 1; $attempt < $this->policy->maxAttempts(); $attempt++ ) {
			$total += $this->policy->delayAfter( $attempt );
		}

		// A lead that failed at 18:00 is in the CRM before the sales team
		// arrives. Anything shorter loses it overnight.
		$this->assertGreaterThan( 9 * 3600, $total );
	}

	public function test_it_stops_after_the_last_attempt(): void {
		$this->assertTrue( $this->policy->shouldRetry( 5, true ) );
		$this->assertFalse( $this->policy->shouldRetry( 6, true ) );
	}

	public function test_the_longest_backoff_is_actually_reachable(): void {
		// The regression this file was written for: with five total
		// attempts only four delays are ever used, the 12-hour entry is
		// dead code, and the schedule quietly collapses to two hours.
		$this->assertTrue( $this->policy->shouldRetry( 5, true ) );
		$this->assertSame( 43200, $this->policy->delayAfter( 5 ) );
	}

	public function test_a_permanent_failure_is_never_retried(): void {
		// A 400 saying "that is not a valid address" would otherwise be
		// repeated five times over fifteen hours, filling the customer's
		// sync log with the same row.
		$this->assertFalse( $this->policy->shouldRetry( 1, false ) );
	}

	public function test_it_schedules_from_the_time_it_is_given(): void {
		$this->assertSame( 1_700_000_060, $this->policy->nextAttemptAt( 1, 1_700_000_000 ) );
	}

	public function test_an_attempt_past_the_end_reuses_the_longest_delay(): void {
		// Defensive: a queued job from an older version could carry an
		// attempt number this schedule no longer has an entry for, and
		// reading past the end of the array would be a fatal in a job.
		$this->assertSame( 43200, $this->policy->delayAfter( 99 ) );
	}
}
