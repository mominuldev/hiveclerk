<?php
/**
 * Knowledge-gap endpoints.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Analytics\Http;

use Hiveclerk\Api\AbstractController;
use Hiveclerk\Api\ErrorCode;
use Hiveclerk\Api\Response\ApiResponse;
use Hiveclerk\Core\Capabilities\Capabilities;
use Hiveclerk\Domain\Agent\Agent;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Analytics\GapRepositoryInterface;
use Hiveclerk\Domain\Analytics\GapStatus;
use Hiveclerk\Domain\Analytics\KnowledgeGap;
use Hiveclerk\Domain\Shared\Pagination;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Modules\Analytics\Services\GapService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The knowledge-gaps worklist (FR-ANL-03, D11 §7.3).
 *
 * Gated on `manage_knowledge` rather than `view_conversations`: reading
 * the list is close to reading transcripts, but every action on it writes
 * to the knowledge base, and a capability that let somebody answer a
 * question on the site's behalf without letting them edit knowledge would
 * be a gap in the capability model rather than a convenience.
 */
final class GapsController extends AbstractController {

	/**
	 * Longest answer the composer accepts.
	 *
	 * Generous, because a real answer to "what is your returns policy" is
	 * a couple of paragraphs. Bounded, because the field lands in a JSON
	 * column that is read back by the indexer.
	 */
	private const MAX_ANSWER = 5000;

	/**
	 * Construct.
	 *
	 * @param GapRepositoryInterface   $gaps    Gap storage.
	 * @param GapService               $service Gap actions.
	 * @param AgentRepositoryInterface $agents  Clerk storage.
	 */
	public function __construct(
		private readonly GapRepositoryInterface $gaps,
		private readonly GapService $service,
		private readonly AgentRepositoryInterface $agents
	) {
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		$capability = $this->requires( Capabilities::MANAGE_KNOWLEDGE );

		register_rest_route(
			self::NAMESPACE,
			'/admin/knowledge/gaps',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => $capability,
				'args'                => array_merge(
					$this->collectionArgs(),
					array(
						'status' => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => 'open',
							'enum'              => array( 'open', 'resolved', 'ignored', 'all' ),
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'agent'  => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
					)
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/knowledge/gaps/(?P<id>\d+)/answer',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'answer' ),
				'permission_callback' => $capability,
				'args'                => array(
					'id'     => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'answer' => array(
						'type'              => 'string',
						'required'          => true,
						// The multi-line version: an FAQ answer with
						// paragraphs would be silently flattened into one
						// line by sanitize_text_field, and the operator
						// would find out from the indexed result.
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'source' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/knowledge/gaps/(?P<id>\d+)/status',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => $capability,
				'args'                => array(
					'id'     => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'status' => array(
						'type'              => 'string',
						'required'          => true,
						'enum'              => array( 'open', 'resolved', 'ignored' ),
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);
	}

	/**
	 * A page of gaps, most asked first.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$pagination = $this->pagination( $request );
		$raw        = (string) ( $request->get_param( 'status' ) ?? 'open' );
		$status     = 'all' === $raw ? null : GapStatus::tryFrom( $raw );

		$agentId = $this->agentId( $request );

		if ( $agentId instanceof WP_Error ) {
			return $agentId;
		}

		$gaps   = $this->gaps->paginate( $status, $agentId, $pagination );
		$agents = $this->agentNames( $gaps );
		$total  = $this->gaps->count( $status, $agentId );

		return ApiResponse::ok(
			array_map(
				fn ( KnowledgeGap $gap ): array => $this->present( $gap, $agents ),
				$gaps
			),
			array(
				'pagination' => array(
					'page'        => $pagination->page,
					'per_page'    => $pagination->perPage,
					'total'       => $total,
					'total_pages' => $pagination->totalPages( $total ),
				),
				// The tab counts travel with the page, so the three tabs
				// do not need three requests to know what to say.
				'counts'     => array(
					'open'     => $this->gaps->count( GapStatus::Open, $agentId ),
					'resolved' => $this->gaps->count( GapStatus::Resolved, $agentId ),
					'ignored'  => $this->gaps->count( GapStatus::Ignored, $agentId ),
				),
			)
		);
	}

	/**
	 * Write an answer, index it, and close the gap.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function answer( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$answer = trim( (string) $request->get_param( 'answer' ) );

		if ( '' === $answer ) {
			return ApiResponse::error(
				ErrorCode::VALIDATION_FAILED,
				'An answer needs some words in it.',
				422
			);
		}

		if ( strlen( $answer ) > self::MAX_ANSWER ) {
			return ApiResponse::error(
				ErrorCode::VALIDATION_FAILED,
				sprintf( 'An answer is limited to %d characters.', self::MAX_ANSWER ),
				422
			);
		}

		$source = $this->stringParam( $request, 'source' );

		if ( null !== $source && ! Uuid::isValid( $source ) ) {
			return ApiResponse::error( ErrorCode::VALIDATION_FAILED, 'That is not a valid source id.', 422 );
		}

		$result = $this->service->answer(
			(int) $request->get_param( 'id' ),
			$answer,
			$source,
			self::currentUserId()
		);

		if ( null === $result ) {
			return ApiResponse::error(
				ErrorCode::NOT_FOUND,
				'That question is no longer in the list, or the source it would go to is not an FAQ.',
				404
			);
		}

		return ApiResponse::ok(
			array(
				'gap'      => $this->present( $result['gap'], $this->agentNames( array( $result['gap'] ) ) ),
				'source'   => array(
					'uuid' => $result['source']->uuid->value,
					'name' => $result['source']->name,
				),
				// Stated rather than implied. The answer is saved; it is
				// not searchable until the indexer has run, and a screen
				// that says "answered" without saying that is a screen
				// that gets a support ticket.
				'indexing' => true,
			)
		);
	}

	/**
	 * Ignore a gap, or put it back.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$status = GapStatus::tryFrom( (string) $request->get_param( 'status' ) );

		if ( null === $status ) {
			return ApiResponse::error( ErrorCode::VALIDATION_FAILED, 'Unknown status.', 422 );
		}

		$gap = $this->service->setStatus(
			(int) $request->get_param( 'id' ),
			$status,
			self::currentUserId()
		);

		if ( null === $gap ) {
			return ApiResponse::error( ErrorCode::NOT_FOUND, 'That question is not in the list.', 404 );
		}

		return ApiResponse::ok( $this->present( $gap, $this->agentNames( array( $gap ) ) ) );
	}

	/**
	 * The acting user, or null when there is not one.
	 *
	 * `get_current_user_id()` answers 0 for no user, and 0 stored in
	 * `resolved_by` reads as a real account nobody can look up.
	 *
	 * @return int|null
	 */
	private static function currentUserId(): ?int {
		$id = get_current_user_id();

		return $id > 0 ? $id : null;
	}

	/**
	 * Clerk names for a set of gaps, in one query.
	 *
	 * @param array<int, KnowledgeGap> $gaps Gaps.
	 * @return array<int, array{name: string, uuid: string, threshold: float}>
	 */
	private function agentNames( array $gaps ): array {
		$names = array();

		foreach ( $gaps as $gap ) {
			if ( isset( $names[ $gap->agentId ] ) ) {
				continue;
			}

			$agent = $this->agents->find( $gap->agentId );

			if ( ! $agent instanceof Agent ) {
				continue;
			}

			$names[ $gap->agentId ] = array(
				'name'      => $agent->name,
				'uuid'      => $agent->uuid->value,
				'threshold' => $agent->confidenceThreshold(),
			);
		}

		return $names;
	}

	/**
	 * Wire form for one gap.
	 *
	 * The clerk's threshold travels with the row because the screen's
	 * second line — "Best match scored 0.21, well below Rafi's 0.62
	 * threshold" — is the sentence that tells an operator whether to write
	 * new content or fix an existing page, and it is meaningless without
	 * both numbers.
	 *
	 * @param KnowledgeGap                                                    $gap    Gap.
	 * @param array<int, array{name: string, uuid: string, threshold: float}> $agents Clerk lookup.
	 * @return array<string, mixed>
	 */
	private function present( KnowledgeGap $gap, array $agents ): array {
		$agent = $agents[ $gap->agentId ] ?? null;

		return array(
			'id'              => $gap->id,
			'question'        => $gap->query,
			'occurrences'     => $gap->occurrences,
			'best_score'      => $gap->bestScore,
			'found_nothing'   => $gap->foundNothing(),
			'status'          => $gap->status->value,
			'status_label'    => $gap->status->label(),
			'agent'           => $agent,
			'conversation_id' => $gap->conversationId,
			'first_seen_at'   => $gap->firstSeenAt?->format( 'c' ),
			'last_seen_at'    => $gap->lastSeenAt?->format( 'c' ),
		);
	}

	/**
	 * The clerk filter, resolved from a uuid.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return int|null|WP_Error
	 */
	private function agentId( WP_REST_Request $request ): int|null|WP_Error {
		$uuid = $this->stringParam( $request, 'agent' );

		if ( null === $uuid || 'all' === $uuid ) {
			return null;
		}

		if ( ! Uuid::isValid( $uuid ) ) {
			return ApiResponse::error( ErrorCode::VALIDATION_FAILED, 'That is not a valid clerk id.', 422 );
		}

		$agent = $this->agents->findByUuid( new Uuid( $uuid ) );

		if ( null === $agent || null === $agent->id ) {
			return ApiResponse::error( ErrorCode::NOT_FOUND, 'That clerk does not exist.', 404 );
		}

		return $agent->id;
	}
}
