<?php
/**
 * Rate limit outcome.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Support;

/**
 * The outcome of a rate-limit check, including the headers to send back.
 */
final readonly class RateLimitResult {

	/**
	 * Construct.
	 *
	 * @param bool $allowed   Whether the request may proceed.
	 * @param int  $limit     Configured ceiling.
	 * @param int  $remaining Requests left in this window.
	 * @param int  $resetIn   Seconds until the window rolls.
	 */
	public function __construct(
		public bool $allowed,
		public int $limit,
		public int $remaining,
		public int $resetIn
	) {
	}

	/**
	 * Headers describing the current budget.
	 *
	 * Sent on every response, not just rejections, so a well-behaved client
	 * can slow down before it is turned away.
	 *
	 * @return array<string, string>
	 */
	public function headers(): array {
		return array(
			'X-RateLimit-Limit'     => (string) $this->limit,
			'X-RateLimit-Remaining' => (string) $this->remaining,
			'X-RateLimit-Reset'     => (string) $this->resetIn,
		);
	}
}
