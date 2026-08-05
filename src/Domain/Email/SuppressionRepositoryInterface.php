<?php
/**
 * Suppression storage contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Email;

use Hiveclerk\Domain\Shared\Pagination;

/**
 * The list of addresses that are never written to again.
 *
 * Stored as a hash of the normalised address and nothing else. That is
 * what lets the row survive the erasure of the lead it came from: D7 §5
 * keeps this table out of the GDPR eraser deliberately, because honouring
 * an unsubscribe is a legal basis for retaining the one value needed to
 * honour it, and deleting the record of a request is how somebody starts
 * receiving email again after asking twice to stop.
 */
interface SuppressionRepositoryInterface {

	/**
	 * Whether an address is suppressed.
	 *
	 * @param string $emailHash SHA-256 of the normalised address.
	 * @return bool
	 */
	public function isSuppressed( string $emailHash ): bool;

	/**
	 * Add an address, ignoring a repeat.
	 *
	 * @param string            $emailHash SHA-256 of the normalised address.
	 * @param SuppressionReason $reason    Why.
	 * @return void
	 */
	public function suppress( string $emailHash, SuppressionReason $reason ): void;

	/**
	 * Remove an address, at the operator's explicit request.
	 *
	 * @param string $emailHash SHA-256 of the normalised address.
	 * @return bool
	 */
	public function release( string $emailHash ): bool;

	/**
	 * How many addresses are suppressed.
	 *
	 * @return int
	 */
	public function countAll(): int;

	/**
	 * A page of the list.
	 *
	 * Returns hashes and reasons. There is no address to return — that is
	 * the point of storing it this way.
	 *
	 * @param Pagination $pagination Page request.
	 * @return array<int, array<string, mixed>>
	 */
	public function paginate( Pagination $pagination ): array;
}
