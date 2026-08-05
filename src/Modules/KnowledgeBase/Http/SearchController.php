<?php
/**
 * Retrieval endpoints.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Http;

use Hiveclerk\Ai\AiService;
use Hiveclerk\Ai\EmbeddingModel;
use Hiveclerk\Ai\Model;
use Hiveclerk\Ai\ProviderException;
use Hiveclerk\Ai\ProviderId;
use Hiveclerk\Api\AbstractController;
use Hiveclerk\Api\ErrorCode;
use Hiveclerk\Api\Response\ApiResponse;
use Hiveclerk\Core\Audit\AuditLogger;
use Hiveclerk\Core\Capabilities\Capabilities;
use Hiveclerk\Core\Settings\SettingsRepository;
use Hiveclerk\Core\Support\RateLimiter;
use Hiveclerk\Domain\Knowledge\EmbeddingRepositoryInterface;
use Hiveclerk\Domain\Knowledge\KnowledgeSource;
use Hiveclerk\Domain\Knowledge\KnowledgeSourceRepositoryInterface;
use Hiveclerk\Domain\Knowledge\RetrievalOptions;
use Hiveclerk\Domain\Knowledge\RetrievedChunk;
use Hiveclerk\Domain\Knowledge\SourceStatus;
use Hiveclerk\Domain\Knowledge\VectorStoreInterface;
use Hiveclerk\Domain\Shared\Pagination;
use Hiveclerk\Modules\KnowledgeBase\Services\EmbeddingService;
use Hiveclerk\Modules\KnowledgeBase\Services\RetrievalService;
use Hiveclerk\Modules\KnowledgeBase\Vector\BinaryQuantiser;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The retrieval playground and the settings behind it (FR-KB-12).
 *
 * This controller exists so retrieval quality is debuggable rather than
 * mystical. When a clerk gives a bad answer the operator's only other
 * recourse is to guess, and the guesses are all wrong in the same
 * direction — they blame the model, which is the one component that was
 * working. Showing which chunks were found, what each signal scored them,
 * and where the time went turns an unfalsifiable complaint into a
 * specific one.
 */
final class SearchController extends AbstractController {

	/**
	 * Searches allowed per minute.
	 *
	 * Every one embeds a query, which is a provider call the customer is
	 * billed for. Generous enough to type in, low enough that a stuck
	 * keypress cannot run up a bill.
	 */
	private const SEARCH_LIMIT = 60;

	/**
	 * Longest query accepted.
	 */
	private const MAX_QUERY_BYTES = 2000;

	/**
	 * Construct.
	 *
	 * @param RetrievalService                   $retrieval  Retrieval.
	 * @param EmbeddingService                   $embeddings Embedding.
	 * @param VectorStoreInterface               $vectors    Vector store.
	 * @param EmbeddingRepositoryInterface       $stored     Vector persistence.
	 * @param KnowledgeSourceRepositoryInterface $sources    Sources.
	 * @param AiService                          $ai         Providers.
	 * @param SettingsRepository                 $settings   Settings.
	 * @param AuditLogger                        $audit      Audit log.
	 * @param RateLimiter                        $limiter    Rate limiter.
	 */
	public function __construct(
		private readonly RetrievalService $retrieval,
		private readonly EmbeddingService $embeddings,
		private readonly VectorStoreInterface $vectors,
		private readonly EmbeddingRepositoryInterface $stored,
		private readonly KnowledgeSourceRepositoryInterface $sources,
		private readonly AiService $ai,
		private readonly SettingsRepository $settings,
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
		$manage   = $this->requires( Capabilities::MANAGE_KNOWLEDGE );
		$settings = $this->requires( Capabilities::MANAGE_SETTINGS );

		register_rest_route(
			self::NAMESPACE,
			'/admin/knowledge/search',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'search' ),
				'permission_callback' => $manage,
				'args'                => array(
					'query'       => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'source_ids'  => array(
						'type'     => 'array',
						'required' => false,
						'items'    => array( 'type' => 'integer' ),
					),
					'top_k'       => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 10,
						'minimum'           => 1,
						'maximum'           => 50,
						'sanitize_callback' => 'absint',
					),
					'threshold'   => array(
						'type'     => 'number',
						'required' => false,
					),
					'use_keyword' => array(
						'type'     => 'boolean',
						'required' => false,
						'default'  => true,
					),
					'fresh'       => array(
						'type'     => 'boolean',
						'required' => false,
						'default'  => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/knowledge/retrieval',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/knowledge/embedding',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'showEmbedding' ),
					'permission_callback' => $manage,
				),
				array(
					// Writing requires manage_settings, not manage_knowledge.
					// Changing the embedding model invalidates every vector
					// on the site and bills a full re-index to the
					// customer's provider account, which is a spending
					// decision rather than a content one.
					'methods'             => 'PUT',
					'callback'            => array( $this, 'saveEmbedding' ),
					'permission_callback' => $settings,
					'args'                => array(
						'provider' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
						'model'    => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Run a search and report how it went.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function search( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$query = (string) $request->get_param( 'query' );

		if ( '' === trim( $query ) ) {
			return ApiResponse::error( ErrorCode::VALIDATION_FAILED, 'Type a question to search for.', 422 );
		}

		if ( strlen( $query ) > self::MAX_QUERY_BYTES ) {
			return ApiResponse::error(
				ErrorCode::VALIDATION_FAILED,
				sprintf( 'That query is longer than the %d character limit.', self::MAX_QUERY_BYTES ),
				422
			);
		}

		$throttled = $this->throttle( $this->limiter, 'knowledge-search', self::SEARCH_LIMIT );

		if ( $throttled instanceof WP_Error ) {
			return $throttled;
		}

		$sourceIds = $this->sourceIds( $request );

		if ( array() === $sourceIds ) {
			return ApiResponse::ok(
				array(
					'results'     => array(),
					'diagnostics' => array(),
					'sources'     => array(),
					'embedding'   => null,
					'message'     => 'There is nothing indexed to search yet.',
				)
			);
		}

		$threshold = $request->get_param( 'threshold' );

		$options = RetrievalOptions::of(
			topK: (int) $request->get_param( 'top_k' ),
			threshold: is_numeric( $threshold ) ? (float) $threshold : null,
			useKeyword: (bool) $request->get_param( 'use_keyword' ),
			// The playground exists to measure. Serving a cached result
			// would report a two-millisecond search that did none of the
			// work being measured.
			useCache: ! (bool) $request->get_param( 'fresh' )
		);

		$result = $this->retrieval->retrieve( $query, $sourceIds, $options );
		$pin    = $this->retrieval->pinFor( $sourceIds );

		return ApiResponse::ok(
			array(
				'results'     => array_map(
					fn ( RetrievedChunk $chunk ): array => $this->present( $chunk, $options->threshold ),
					$result->chunks
				),
				'diagnostics' => $result->diagnostics->toArray(),
				'threshold'   => $options->threshold,
				'best_score'  => round( $result->bestScore(), 4 ),
				'sources'     => $sourceIds,
				'embedding'   => $pin?->jsonSerialize(),
			),
			array(),
			200,
			$throttled
		);
	}

	/**
	 * What retrieval is currently able to do.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function status( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$sources = array();

		foreach ( $this->allSources() as $source ) {
			if ( null === $source->id ) {
				continue;
			}

			$sources[] = array(
				'id'          => $source->id,
				'uuid'        => $source->uuid->value,
				'name'        => $source->name,
				'chunk_count' => $source->chunkCount,
				'vectors'     => $this->stored->countForSource( $source->id ),
				'models'      => $this->stored->modelsForSource( $source->id ),
				'pinned'      => EmbeddingModel::fromStorage(
					$source->embedProvider,
					$source->embedModel,
					$source->embedDimensions
				)?->jsonSerialize(),
				'searchable'  => $source->isUsable() && $this->stored->countForSource( $source->id ) > 0,
			);
		}

		return ApiResponse::ok(
			array(
				'store'   => $this->vectors->describe(),
				'sources' => $sources,
			)
		);
	}

	/**
	 * Which embedding model is configured, and what else is available.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function showEmbedding( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$configured = $this->embeddings->configured();
		$providers  = array();

		foreach ( ProviderId::cases() as $candidate ) {
			if ( ! $candidate->canEmbed() ) {
				continue;
			}

			$ready  = $this->ai->canEmbed( $candidate->value );
			$models = array();

			if ( $ready ) {
				try {
					$models = array_map(
						static fn ( Model $model ): array => array(
							'id'         => $model->id,
							'label'      => $model->label,
							'dimensions' => $model->dimensions,
							'pricing'    => $model->pricing?->jsonSerialize(),
						),
						$this->ai->embeddingModels( $candidate->value )
					);
				} catch ( ProviderException $e ) {
					// A provider that cannot be reached is reported as such
					// rather than omitted. An operator whose Azure resource
					// is down needs to see why the list is empty.
					$models = array();

					$providers[] = array(
						'id'     => $candidate->value,
						'label'  => $candidate->label(),
						'ready'  => false,
						'models' => array(),
						'error'  => $e->getMessage(),
					);

					continue;
				}
			}

			$providers[] = array(
				'id'     => $candidate->value,
				'label'  => $candidate->label(),
				'ready'  => $ready,
				'models' => $models,
				'error'  => null,
			);
		}

		return ApiResponse::ok(
			array(
				'configured'     => $configured?->jsonSerialize(),
				'is_explicit'    => is_string( $this->settings->get( 'retrieval.embed_provider' ) ),
				'providers'      => $providers,
				'max_dimensions' => BinaryQuantiser::MAX_DIMENSIONS,
			)
		);
	}

	/**
	 * Choose the embedding model.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function saveEmbedding( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$provider = (string) $request->get_param( 'provider' );
		$model    = (string) $request->get_param( 'model' );

		if ( ! $this->ai->canEmbed( $provider ) ) {
			return ApiResponse::error(
				ErrorCode::PROVIDER_UNCONFIGURED,
				'That provider cannot produce embeddings, or has no API key stored.',
				409
			);
		}

		if ( '' === trim( $model ) ) {
			return ApiResponse::error( ErrorCode::VALIDATION_FAILED, 'Choose an embedding model.', 422 );
		}

		$before = $this->embeddings->configured();

		$this->settings->set( 'retrieval.embed_provider', $provider );
		$this->settings->set( 'retrieval.embed_model', mb_substr( $model, 0, 64 ) );

		$after = new EmbeddingModel( $provider, $model );

		$affected = $this->markStaleSources( $after );

		$this->audit->record(
			'knowledge.embedding.changed',
			array(
				'from'             => $before?->jsonSerialize(),
				'to'               => $after->jsonSerialize(),
				'sources_affected' => $affected,
			)
		);

		return ApiResponse::ok(
			array(
				'configured'       => $after->jsonSerialize(),
				'sources_affected' => $affected,
				'message'          => $affected > 0
					? sprintf(
						'%d source%s indexed with the previous model and need re-indexing before %s can be searched.',
						$affected,
						1 === $affected ? ' was' : 's were',
						1 === $affected ? 'it' : 'they'
					)
					: null,
			)
		);
	}

	/**
	 * Flag sources whose vectors no longer match the configured model.
	 *
	 * Flagged, not deleted. Deleting them would leave the customer with a
	 * clerk that knows nothing until a re-index they did not ask for
	 * finishes; keeping them means the old vectors stay searchable through
	 * their own pin while the operator decides when to spend the money.
	 *
	 * @param EmbeddingModel $pin Newly configured model.
	 * @return int Sources affected.
	 */
	private function markStaleSources( EmbeddingModel $pin ): int {
		$affected = 0;

		foreach ( $this->allSources() as $source ) {
			if ( null === $source->id || 0 === $source->chunkCount ) {
				continue;
			}

			if ( ! $source->needsReembedding( $pin->provider, $pin->model ) ) {
				continue;
			}

			$source->status = SourceStatus::NeedsReembedding;

			$this->sources->save( $source );

			++$affected;
		}

		return $affected;
	}

	/**
	 * Sources named by the request, or every usable source.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return array<int, int>
	 */
	private function sourceIds( WP_REST_Request $request ): array {
		$requested = $request->get_param( 'source_ids' );

		if ( is_array( $requested ) && array() !== $requested ) {
			$ids = array_values( array_unique( array_filter( array_map( 'intval', $requested ) ) ) );

			// Filtered against what actually exists rather than trusted. The
			// ids come from a browser and reach a WHERE IN clause; a
			// deleted or foreign id must not silently widen the search.
			return array_values( array_intersect( $ids, $this->usableSourceIds() ) );
		}

		return $this->usableSourceIds();
	}

	/**
	 * Ids of every source with content in it.
	 *
	 * @return array<int, int>
	 */
	private function usableSourceIds(): array {
		$ids = array();

		foreach ( $this->allSources() as $source ) {
			if ( null !== $source->id && $source->chunkCount > 0 ) {
				$ids[] = $source->id;
			}
		}

		return $ids;
	}

	/**
	 * Every source on the site.
	 *
	 * @return array<int, KnowledgeSource>
	 */
	private function allSources(): array {
		return $this->sources->paginate( new Pagination( 1, Pagination::MAX_PER_PAGE ) );
	}

	/**
	 * Shape one result for the playground.
	 *
	 * @param RetrievedChunk $chunk     Result.
	 * @param float          $threshold Confidence floor.
	 * @return array<string, mixed>
	 */
	private function present( RetrievedChunk $chunk, float $threshold ): array {
		return array(
			'chunk_id'       => $chunk->chunk->id,
			'document_id'    => $chunk->chunk->documentId,
			'source_id'      => $chunk->chunk->sourceId,
			'document_title' => $chunk->documentTitle,
			'document_url'   => $chunk->documentUrl,
			'heading_path'   => $chunk->chunk->headingPath,
			'excerpt'        => $chunk->excerpt(),
			'token_count'    => $chunk->chunk->tokenCount,
			'rank'           => $chunk->rank,
			'vector_score'   => round( $chunk->vectorScore, 4 ),
			'vector_rank'    => $chunk->vectorRank,
			'bm25_score'     => round( $chunk->bm25Score, 4 ),
			'keyword_rank'   => $chunk->keywordRank,
			'fused_score'    => round( $chunk->fusedScore, 6 ),
			'confident'      => $chunk->isConfident( $threshold ),
		);
	}
}
