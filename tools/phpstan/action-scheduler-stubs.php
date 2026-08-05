<?php
/**
 * Action Scheduler function signatures, for static analysis only.
 *
 * Action Scheduler is not a Composer dependency. It ships inside
 * WooCommerce and many other plugins, each bundling its own copy and
 * negotiating at runtime which version loads — so adding our own would put
 * weight on the sites that already have it and still not help the ones
 * that do not. ActionSchedulerQueue::isAvailable() checks for it and
 * CronQueue takes over when it is absent.
 *
 * That leaves PHPStan analysing calls to functions it cannot see. Stubbing
 * the signatures is the honest fix: the analyser type-checks every
 * argument and return value as it would for any other dependency, which a
 * suppression would not do. These declarations are never loaded at
 * runtime — phpstan.neon.dist references this file under stubFiles, and
 * nothing else includes it.
 *
 * Signatures follow Action Scheduler 3.x.
 *
 * @package Hiveclerk
 */

/**
 * Enqueue an action to run as soon as possible.
 *
 * @param string       $hook  Hook name.
 * @param array<mixed> $args  Arguments passed to the hook.
 * @param string       $group Group name.
 * @return int Action id, or 0 on failure.
 */
function as_enqueue_async_action( string $hook, array $args = array(), string $group = '' ): int {
}

/**
 * Schedule an action to run once at a given time.
 *
 * @param int          $timestamp Unix time.
 * @param string       $hook      Hook name.
 * @param array<mixed> $args      Arguments passed to the hook.
 * @param string       $group     Group name.
 * @return int Action id, or 0 on failure.
 */
function as_schedule_single_action(
	int $timestamp,
	string $hook,
	array $args = array(),
	string $group = ''
): int {
}

/**
 * Schedule an action to run repeatedly.
 *
 * @param int          $timestamp        Unix time of the first run.
 * @param int          $intervalInSeconds Seconds between runs.
 * @param string       $hook             Hook name.
 * @param array<mixed> $args             Arguments passed to the hook.
 * @param string       $group            Group name.
 * @return int Action id, or 0 on failure.
 */
function as_schedule_recurring_action(
	int $timestamp,
	int $intervalInSeconds,
	string $hook,
	array $args = array(),
	string $group = ''
): int {
}

/**
 * Cancel every pending action matching a hook.
 *
 * @param string            $hook  Hook name.
 * @param array<mixed>      $args  Arguments to match, or an empty array for any.
 * @param string            $group Group name.
 * @return void
 */
function as_unschedule_all_actions( string $hook, array $args = array(), string $group = '' ): void {
}

/**
 * Whether a matching action is scheduled.
 *
 * @param string            $hook  Hook name.
 * @param array<mixed>|null $args  Arguments to match, or null for any.
 * @param string            $group Group name.
 * @return bool
 */
function as_has_scheduled_action( string $hook, ?array $args = null, string $group = '' ): bool {
}

/**
 * Query scheduled actions.
 *
 * @param array<string, mixed> $args        Query arguments.
 * @param string               $returnFormat One of OBJECT, ARRAY_A or ids.
 * @return array<int|string, mixed>
 */
function as_get_scheduled_actions( array $args = array(), string $returnFormat = 'OBJECT' ): array {
}
