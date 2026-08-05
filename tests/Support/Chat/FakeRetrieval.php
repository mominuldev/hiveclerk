<?php
/**
 * Retrieval that returns whatever the test set.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Chat;

use Hiveclerk\Domain\Knowledge\RetrievalDiagnostics;
use Hiveclerk\Domain\Knowledge\RetrievalOptions;
use Hiveclerk\Domain\Knowledge\RetrievalResult;
use Hiveclerk\Domain\Knowledge\RetrievalServiceInterface;

/**
 * A retrieval port with no vector store behind it.
 */
final class FakeRetrieval implements RetrievalServiceInterface {

	/**
	 * What retrieve() returns.
	 *
	 * @var RetrievalResult|null
	 */
	public ?RetrievalResult $result = null;

	/**
	 * The last query asked for.
	 *
	 * @var string
	 */
	public string $lastQuery = '';

	public function retrieve( string $query, array $sourceIds, RetrievalOptions $options ): RetrievalResult {
		$this->lastQuery = $query;

		return $this->result ?? new RetrievalResult( array(), new RetrievalDiagnostics() );
	}
}
