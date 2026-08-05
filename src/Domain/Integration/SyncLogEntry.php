<?php
/**
 * Sync log entry.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Integration;

use DateTimeImmutable;

/**
 * One attempt, kept whether it worked or not (FR-CRM-08).
 *
 * Every attempt gets a row, not every push. Five rows saying "attempt 3
 * of 5, 502 from the provider, next try at 14:20" is what makes a stuck
 * sync legible; one row that quietly changes status is a screen where
 * nothing ever appears to be happening.
 *
 * `requestSummary` is redacted before it arrives here. It records which
 * fields were sent rather than what was in them, because a log row is the
 * one place a lead's phone number could survive a GDPR erasure request.
 */
final class SyncLogEntry {

	/**
	 * Construct.
	 *
	 * @param int|null               $id            Storage id.
	 * @param int                    $integrationId Which connection.
	 * @param string                 $operation     push_contact, push_activity, webhook or test.
	 * @param SyncStatus             $status        Outcome.
	 * @param int|null               $leadId        Which lead, when there was one.
	 * @param int                    $attempt       1-based attempt number.
	 * @param string|null            $externalId    Contact id over there.
	 * @param array<string, mixed>   $requestSummary Redacted request detail.
	 * @param int|null               $responseCode  HTTP status.
	 * @param string|null            $error         Failure reason.
	 * @param DateTimeImmutable|null $nextRetryAt   When the next attempt is due, UTC.
	 * @param DateTimeImmutable|null $createdAt     When, UTC.
	 */
	public function __construct(
		public ?int $id,
		public int $integrationId,
		public string $operation,
		public SyncStatus $status,
		public ?int $leadId = null,
		public int $attempt = 1,
		public ?string $externalId = null,
		public array $requestSummary = array(),
		public ?int $responseCode = null,
		public ?string $error = null,
		public ?DateTimeImmutable $nextRetryAt = null,
		public ?DateTimeImmutable $createdAt = null,
	) {
	}

	public const OP_PUSH_CONTACT  = 'push_contact';
	public const OP_PUSH_ACTIVITY = 'push_activity';
	public const OP_WEBHOOK       = 'webhook';
	public const OP_TEST          = 'test';
}
