<?php
/**
 * Outcome of one push.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Integration;

/**
 * What happened when a connector was handed a lead.
 *
 * ## Why `retryable` is a separate flag from `ok`
 *
 * The retry policy needs to tell two failures apart and nothing else can
 * do it for it. A 429 or a 502 means try again in five minutes; a 400
 * saying "email is not a valid address" means try again forever and never
 * succeed, filling the customer's sync log with the same row eighty times.
 * Only the connector knows which of those it just received, so it says so
 * here rather than leaving the policy to guess from a status code it
 * would have to special-case per provider.
 */
final readonly class SyncResult {

	/**
	 * Construct.
	 *
	 * @param bool                 $ok         Whether the far side accepted it.
	 * @param string|null          $externalId The contact's id over there, when returned.
	 * @param string|null          $error      Operator-facing reason for a failure.
	 * @param bool                 $retryable  Whether trying again could work.
	 * @param int|null             $statusCode HTTP status, when there was one.
	 * @param array<string, mixed> $summary    Redacted request detail for the log.
	 */
	public function __construct(
		public bool $ok,
		public ?string $externalId = null,
		public ?string $error = null,
		public bool $retryable = false,
		public ?int $statusCode = null,
		public array $summary = array()
	) {
	}

	/**
	 * A success.
	 *
	 * @param string|null          $externalId Contact id over there.
	 * @param array<string, mixed> $summary    Redacted request detail.
	 * @param int|null             $statusCode HTTP status.
	 * @return self
	 */
	public static function success(
		?string $externalId = null,
		array $summary = array(),
		?int $statusCode = null
	): self {
		return new self( true, $externalId, null, false, $statusCode, $summary );
	}

	/**
	 * A failure worth retrying — a timeout, a 429, a 5xx.
	 *
	 * @param string               $error      Reason.
	 * @param int|null             $statusCode HTTP status.
	 * @param array<string, mixed> $summary    Redacted request detail.
	 * @return self
	 */
	public static function transient(
		string $error,
		?int $statusCode = null,
		array $summary = array()
	): self {
		return new self( false, null, $error, true, $statusCode, $summary );
	}

	/**
	 * A failure that will happen again — bad data, a revoked token.
	 *
	 * @param string               $error      Reason.
	 * @param int|null             $statusCode HTTP status.
	 * @param array<string, mixed> $summary    Redacted request detail.
	 * @return self
	 */
	public static function permanent(
		string $error,
		?int $statusCode = null,
		array $summary = array()
	): self {
		return new self( false, null, $error, false, $statusCode, $summary );
	}
}
