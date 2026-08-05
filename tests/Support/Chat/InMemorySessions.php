<?php
/**
 * Session storage without a database.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Chat;

use Hiveclerk\Domain\Conversation\Session;
use Hiveclerk\Domain\Conversation\SessionRepositoryInterface;

/**
 * Session storage without a database.
 */
final class InMemorySessions implements SessionRepositoryInterface {

	/**
	 * Sessions by token hash.
	 *
	 * @var array<string, Session>
	 */
	public array $byHash = array();

	/**
	 * Next id to hand out.
	 *
	 * @var int
	 */
	private int $next = 1;

	public function findByTokenHash( string $tokenHash ): ?Session {
		return $this->byHash[ $tokenHash ] ?? null;
	}

	public function save( Session $session ): Session {
		$session->id = $session->id ?? $this->next++;

		$this->byHash[ $session->tokenHash ] = $session;

		return $session;
	}

	public function recordTransport( int $id, string $transport ): void {
		foreach ( $this->byHash as $session ) {
			if ( $session->id === $id ) {
				$session->transport = $transport;
			}
		}
	}

	public function purgeExpired( string $before, int $limit = 500 ): int {
		return 0;
	}
}
