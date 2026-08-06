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
	 * Unreachable for so long that entitlements can no longer rest on it.
	 *
	 * Every safety valve in this system opens the same way: a signature
	 * that cannot be verified is `Unreachable`, a missing sodium extension
	 * skips verification, and `Unreachable` keeps whatever the site
	 * already had. Each is right on its own — none of them should switch a
	 * paying customer off because of a network fault. Composed, and with
	 * no time limit, they meant that anyone able to keep this site from
	 * reaching the licence server kept it on its current tier for ever.
	 *
	 * This is that time limit, and it is deliberately not `Invalid`: we
	 * still know nothing about the key, so we still do not claim anything
	 * about it.
	 */
	case Unverified = 'unverified';

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
	 * Whether this state means the server's answer is simply not known.
	 *
	 * Both of these must never be reported to an operator as a problem
	 * with their key, because neither is evidence about the key at all.
	 *
	 * @return bool
	 */
	public function isUnconfirmed(): bool {
		return self::Unreachable === $this || self::Unverified === $this;
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
			self::Unverified  => 'Not confirmed recently',
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
			self::Unverified  => 'The licence server has not been reachable for long enough that paid features have paused. '
				. 'Your key has not been rejected and nothing has been deleted — everything returns the moment a check gets through. '
				. 'If this site blocks outbound HTTPS, allowing it is the fix.',
		};
	}
}
