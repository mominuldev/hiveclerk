<?php
/**
 * Integration status.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Integration;

/**
 * What state a connection is in, from the operator's point of view.
 *
 * `Degraded` is the one that earns its place. A connection whose last six
 * pushes failed is not disconnected — the credentials are still there and
 * the next push might work — but calling it connected puts a green dot on
 * a card while leads silently pile up unsynced. D11 §8 draws it as
 * "⚠ 6 failed syncs", which is a state the card has to be able to hold.
 */
enum IntegrationStatus: string {

	case Disconnected = 'disconnected';
	case Connected    = 'connected';
	case Degraded     = 'degraded';
	case Expired      = 'expired';

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Disconnected => 'Not connected',
			self::Connected    => 'Connected',
			self::Degraded     => 'Failing',
			self::Expired      => 'Token expired',
		};
	}

	/**
	 * Whether a push should even be attempted.
	 *
	 * Degraded still pushes: the whole point of the retry policy is that a
	 * provider having a bad hour recovers on its own.
	 *
	 * @return bool
	 */
	public function isUsable(): bool {
		return self::Connected === $this || self::Degraded === $this;
	}

	/**
	 * Parse a stored value, defaulting to disconnected.
	 *
	 * @param string|null $value Stored value.
	 * @return self
	 */
	public static function fromStorage( ?string $value ): self {
		return self::tryFrom( (string) $value ) ?? self::Disconnected;
	}
}
