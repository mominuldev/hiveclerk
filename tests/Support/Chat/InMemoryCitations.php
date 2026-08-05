<?php
/**
 * Citation storage without a database.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Chat;

use Hiveclerk\Domain\Conversation\Citation;
use Hiveclerk\Domain\Conversation\CitationRepositoryInterface;

/**
 * Citation storage without a database.
 */
final class InMemoryCitations implements CitationRepositoryInterface {

	/**
	 * Citations saved, keyed by message id.
	 *
	 * @var array<int, array<int, Citation>>
	 */
	public array $saved = array();

	public function saveFor( int $messageId, array $citations ): void {
		$this->saved[ $messageId ] = $citations;
	}

	public function forMessages( array $messageIds ): array {
		return $this->saved;
	}
}
