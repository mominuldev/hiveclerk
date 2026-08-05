<?php
/**
 * Feature gating by tier.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Licence;

use Hiveclerk\Api\ErrorCode;
use Hiveclerk\Api\Response\ApiResponse;
use WP_Error;

/**
 * The one place the product asks "is this allowed at their tier?".
 *
 * Every gate goes through here rather than reading `$licence->tier`
 * directly, for two reasons. The first is that lapsed licences fall back
 * to free entitlements, and a caller comparing tiers by hand would forget
 * that on the fourth call site. The second is the filter below: a
 * customer running this behind their own agreement, or a developer on a
 * staging copy, needs one supported way to switch a gate off — and one
 * documented seam is safer than six people patching the plugin.
 *
 * ## Limits are checked on the way up, not enforced downwards
 *
 * `chunkHeadroom()` answers "how many more may be indexed", never "delete
 * the excess". A customer whose licence lapses with 9,000 chunks indexed
 * keeps all 9,000 and can index no more. Retrieval carries on working.
 * Deleting a customer's own content because their card expired is not
 * degradation, it is data loss.
 */
final class LicenceGate {

	/**
	 * Construct.
	 *
	 * @param LicenceService $licences Licence state.
	 */
	public function __construct(
		private readonly LicenceService $licences
	) {
	}

	/**
	 * Whether a feature is available.
	 *
	 * @param Feature $feature Feature.
	 * @return bool
	 */
	public function allows( Feature $feature ): bool {
		$tier    = $this->licences->current()->effectiveTier();
		$allowed = $tier->includes( $feature );

		/**
		 * Filter whether a licensed feature is available.
		 *
		 * The supported seam for anybody running this outside the standard
		 * licensing arrangement. Deliberately not filterable per-site
		 * inside a multisite network: a network admin switching a feature
		 * on for one site would be making a licensing decision the network
		 * owner did not agree to.
		 *
		 * @param bool    $allowed Whether it is available.
		 * @param Feature $feature Feature being checked.
		 * @param Tier    $tier    Tier in force.
		 */
		return (bool) apply_filters( 'hiveclerk/licence/allows', $allowed, $feature, $tier );
	}

	/**
	 * A 402 for a feature the tier does not include, or null.
	 *
	 * Returned rather than thrown so a controller can put it straight
	 * back on the wire, and named `hvc_licence_required` because D9 §1.5
	 * says a client reacts to the code rather than pattern-matching
	 * English prose.
	 *
	 * @param Feature $feature Feature.
	 * @return WP_Error|null
	 */
	public function refusal( Feature $feature ): ?WP_Error {
		if ( $this->allows( $feature ) ) {
			return null;
		}

		return ApiResponse::error(
			ErrorCode::LICENCE_REQUIRED,
			sprintf(
				/* translators: 1: feature name, 2: plan name. */
				__( '%1$s is part of %2$s.', 'hiveclerk' ),
				$feature->label(),
				$feature->requires()->label()
			),
			402,
			array(
				'feature' => array( $feature->value ),
				'tier'    => array( $feature->requires()->value ),
			)
		);
	}

	/**
	 * How many more clerks may be created.
	 *
	 * @param int $existing Clerks already on the site.
	 * @return int|null Null means no limit.
	 */
	public function clerkHeadroom( int $existing ): ?int {
		$limit = $this->licences->current()->effectiveTier()->clerkLimit();

		return null === $limit ? null : max( 0, $limit - $existing );
	}

	/**
	 * How many more chunks may be indexed.
	 *
	 * @param int $existing Chunks already indexed.
	 * @return int|null Null means no limit.
	 */
	public function chunkHeadroom( int $existing ): ?int {
		$limit = $this->licences->current()->effectiveTier()->chunkLimit();

		return null === $limit ? null : max( 0, $limit - $existing );
	}

	/**
	 * A 402 when the clerk limit is already reached, or null.
	 *
	 * @param int $existing Clerks already on the site.
	 * @return WP_Error|null
	 */
	public function clerkRefusal( int $existing ): ?WP_Error {
		$headroom = $this->clerkHeadroom( $existing );

		if ( null === $headroom || $headroom > 0 ) {
			return null;
		}

		return ApiResponse::error(
			ErrorCode::LICENCE_REQUIRED,
			sprintf(
				/* translators: %d: number of clerks included. */
				_n(
					'A free licence covers %d clerk. Pro removes the limit.',
					'A free licence covers %d clerks. Pro removes the limit.',
					(int) $this->licences->current()->effectiveTier()->clerkLimit(),
					'hiveclerk'
				),
				(int) $this->licences->current()->effectiveTier()->clerkLimit()
			),
			402,
			array( 'tier' => array( Tier::Pro->value ) )
		);
	}

	/**
	 * A 402 when the chunk cap is already reached, or null.
	 *
	 * @param int $existing Chunks already indexed.
	 * @return WP_Error|null
	 */
	public function chunkRefusal( int $existing ): ?WP_Error {
		$headroom = $this->chunkHeadroom( $existing );

		if ( null === $headroom || $headroom > 0 ) {
			return null;
		}

		return ApiResponse::error(
			ErrorCode::QUOTA_EXCEEDED,
			sprintf(
				/* translators: %s: chunk limit, already formatted. */
				__( 'This licence covers %s indexed chunks, and they are all in use. Everything already indexed keeps working.', 'hiveclerk' ),
				number_format_i18n( (int) $this->licences->current()->effectiveTier()->chunkLimit() )
			),
			402,
			array( 'tier' => array( Tier::Pro->value ) )
		);
	}
}
