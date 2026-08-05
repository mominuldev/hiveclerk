<?php
/**
 * Licence states.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Licence;

/**
 * What the licence server last said about this key.
 *
 * `Unreachable` is a state of its own, and it is the important one. A
 * self-hosted plugin whose licence check fails closed turns every outage
 * at our end — or every customer firewall that blocks outbound HTTP —
 * into a downgrade the customer did not cause and cannot fix. Every entitlement
 * check treats it as "keep working", and the screen says the check has
 * not succeeded recently rather than pretending it has.
 */
enum LicenceStatus: string {

	case Inactive    = 'inactive';
	case Active      = 'active';
	case Expired     = 'expired';
	case Invalid     = 'invalid';
	case SeatLimit   = 'seat_limit';
	case Unreachable = 'unreachable';

	/**
	 * Parse a stored or remote value.
	 *
	 * @param string|null $value Raw value.
	 * @return self
	 */
	public static function fromStorage( ?string $value ): self {
		return self::tryFrom( strtolower( (string) $value ) ) ?? self::Inactive;
	}

	/**
	 * Whether paid entitlements apply.
	 *
	 * @return bool
	 */
	public function grantsEntitlements(): bool {
		return self::Active === $this || self::Unreachable === $this;
	}

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Inactive    => 'No licence',
			self::Active      => 'Active',
			self::Expired     => 'Expired',
			self::Invalid     => 'Not recognised',
			self::SeatLimit   => 'Site limit reached',
			self::Unreachable => 'Could not be checked',
		};
	}

	/**
	 * What the operator should do about it, if anything.
	 *
	 * @return string|null
	 */
	public function guidance(): ?string {
		return match ( $this ) {
			self::Inactive    => null,
			self::Active      => null,
			self::Expired     => 'Your clerks keep answering. Renewing restores CRM sync, email sequences and the removable badge.',
			self::Invalid     => 'Check the key against your purchase receipt, or contact support.',
			self::SeatLimit   => 'Deactivate the licence on a site you no longer use, or move up a plan.',
			self::Unreachable => 'The licence server could not be reached. Nothing has been switched off; this will retry on its own.',
		};
	}
}
