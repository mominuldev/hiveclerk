<?php
/**
 * A licence, as this site currently understands it.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Licence;

use DateTimeImmutable;

/**
 * Tier, status and the dates the screen needs — never the key.
 *
 * The key is not on this object and there is no method that returns it.
 * Everything that renders a licence — the settings tab, the sidebar
 * footer, the upgrade prompt — takes one of these, so a presenter cannot
 * leak a key it was never given. The same arrangement as
 * {@see \Hiveclerk\Domain\Integration\Integration} and for the same
 * reason: structural rather than remembered.
 *
 * `masked` is a display string built at storage time, not the key with
 * characters hidden on the way out. A masking function applied late is a
 * masking function that gets forgotten in one of the four places the
 * value is rendered.
 */
final class Licence {

	/**
	 * Construct.
	 *
	 * @param Tier                   $tier      What was bought.
	 * @param LicenceStatus          $status    What the server last said.
	 * @param string|null            $masked    Display form of the key, or null when there is none.
	 * @param int                    $sites     Sites this key is active on.
	 * @param DateTimeImmutable|null $expiresAt When it lapses.
	 * @param DateTimeImmutable|null $checkedAt When the server last answered.
	 * @param string|null            $customer  Who it belongs to, as the server reported.
	 */
	public function __construct(
		public readonly Tier $tier = Tier::Free,
		public readonly LicenceStatus $status = LicenceStatus::Inactive,
		public readonly ?string $masked = null,
		public readonly int $sites = 1,
		public readonly ?DateTimeImmutable $expiresAt = null,
		public readonly ?DateTimeImmutable $checkedAt = null,
		public readonly ?string $customer = null
	) {
	}

	/**
	 * The unlicensed state.
	 *
	 * @return self
	 */
	public static function free(): self {
		return new self();
	}

	/**
	 * The tier whose entitlements actually apply right now.
	 *
	 * ## Graceful degradation
	 *
	 * An expired licence falls back to free entitlements — CRM sync and
	 * email sequences stop, the badge comes back — and nothing else
	 * changes. Clerks keep answering, knowledge stays indexed, leads keep
	 * being captured, and no data is deleted or hidden.
	 *
	 * That is a deliberate line. A lapsed card should not take a
	 * customer's support channel off their website without warning; it
	 * should stop them getting *more* of what they stopped paying for.
	 * The chunk cap is applied to new indexing rather than to existing
	 * chunks for the same reason — see {@see LicenceGate::chunkHeadroom()}.
	 *
	 * @return Tier
	 */
	public function effectiveTier(): Tier {
		return $this->status->grantsEntitlements() ? $this->tier : Tier::Free;
	}

	/**
	 * Whether a key has been entered at all.
	 *
	 * @return bool
	 */
	public function isPresent(): bool {
		return null !== $this->masked && '' !== $this->masked;
	}

	/**
	 * Days until expiry, negative once past.
	 *
	 * @param DateTimeImmutable $now Reference time.
	 * @return int|null Null when the licence has no expiry.
	 */
	public function daysRemaining( DateTimeImmutable $now ): ?int {
		if ( null === $this->expiresAt ) {
			return null;
		}

		return (int) floor( ( $this->expiresAt->getTimestamp() - $now->getTimestamp() ) / 86400 );
	}

	/**
	 * Wire form.
	 *
	 * @param DateTimeImmutable $now Reference time, for the countdown.
	 * @return array<string, mixed>
	 */
	public function toArray( DateTimeImmutable $now ): array {
		$effective = $this->effectiveTier();

		return array(
			'tier'           => $this->tier->value,
			'tier_label'     => $this->tier->label(),
			// The tier in force, which differs from the tier bought
			// whenever a licence has lapsed. A screen that showed only
			// `tier` would say "Pro" above a CRM page that refuses to
			// connect.
			'effective_tier' => $effective->value,
			'status'         => $this->status->value,
			'status_label'   => $this->status->label(),
			'guidance'       => $this->status->guidance(),
			'masked'         => $this->masked,
			'is_set'         => $this->isPresent(),
			'sites'          => $this->sites,
			'site_limit'     => $this->tier->siteLimit(),
			'customer'       => $this->customer,
			'expires_at'     => $this->expiresAt?->format( 'c' ),
			'checked_at'     => $this->checkedAt?->format( 'c' ),
			'days_remaining' => $this->daysRemaining( $now ),
			'limits'         => array(
				'clerks' => $effective->clerkLimit(),
				'chunks' => $effective->chunkLimit(),
			),
			'features'       => array_reduce(
				Feature::cases(),
				static function ( array $carry, Feature $feature ) use ( $effective ): array {
					$carry[ $feature->value ] = $effective->includes( $feature );

					return $carry;
				},
				array()
			),
		);
	}
}
