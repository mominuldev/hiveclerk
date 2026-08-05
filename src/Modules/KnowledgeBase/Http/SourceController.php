<?php
/**
 * Knowledge source endpoints.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Http;

use Hiveclerk\Api\AbstractController;
use Hiveclerk\Api\ErrorCode;
use Hiveclerk\Api\Response\ApiResponse;
use Hiveclerk\Core\Audit\AuditLogger;
use Hiveclerk\Core\Capabilities\Capabilities;
use Hiveclerk\Core\Queue\QueueInterface;
use Hiveclerk\Core\Support\RateLimiter;
use Hiveclerk\Domain\Knowledge\ChunkRepositoryInterface;
use Hiveclerk\Domain\Knowledge\Chunk;
use Hiveclerk\Domain\Knowledge\Document;
use Hiveclerk\Domain\Knowledge\DocumentRepositoryInterface;
use Hiveclerk\Domain\Knowledge\KnowledgeSource;
use Hiveclerk\Domain\Knowledge\KnowledgeSourceRepositoryInterface;
use Hiveclerk\Domain\Knowledge\SourceStatus;
use Hiveclerk\Domain\Knowledge\SourceType;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Modules\KnowledgeBase\Extractors\ExtractorRegistry;
use Hiveclerk\Modules\KnowledgeBase\Extractors\FaqExtractor;
use Hiveclerk\Modules\KnowledgeBase\Jobs\IngestSourceJob;
use Hiveclerk\Modules\KnowledgeBase\Services\IngestionProgress;
use Hiveclerk\Modules\KnowledgeBase\Services\IngestionService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Managing what a clerk knows.
 *
 * Indexing is never done in the request. Every route that would start
 * work enqueues a job and returns immediately, so a crawl of a large
 * site cannot time out the admin screen or hold a PHP worker for
 * minutes. The screen then polls this controller for progress, which is
 * why the list response carries live counters rather than only what was
 * true when the job finished.
 */
final class SourceController extends AbstractController {

	/**
	 * Index requests allowed per minute.
	 *
	 * Each one can cost thousands of HTTP requests to a third party, so
	 * the limit is low and deliberate.
	 */
	private const INDEX_LIMIT = 10;

	/**
	 * Largest CSV accepted for FAQ import, in bytes.
	 */
	private const MAX_CSV_BYTES = 2097152;

	/**
	 * Construct.
	 *
	 * @param KnowledgeSourceRepositoryInterface $sources    Sources.
	 * @param DocumentRepositoryInterface        $documents  Documents.
	 * @param ChunkRepositoryInterface           $chunks     Chunks.
	 * @param IngestionService                   $ingestion  Ingestion.
	 * @param ExtractorRegistry                  $extractors Extractors.
	 * @param QueueInterface                     $queue      Background queue.
	 * @param AuditLogger                        $audit      Audit log.
	 * @param RateLimiter                        $limiter    Rate limiter.
	 */
	public function __construct(
		private readonly KnowledgeSourceRepositoryInterface $sources,
		private readonly DocumentRepositoryInterface $documents,
		private readonly ChunkRepositoryInterface $chunks,
		private readonly IngestionService $ingestion,
		private readonly ExtractorRegistry $extractors,
		private readonly QueueInterface $queue,
		private readonly AuditLogger $audit,
		private readonly RateLimiter $limiter,
	) {
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		$manage = $this->requires( Capabilities::MANAGE_KNOWLEDGE );

		register_rest_route(
			self::NAMESPACE,
			'/admin/knowledge/sources',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $manage,
					'args'                => $this->collectionArgs(),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $manage,
					'args'                => $this->writeArgs(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/knowledge/types',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'types' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/knowledge/sources/(?P<uuid>[a-f0-9-]{36})',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'show' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $manage,
					'args'                => $this->writeArgs(),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'destroy' ),
					'permission_callback' => $manage,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/knowledge/sources/(?P<uuid>[a-f0-9-]{36})/index',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reindex' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/knowledge/sources/(?P<uuid>[a-f0-9-]{36})/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/knowledge/sources/(?P<uuid>[a-f0-9-]{36})/documents',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'documents' ),
				'permission_callback' => $manage,
				'args'                => $this->collectionArgs(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/knowledge/documents/(?P<id>\d+)/chunks',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'chunks' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/knowledge/faq/parse',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'parseFaqCsv' ),
				'permission_callback' => $manage,
				'args'                => array(
					'csv' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * List sources.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$pagination = $this->pagination( $request );

		return ApiResponse::collection(
			array_map(
				fn ( KnowledgeSource $source ): array => $this->present( $source ),
				$this->sources->paginate( $pagination )
			),
			$pagination,
			$this->sources->count()
		);
	}

	/**
	 * Which source types can be used here.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function types( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$availability = $this->extractors->availability();
		$types        = array();

		foreach ( SourceType::cases() as $type ) {
			$reason = $availability[ $type->value ] ?? 'This source type is not installed.';

			$types[] = array(
				'value'              => $type->value,
				'label'              => $type->label(),
				'available'          => '' === $reason,
				'unavailable_reason' => $reason,
				'supports_schedule'  => $type->supportsScheduledSync(),
			);
		}

		return ApiResponse::ok( array( 'types' => $types ) );
	}

	/**
	 * Show one source.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$source = $this->resolve( $request );

		if ( $source instanceof WP_Error ) {
			return $source;
		}

		return ApiResponse::ok( $this->present( $source, true ) );
	}

	/**
	 * Create a source and start indexing it.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$type = SourceType::tryFrom( (string) $request->get_param( 'type' ) );

		if ( null === $type ) {
			return ApiResponse::error(
				ErrorCode::VALIDATION_FAILED,
				'That is not a source type this installation knows about.',
				422
			);
		}

		$extractor = $this->extractors->for( $type );

		if ( null === $extractor || ! $extractor->isAvailable() ) {
			// Refused at creation rather than at index time. A source that
			// can only ever fail is worse than no source: the customer
			// configures it, waits, and then gets an error.
			return ApiResponse::error(
				ErrorCode::VALIDATION_FAILED,
				null === $extractor
					? 'That source type has no extractor registered.'
					: $extractor->unavailableReason(),
				422
			);
		}

		$source = new KnowledgeSource(
			id: null,
			uuid: Uuid::generate(),
			name: $this->name( $request, $type ),
			type: $type,
			status: SourceStatus::Pending,
			config: $this->config( $request ),
			syncSchedule: $this->schedule( $request ),
		);

		$source = $this->sources->save( $source );

		$this->audit->record(
			'knowledge.source.created',
			array(
				'type' => $type->value,
				'name' => $source->name,
			),
			'knowledge_source',
			$source->id
		);

		$this->enqueue( $source );

		return ApiResponse::ok( $this->present( $source ), array(), 201 );
	}

	/**
	 * Update a source.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$source = $this->resolve( $request );

		if ( $source instanceof WP_Error ) {
			return $source;
		}

		$before = $source->config;

		if ( null !== $request->get_param( 'name' ) ) {
			$source->name = $this->name( $request, $source->type );
		}

		if ( null !== $request->get_param( 'config' ) ) {
			$source->config = $this->config( $request );
		}

		if ( null !== $request->get_param( 'sync_schedule' ) ) {
			$source->syncSchedule = $this->schedule( $request );
		}

		$this->sources->save( $source );

		$this->audit->record(
			'knowledge.source.updated',
			array(
				'name'           => $source->name,
				'config_changed' => $before !== $source->config,
			),
			'knowledge_source',
			$source->id
		);

		// Configuration changes what gets indexed — a different post type,
		// a narrower crawl — so the index has to be rebuilt to match. Not
		// doing so leaves the source describing settings it does not obey.
		if ( $before !== $source->config ) {
			$this->enqueue( $source );
		}

		return ApiResponse::ok( $this->present( $source ) );
	}

	/**
	 * Delete a source and everything it indexed.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function destroy( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$source = $this->resolve( $request );

		if ( $source instanceof WP_Error ) {
			return $source;
		}

		$id = (int) $source->id;

		// Stop first. Deleting a source whose job is mid-run would let the
		// job carry on writing documents against a row that no longer
		// exists.
		$this->ingestion->cancel( $id );
		$this->queue->cancel( IngestSourceJob::hook(), array( 'source_id' => $id ) );

		$this->documents->deleteForSource( $id );
		$this->sources->delete( $id );

		$this->audit->record(
			'knowledge.source.deleted',
			array(
				'name' => $source->name,
				'type' => $source->type->value,
			),
			'knowledge_source',
			$id
		);

		return ApiResponse::noContent();
	}

	/**
	 * Queue a re-index.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reindex( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$source = $this->resolve( $request );

		if ( $source instanceof WP_Error ) {
			return $source;
		}

		$throttled = $this->throttle( $this->limiter, 'knowledge-index', self::INDEX_LIMIT );

		if ( $throttled instanceof WP_Error ) {
			return $throttled;
		}

		$this->audit->record(
			'knowledge.source.reindexed',
			array( 'name' => $source->name ),
			'knowledge_source',
			$source->id
		);

		$this->enqueue( $source );

		return ApiResponse::ok( $this->present( $source ) );
	}

	/**
	 * Ask a running index to stop.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$source = $this->resolve( $request );

		if ( $source instanceof WP_Error ) {
			return $source;
		}

		$id = (int) $source->id;

		$this->ingestion->cancel( $id );
		$this->queue->cancel( IngestSourceJob::hook(), array( 'source_id' => $id ) );

		$this->audit->record(
			'knowledge.source.cancelled',
			array( 'name' => $source->name ),
			'knowledge_source',
			$id
		);

		return ApiResponse::ok( $this->present( $source ) );
	}

	/**
	 * List a source's documents.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function documents( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$source = $this->resolve( $request );

		if ( $source instanceof WP_Error ) {
			return $source;
		}

		$id         = (int) $source->id;
		$pagination = $this->pagination( $request );

		return ApiResponse::collection(
			array_map(
				static fn ( Document $document ): array => array(
					'id'          => $document->id,
					'title'       => $document->title,
					'url'         => $document->url,
					'chunk_count' => $document->chunkCount,
					'token_count' => $document->tokenCount,
					'status'      => $document->status,
					'metadata'    => $document->metadata,
				),
				$this->documents->forSource( $id, $pagination )
			),
			$pagination,
			$this->documents->countForSource( $id )
		);
	}

	/**
	 * Show a document's chunks.
	 *
	 * The screen that answers "why did it not find this". Seeing the
	 * actual boundaries is usually enough to explain a bad answer, and
	 * there is no other way to see them.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function chunks( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$documentId = (int) $request->get_param( 'id' );
		$document   = $this->documents->find( $documentId );

		if ( null === $document ) {
			return ApiResponse::error( ErrorCode::NOT_FOUND, 'That document does not exist.', 404 );
		}

		return ApiResponse::ok(
			array(
				'document' => array(
					'id'    => $document->id,
					'title' => $document->title,
					'url'   => $document->url,
				),
				'chunks'   => array_map(
					static fn ( Chunk $chunk ): array => array(
						'id'           => $chunk->id,
						'index'        => $chunk->chunkIndex,
						'content'      => $chunk->content,
						'heading_path' => $chunk->headingPath,
						'token_count'  => $chunk->tokenCount,
						'char_start'   => $chunk->charStart,
						'char_end'     => $chunk->charEnd,
					),
					$this->chunks->forDocument( $documentId )
				),
			)
		);
	}

	/**
	 * Parse an uploaded FAQ CSV without saving it.
	 *
	 * Parsed before storage so the customer sees what was understood, and
	 * how many rows were not, while they can still fix the file.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function parseFaqCsv( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$csv = (string) $request->get_param( 'csv' );

		if ( strlen( $csv ) > self::MAX_CSV_BYTES ) {
			return ApiResponse::error(
				ErrorCode::VALIDATION_FAILED,
				sprintf( 'That file is larger than the %d MB limit.', (int) ( self::MAX_CSV_BYTES / 1048576 ) ),
				422
			);
		}

		$result = FaqExtractor::parseCsv( $csv );

		return ApiResponse::ok(
			array(
				'pairs'   => $result['pairs'],
				'skipped' => $result['skipped'],
			)
		);
	}

	/**
	 * Find the source a request names.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return KnowledgeSource|WP_Error
	 */
	private function resolve( WP_REST_Request $request ): KnowledgeSource|WP_Error {
		$uuid = (string) $request->get_param( 'uuid' );

		if ( ! Uuid::isValid( $uuid ) ) {
			return ApiResponse::error( ErrorCode::VALIDATION_FAILED, 'That is not a valid source id.', 422 );
		}

		$source = $this->sources->findByUuid( new Uuid( $uuid ) );

		if ( null === $source || null === $source->id ) {
			return ApiResponse::error( ErrorCode::NOT_FOUND, 'That knowledge source does not exist.', 404 );
		}

		return $source;
	}

	/**
	 * Queue an index run.
	 *
	 * @param KnowledgeSource $source Source.
	 * @return void
	 */
	private function enqueue( KnowledgeSource $source ): void {
		$args = array( 'source_id' => (int) $source->id );

		// Idempotent by design. A customer clicking "re-index" four times
		// while nothing appears to happen should get one crawl, not four
		// running at once against the same rows.
		if ( $this->queue->isPending( IngestSourceJob::hook(), $args ) ) {
			return;
		}

		$source->status   = SourceStatus::Pending;
		$source->progress = ( new IngestionProgress( stage: 'queued' ) )->toArray();

		$this->sources->save( $source );

		$this->queue->enqueue( IngestSourceJob::hook(), $args );
	}

	/**
	 * Shape a source for the browser.
	 *
	 * @param KnowledgeSource $source     Source.
	 * @param bool            $withConfig Whether to include configuration.
	 * @return array<string, mixed>
	 */
	private function present( KnowledgeSource $source, bool $withConfig = false ): array {
		$data = array(
			'uuid'           => $source->uuid->value,
			'name'           => $source->name,
			'type'           => $source->type->value,
			'type_label'     => $source->type->label(),
			'status'         => $source->status->value,
			'is_busy'        => $source->status->isBusy(),
			'needs_action'   => $source->status->needsAttention(),
			'document_count' => $source->documentCount,
			'chunk_count'    => $source->chunkCount,
			'token_count'    => $source->tokenCount,
			'sync_schedule'  => $source->syncSchedule,
			'last_synced_at' => $source->lastSyncedAt,
			'last_error'     => $source->lastError,
			'progress'       => $source->progress,
		);

		if ( $withConfig ) {
			$data['config'] = $source->config;
		}

		return $data;
	}

	/**
	 * Read and clean the name.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @param SourceType                            $type    Source type.
	 * @return string
	 */
	private function name( WP_REST_Request $request, SourceType $type ): string {
		$name = $this->stringParam( $request, 'name' );

		if ( null === $name ) {
			return $type->label();
		}

		return mb_substr( $name, 0, 191 );
	}

	/**
	 * Read the sync schedule, refusing anything unrecognised.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return string
	 */
	private function schedule( WP_REST_Request $request ): string {
		$schedule = (string) $request->get_param( 'sync_schedule' );

		return in_array( $schedule, array( 'manual', 'on_save', 'daily', 'weekly' ), true )
			? $schedule
			: 'manual';
	}

	/**
	 * Read the type-specific configuration.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return array<string, mixed>
	 */
	private function config( WP_REST_Request $request ): array {
		$config = $request->get_param( 'config' );

		if ( ! is_array( $config ) ) {
			return array();
		}

		// Recursively cleaned rather than trusted. This lands in a JSON
		// column and is read back by extractors that build queries and
		// HTTP requests from it, so it is input in every sense that
		// matters however administrative the screen looks.
		return $this->clean( $config );
	}

	/**
	 * Sanitise nested configuration.
	 *
	 * @param array<mixed> $value Raw value.
	 * @return array<string, mixed>
	 */
	private function clean( array $value ): array {
		$clean = array();

		foreach ( $value as $key => $item ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $item ) ) {
				$clean[ $key ] = $this->clean( $item );

				continue;
			}

			if ( is_bool( $item ) || is_int( $item ) || is_float( $item ) ) {
				$clean[ $key ] = $item;

				continue;
			}

			// sanitize_textarea_field rather than sanitize_text_field: raw
			// text sources and FAQ answers are multi-line, and the
			// single-line version silently flattens them.
			$clean[ $key ] = sanitize_textarea_field( (string) $item );
		}

		return $clean;
	}

	/**
	 * Arguments accepted when writing a source.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function writeArgs(): array {
		return array(
			'name'          => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'type'          => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_key',
			),
			'sync_schedule' => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_key',
			),
			'config'        => array(
				'type'     => 'object',
				'required' => false,
			),
		);
	}
}
