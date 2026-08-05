<?php
/**
 * Background work contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Queue;

/**
 * Schedules work to happen outside the current request.
 *
 * Anything slow is a job. Crawling a site, embedding a document, syncing
 * a CRM and sending a sequence all take longer than a web request may
 * take, and doing them inline is how a plugin earns a reputation for
 * making the admin slow.
 *
 * The interface is deliberately narrower than Action Scheduler's own API.
 * Everything here can be implemented on plain WP-Cron, which keeps the
 * fallback honest — a method that only one driver could implement would
 * make the abstraction a lie the moment Action Scheduler was absent.
 */
interface QueueInterface {

	/**
	 * The group jobs are filed under.
	 */
	public const GROUP = 'hiveclerk';

	/**
	 * Run something as soon as possible.
	 *
	 * @param string               $hook Hook name.
	 * @param array<string, mixed> $args Arguments passed to the handler.
	 * @return bool Whether it was accepted.
	 */
	public function enqueue( string $hook, array $args = array() ): bool;

	/**
	 * Run something at a specific time.
	 *
	 * @param int                  $timestamp Unix time, UTC.
	 * @param string               $hook      Hook name.
	 * @param array<string, mixed> $args      Arguments.
	 * @return bool
	 */
	public function scheduleAt( int $timestamp, string $hook, array $args = array() ): bool;

	/**
	 * Run something repeatedly.
	 *
	 * @param int                  $interval Seconds between runs.
	 * @param string               $hook     Hook name.
	 * @param array<string, mixed> $args     Arguments.
	 * @return bool
	 */
	public function scheduleRecurring( int $interval, string $hook, array $args = array() ): bool;

	/**
	 * Cancel pending work.
	 *
	 * @param string               $hook Hook name.
	 * @param array<string, mixed> $args Arguments identifying the job.
	 * @return void
	 */
	public function cancel( string $hook, array $args = array() ): void;

	/**
	 * Whether matching work is already pending.
	 *
	 * Checked before enqueueing anything idempotent, so a customer
	 * clicking "re-index" four times gets one crawl rather than four.
	 *
	 * @param string               $hook Hook name.
	 * @param array<string, mixed> $args Arguments.
	 * @return bool
	 */
	public function isPending( string $hook, array $args = array() ): bool;

	/**
	 * How many of our jobs are waiting.
	 *
	 * Surfaced on the health screen: a queue depth that only grows is the
	 * clearest signal that cron is not running on a site, and it is
	 * otherwise invisible until a customer asks why nothing indexed.
	 *
	 * @return int Count, or -1 when the driver cannot report one.
	 */
	public function depth(): int;

	/**
	 * Which implementation is in use.
	 *
	 * Reported to the operator rather than kept internal, because the
	 * two drivers have genuinely different reliability and the answer to
	 * "why did my crawl stall" usually starts here.
	 *
	 * @return string
	 */
	public function driver(): string;
}
