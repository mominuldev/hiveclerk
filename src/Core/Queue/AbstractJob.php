<?php
/**
 * Job base class.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Queue;

/**
 * Shared helpers for background jobs.
 *
 * Argument reading is here rather than in each job because job arguments
 * arrive from storage, not from a caller. Action Scheduler serialised
 * them possibly weeks ago, under a previous version of the plugin, and a
 * job that assumes its arguments still have the shape it was written
 * against fatals on exactly the sites that upgraded mid-queue.
 */
abstract class AbstractJob implements JobInterface {

	/**
	 * Read an integer argument.
	 *
	 * @param array<string, mixed> $args     Arguments.
	 * @param string               $key      Name.
	 * @param int                  $fallback Value when absent or wrong-typed.
	 * @return int
	 */
	protected static function intArg( array $args, string $key, int $fallback = 0 ): int {
		$value = $args[ $key ] ?? null;

		return is_numeric( $value ) ? (int) $value : $fallback;
	}

	/**
	 * Read a string argument.
	 *
	 * @param array<string, mixed> $args     Arguments.
	 * @param string               $key      Name.
	 * @param string               $fallback Value when absent or wrong-typed.
	 * @return string
	 */
	protected static function stringArg( array $args, string $key, string $fallback = '' ): string {
		$value = $args[ $key ] ?? null;

		return is_string( $value ) ? $value : $fallback;
	}

	/**
	 * Read an array argument.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @param string               $key  Name.
	 * @return array<mixed>
	 */
	protected static function arrayArg( array $args, string $key ): array {
		$value = $args[ $key ] ?? null;

		return is_array( $value ) ? $value : array();
	}
}
