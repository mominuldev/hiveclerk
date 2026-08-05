<?php
/**
 * How many clerks may be on duty at once.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Agents\Services;

use Hiveclerk\Core\Settings\SettingsRepository;
use Hiveclerk\Domain\Agent\Agent;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Agent\AgentStatus;

/**
 * The free-tier ceiling on published clerks (FR-CLK-09).
 *
 * Kept as its own object rather than folded into AgentService for one
 * reason: Sprint 9 replaces where the tier comes from, and nothing else
 * should have to change when it does. `LicenceService` will bind over
 * this class's single question — what tier is this site on — and the
 * enforcement, the error code and the wording stay put.
 *
 * The limit is counted, not cached. A cached count of published clerks
 * that drifts by one is a customer who paid for Pro and cannot publish,
 * or a free site running two clerks; the query is a single indexed COUNT
 * on a table with tens of rows.
 */
final class PublishPolicy {

	/**
	 * Clerks a free site may have on duty at once.
	 */
	public const FREE_LIMIT = 1;

	/**
	 * Construct.
	 *
	 * @param AgentRepositoryInterface $agents   Clerk storage.
	 * @param SettingsRepository       $settings Settings.
	 */
	public function __construct(
		private readonly AgentRepositoryInterface $agents,
		private readonly SettingsRepository $settings
	) {
	}

	/**
	 * The licence tier this site is running under.
	 *
	 * @return string
	 */
	public function tier(): string {
		$stored = $this->settings->get( 'licence.tier', 'free' );
		$tier   = is_string( $stored ) && '' !== $stored ? $stored : 'free';

		/**
		 * Filter the licence tier used for feature gating.
		 *
		 * The seam LicenceService binds over in Sprint 9. It is a filter
		 * today so that a site running a build without licensing can be
		 * put onto the unrestricted path deliberately, rather than by
		 * having the gate quietly not written.
		 *
		 * @param string $tier Licence tier.
		 */
		$filtered = apply_filters( 'hiveclerk/licence/tier', $tier );

		return is_string( $filtered ) && '' !== $filtered ? $filtered : $tier;
	}

	/**
	 * How many clerks may be published, or null for unlimited.
	 *
	 * @return int|null
	 */
	public function limit(): ?int {
		return 'free' === $this->tier() ? self::FREE_LIMIT : null;
	}

	/**
	 * How many are on duty now.
	 *
	 * @return int
	 */
	public function published(): int {
		return $this->agents->count( array( 'status' => AgentStatus::Published->value ) );
	}

	/**
	 * Whether this clerk may go on duty.
	 *
	 * A clerk that is already published always may. Re-publishing an
	 * on-duty clerk is what a save does, and refusing it would make the
	 * cap fire on the one action that does not change the count.
	 *
	 * @param Agent $agent The clerk.
	 * @return bool
	 */
	public function allowsPublishing( Agent $agent ): bool {
		$limit = $this->limit();

		if ( null === $limit ) {
			return true;
		}

		if ( AgentStatus::Published === $agent->status ) {
			return true;
		}

		return $this->published() < $limit;
	}

	/**
	 * Why publishing was refused, in the operator's terms.
	 *
	 * @return string
	 */
	public function refusalMessage(): string {
		return sprintf(
			/* translators: %d: number of clerks a free licence may have on duty. */
			_n(
				'A free licence keeps %d clerk on duty at a time. Pause the one that is working, or upgrade to run more.',
				'A free licence keeps %d clerks on duty at a time. Pause one, or upgrade to run more.',
				self::FREE_LIMIT,
				'hiveclerk'
			),
			self::FREE_LIMIT
		);
	}
}
