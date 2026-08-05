<?php
/**
 * Licence tiers.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Licence;

/**
 * The five things a customer can be paying for (D16 §2).
 *
 * The limits live on the tier rather than on a settings screen, and that
 * is the point: a limit an operator can edit is not a limit. What a
 * customer bought is a fact about their licence, and the only thing that
 * changes it is a different licence.
 *
 * Free is limited by *scale*, never by quality. A free install gets real
 * vector retrieval, real streaming, real citations and real lead
 * capture — just less of it. D16 §3 is explicit about why: a crippled
 * free tier produces one-star reviews on WordPress.org, which is the
 * distribution channel the entire go-to-market depends on.
 */
enum Tier: string {

	case Free     = 'free';
	case Pro      = 'pro';
	case Business = 'business';
	case Agency   = 'agency';
	case Managed  = 'managed';

	/**
	 * Parse a stored or remote value, defaulting to free.
	 *
	 * Unknown values fall to free rather than throwing. A licence server
	 * that starts returning a tier this version has never heard of must
	 * not fatal the customer's admin — and falling *down* is the only
	 * safe direction to guess in.
	 *
	 * @param string|null $value Raw value.
	 * @return self
	 */
	public static function fromStorage( ?string $value ): self {
		return self::tryFrom( strtolower( (string) $value ) ) ?? self::Free;
	}

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Free     => 'Free',
			self::Pro      => 'Pro',
			self::Business => 'Business',
			self::Agency   => 'Agency',
			self::Managed  => 'Managed',
		};
	}

	/**
	 * Whether this tier is anything other than free.
	 *
	 * @return bool
	 */
	public function isPaid(): bool {
		return self::Free !== $this;
	}

	/**
	 * How many clerks may exist.
	 *
	 * @return int|null Null means no limit.
	 */
	public function clerkLimit(): ?int {
		return self::Free === $this ? 1 : null;
	}

	/**
	 * How many indexed chunks may exist.
	 *
	 * The 200-chunk free cap is the product's primary upgrade mechanic
	 * (D16 §3): generous enough to prove the product works on a real
	 * site, small enough that any genuine business site passes it within
	 * days.
	 *
	 * @return int|null Null means no limit.
	 */
	public function chunkLimit(): ?int {
		return match ( $this ) {
			self::Free     => 200,
			self::Pro      => 10000,
			self::Business => 50000,
			self::Managed  => 25000,
			self::Agency   => null,
		};
	}

	/**
	 * How many sites one licence covers.
	 *
	 * @return int
	 */
	public function siteLimit(): int {
		return match ( $this ) {
			self::Free, self::Pro, self::Managed => 1,
			self::Business                       => 5,
			self::Agency                         => 25,
		};
	}

	/**
	 * Whether a feature is included at this tier.
	 *
	 * @param Feature $feature Feature.
	 * @return bool
	 */
	public function includes( Feature $feature ): bool {
		return match ( $feature ) {
			Feature::Crm, Feature::EmailSequences, Feature::RemoveBadge => $this->isPaid(),
			Feature::Multisite  => in_array( $this, array( self::Business, self::Agency ), true ),
			Feature::WhiteLabel => self::Agency === $this,
		};
	}
}
