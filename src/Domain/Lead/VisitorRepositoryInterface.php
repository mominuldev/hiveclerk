<?php
/**
 * Visitor repository contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Lead;

use Hiveclerk\Domain\Shared\Uuid;

/**
 * Persistence for anonymous visitors.
 */
interface VisitorRepositoryInterface {

	/**
	 * Find by storage id.
	 *
	 * @param int $id Storage id.
	 * @return Visitor|null
	 */
	public function find( int $id ): ?Visitor;

	/**
	 * Find by public identifier.
	 *
	 * @param Uuid $uuid Public identifier.
	 * @return Visitor|null
	 */
	public function findByUuid( Uuid $uuid ): ?Visitor;

	/**
	 * Insert or update.
	 *
	 * @param Visitor $visitor Visitor.
	 * @return Visitor
	 */
	public function save( Visitor $visitor ): Visitor;

	/**
	 * Every visitor stitched to a lead (FR-LED-07).
	 *
	 * @param int $leadId Lead storage id.
	 * @return array<int, Visitor>
	 */
	public function forLead( int $leadId ): array;

	/**
	 * Point a set of visitors at a lead.
	 *
	 * @param array<int, int> $ids    Visitor storage ids.
	 * @param int             $leadId Lead storage id.
	 * @return int Rows updated.
	 */
	public function attachToLead( array $ids, int $leadId ): int;

	/**
	 * Move every visitor from one lead onto another.
	 *
	 * @param int $from Lead being merged away.
	 * @param int $to   Surviving lead.
	 * @return int Rows moved.
	 */
	public function reassign( int $from, int $to ): int;

	/**
	 * Detach a lead's visitors, leaving the visitors themselves in place.
	 *
	 * A deleted lead must not take the site's traffic record with it,
	 * and a visitor row holds nothing identifying to begin with.
	 *
	 * @param int $leadId Lead storage id.
	 * @return int Rows updated.
	 */
	public function detachLead( int $leadId ): int;

	/**
	 * Delete a lead's visitors outright, and their sessions with them.
	 *
	 * The counterpart to `detachLead()`, and the difference between them
	 * is the difference between deleting a lead and erasing a person.
	 * Detaching is right when an operator removes a record from their CRM:
	 * the site keeps its traffic history and loses only the link. It is
	 * wrong when the person themselves has asked to be erased, because a
	 * visitor row carries a browser fingerprint and a hashed IP, and a
	 * fingerprint that survives is what re-identifies the same person on
	 * their next visit.
	 *
	 * @param int $leadId Lead storage id.
	 * @return int Visitors deleted.
	 */
	public function deleteForLead( int $leadId ): int;
}
