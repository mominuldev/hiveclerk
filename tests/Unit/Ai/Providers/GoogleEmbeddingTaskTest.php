<?php
/**
 * Google embedding task-type tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Ai\Providers;

use Hiveclerk\Ai\Credentials;
use Hiveclerk\Ai\EmbeddingTask;
use Hiveclerk\Ai\Http\HttpResponse;
use Hiveclerk\Ai\PricingTable;
use Hiveclerk\Ai\Providers\GoogleProvider;
use Hiveclerk\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Gemini's embeddings are asymmetric: a question and the passage that
 * answers it are placed by different task types, and retrieval quality
 * depends on each side being told which it is. These tests pin the wire
 * format, because the failure mode is silent — a query embedded as a
 * document returns vectors, similarities and confident rankings, all of
 * them measurably worse and none of them an error.
 */
final class GoogleEmbeddingTaskTest extends TestCase {

	public function testChunksAreEmbeddedAsDocuments(): void {
		$http = self::respondingClient();

		$provider = new GoogleProvider( $http, new PricingTable() );
		$provider->embed( new Credentials( 'key' ), array( 'a chunk' ), 'gemini-embedding-001' );

		$this->assertSame( 'RETRIEVAL_DOCUMENT', self::taskTypeOf( $http->lastBody() ) );
	}

	public function testQueriesAreEmbeddedAsQueries(): void {
		$http = self::respondingClient();

		$provider = new GoogleProvider( $http, new PricingTable() );
		$provider->embed(
			new Credentials( 'key' ),
			array( 'where is my parcel' ),
			'gemini-embedding-001',
			60,
			EmbeddingTask::Query
		);

		$this->assertSame( 'RETRIEVAL_QUERY', self::taskTypeOf( $http->lastBody() ) );
	}

	public function testEveryRequestInABatchCarriesTheTask(): void {
		$http = self::respondingClient( 3 );

		$provider = new GoogleProvider( $http, new PricingTable() );
		$provider->embed(
			new Credentials( 'key' ),
			array( 'one', 'two', 'three' ),
			'gemini-embedding-001',
			60,
			EmbeddingTask::Query
		);

		foreach ( (array) ( $http->lastBody()['requests'] ?? array() ) as $request ) {
			$this->assertSame( 'RETRIEVAL_QUERY', $request['taskType'] ?? null );
		}
	}

	/**
	 * A client scripted with a well-formed batchEmbedContents response.
	 *
	 * @param int $count Vectors to return.
	 * @return FakeHttpClient
	 */
	private static function respondingClient( int $count = 1 ): FakeHttpClient {
		$embeddings = array_fill( 0, $count, array( 'values' => array( 0.1, 0.2, 0.3 ) ) );

		return new FakeHttpClient(
			new HttpResponse( 200, (string) json_encode( array( 'embeddings' => $embeddings ) ) )
		);
	}

	/**
	 * The task type of the first request in the last call's batch.
	 *
	 * @param array<string, mixed> $body Request body.
	 * @return string|null
	 */
	private static function taskTypeOf( array $body ): ?string {
		$requests = $body['requests'] ?? null;

		if ( ! is_array( $requests ) || ! is_array( $requests[0] ?? null ) ) {
			return null;
		}

		return is_string( $requests[0]['taskType'] ?? null ) ? $requests[0]['taskType'] : null;
	}
}
