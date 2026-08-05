<?php
/**
 * Session repository contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Conversation;

/**
 * Persistence for widget sessions.
 */
interface SessionRepositoryInterface {

	/**
	 * Find by the hash of a presented token.
	 *
	 * Lookup is by hash rather than by uuid because the hash is what the
	 * caller can prove they hold. A uuid lookup would make the session
	 * enumerable by anyone who saw one identifier.
	 *
	 * @param string $tokenHash SHA-256 hex digest.
	 * @return Session|null
	 */
	public function findByTokenHash( string $tokenHash ): ?Session;

	/**
	 * Insert or update.
	 *
	 * @param Session $session Session.
	 * @return Session The saved session, carrying its id.
	 */
	public function save( Session $session ): Session;

	/**
	 * Record which transport the widget settled on.
	 *
	 * @param int    $id        Storage id.
	 * @param string $transport 'sse' or 'poll'.
	 * @return void
	 */
	public function recordTransport( int $id, string $transport ): void;

	/**
	 * Delete sessions that expired before a cut-off.
	 *
	 * @param string $before MySQL DATETIME in UTC.
	 * @param int    $limit  Maximum rows to remove in one pass.
	 * @return int Rows deleted.
	 */
	public function purgeExpired( string $before, int $limit = 500 ): int;
}
