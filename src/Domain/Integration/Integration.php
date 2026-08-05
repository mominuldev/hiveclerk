<?php
/**
 * Integration entity.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Integration;

use DateTimeImmutable;

/**
 * One configured connection to something outside this site.
 *
 * ## What is deliberately not on this object
 *
 * Credentials. They live in the `credentials` column and come back only
 * through `IntegrationRepositoryInterface::secret()`, never as a property
 * here. Everything that renders an integration — the grid, the mapping
 * screen, the sync log — is handed one of these, so a presenter cannot
 * leak a token it was never given. That is a structural guarantee rather
 * than a rule somebody has to remember when writing the next presenter.
 *
 * `tokenExpiresAt` *is* here, because "reconnect, your token expired" is
 * a thing the grid has to say and the expiry is not itself a secret.
 */
final class Integration {

	/**
	 * Construct.
	 *
	 * @param int|null               $id             Storage id, null before first save.
	 * @param string                 $provider       Connector identifier.
	 * @param string|null            $name           Account name, as the far side reports it.
	 * @param IntegrationStatus      $status         Connection state.
	 * @param DateTimeImmutable|null $tokenExpiresAt When the access token dies, UTC.
	 * @param FieldMap               $fieldMap       Field mapping.
	 * @param array<string, mixed>   $syncConfig     Trigger, threshold and connector options.
	 * @param DateTimeImmutable|null $lastSyncAt     Last successful push, UTC.
	 * @param string|null            $lastError      Most recent failure, for the card.
	 * @param int                    $errorCount     Consecutive failures.
	 * @param DateTimeImmutable|null $createdAt      Row creation, UTC.
	 * @param DateTimeImmutable|null $updatedAt      Last write, UTC.
	 */
	public function __construct(
		public ?int $id,
		public string $provider,
		public ?string $name = null,
		public IntegrationStatus $status = IntegrationStatus::Disconnected,
		public ?DateTimeImmutable $tokenExpiresAt = null,
		public FieldMap $fieldMap = new FieldMap(),
		public array $syncConfig = array(),
		public ?DateTimeImmutable $lastSyncAt = null,
		public ?string $lastError = null,
		public int $errorCount = 0,
		public ?DateTimeImmutable $createdAt = null,
		public ?DateTimeImmutable $updatedAt = null,
	) {
	}

	/**
	 * How many consecutive failures make a connection "failing".
	 *
	 * Three rather than one. A single timeout is a provider having a bad
	 * minute and the retry policy handles it without anybody being told;
	 * a card that turned amber every time one push was slow would train
	 * the operator to ignore the colour.
	 */
	public const DEGRADED_AFTER = 3;

	/**
	 * When leads get pushed.
	 *
	 * @return SyncTrigger
	 */
	public function trigger(): SyncTrigger {
		$value = $this->syncConfig['trigger'] ?? null;

		return SyncTrigger::fromStorage( is_string( $value ) ? $value : null );
	}

	/**
	 * The score a lead must reach under the score_above trigger.
	 *
	 * @return int
	 */
	public function threshold(): int {
		$value = $this->syncConfig['threshold'] ?? null;

		return is_numeric( $value ) ? (int) $value : 60;
	}

	/**
	 * Whether the whole transcript may be sent along with the contact.
	 *
	 * Off unless asked for. A chat transcript is the most sensitive thing
	 * this plugin holds about a visitor, and copying it into a third-party
	 * SaaS is a decision the customer makes rather than a default they
	 * discover.
	 *
	 * @return bool
	 */
	public function sendsTranscript(): bool {
		return (bool) ( $this->syncConfig['send_transcript'] ?? false );
	}

	/**
	 * A connector-specific option.
	 *
	 * @param string $key      Option name.
	 * @param mixed  $fallback Value when unset.
	 * @return mixed
	 */
	public function option( string $key, mixed $fallback = null ): mixed {
		return $this->syncConfig['options'][ $key ] ?? $fallback;
	}

	/**
	 * Whether this connection can be pushed to right now.
	 *
	 * @return bool
	 */
	public function isUsable(): bool {
		return $this->status->isUsable();
	}

	/**
	 * Record that a push succeeded.
	 *
	 * Clears the error count as well as the message: a connection that
	 * recovered is connected, and leaving the count at six would keep the
	 * card amber until somebody reconnected something that works.
	 *
	 * @param DateTimeImmutable $at When, UTC.
	 * @return void
	 */
	public function recordSuccess( DateTimeImmutable $at ): void {
		$this->lastSyncAt = $at;
		$this->lastError  = null;
		$this->errorCount = 0;
		$this->status     = IntegrationStatus::Connected;
	}

	/**
	 * Record that a push failed for good.
	 *
	 * @param string $error What went wrong.
	 * @return void
	 */
	public function recordFailure( string $error ): void {
		$this->lastError = $error;
		++$this->errorCount;

		if ( $this->errorCount >= self::DEGRADED_AFTER && IntegrationStatus::Connected === $this->status ) {
			$this->status = IntegrationStatus::Degraded;
		}
	}
}
