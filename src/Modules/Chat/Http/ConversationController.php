<?php
/**
 * Conversation supervision endpoints.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Chat\Http;

use Hiveclerk\Api\AbstractController;
use Hiveclerk\Api\ErrorCode;
use Hiveclerk\Api\Response\ApiResponse;
use Hiveclerk\Core\Capabilities\Capabilities;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Agent\Agent;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Conversation\Citation;
use Hiveclerk\Domain\Conversation\CitationRepositoryInterface;
use Hiveclerk\Domain\Conversation\Conversation;
use Hiveclerk\Domain\Conversation\ConversationNote;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Domain\Conversation\Message;
use Hiveclerk\Domain\Conversation\MessageRepositoryInterface;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Modules\Chat\Services\HandoffService;
use Hiveclerk\Modules\Chat\Services\RetentionService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The conversations screen (D9 §3.4, FR-CNV-01 through 04, 07).
 *
 * Reading is gated on `view_conversations` and every mutation on
 * `manage_conversations`, which is the split that lets a shop manager
 * supervise without being able to reply as the business — and the reason
 * the two capabilities exist separately at all.
 *
 * The transcript is the product's audit trail. Every assistant message
 * carries the sources it used, what it cost and how long it took, because
 * the question an operator opens this screen with is almost never "what
 * did it say" — it is "why did it say that".
 */
final class ConversationController extends AbstractController {

	/**
	 * Longest tag accepted, and the most a conversation may carry.
	 */
	private const MAX_TAG_LENGTH = 40;
	private const MAX_TAGS       = 12;

	/**
	 * Notes kept per conversation.
	 *
	 * They live in a JSON column read with the row, so the list is
	 * bounded. Fifty notes on one conversation is a thread that should
	 * have become a ticket.
	 */
	private const MAX_NOTES = 50;

	/**
	 * Construct.
	 *
	 * @param ConversationRepositoryInterface $conversations Conversation storage.
	 * @param MessageRepositoryInterface      $messages      Message storage.
	 * @param CitationRepositoryInterface     $citations     Citation storage.
	 * @param AgentRepositoryInterface        $agents        Clerk storage.
	 * @param HandoffService                  $handoff       Handoff, takeover and reply.
	 * @param RetentionService                $retention     Retention policy.
	 * @param ClockInterface                  $clock         Clock.
	 */
	public function __construct(
		private readonly ConversationRepositoryInterface $conversations,
		private readonly MessageRepositoryInterface $messages,
		private readonly CitationRepositoryInterface $citations,
		private readonly AgentRepositoryInterface $agents,
		private readonly HandoffService $handoff,
		private readonly RetentionService $retention,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		$view   = $this->requires( Capabilities::VIEW_CONVERSATIONS );
		$manage = $this->requires( Capabilities::MANAGE_CONVERSATIONS );
		$uuid   = '(?P<uuid>[a-f0-9-]{36})';

		register_rest_route(
			self::NAMESPACE,
			'/admin/conversations',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => $view,
				'args'                => $this->listArgs(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/conversations/retention',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'retention' ),
				'permission_callback' => $view,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/conversations/' . $uuid,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'show' ),
					'permission_callback' => $view,
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
			'/admin/conversations/' . $uuid . '/takeover',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'takeover' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/conversations/' . $uuid . '/reply',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reply' ),
				'permission_callback' => $manage,
				'args'                => array(
					'message' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/conversations/' . $uuid . '/resolve',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'resolve' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/conversations/' . $uuid . '/tags',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'tags' ),
				'permission_callback' => $manage,
				'args'                => array(
					'tags'    => array(
						'type'     => 'array',
						'required' => false,
					),
					'starred' => array(
						'type'     => 'boolean',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/conversations/' . $uuid . '/notes',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'note' ),
				'permission_callback' => $manage,
				'args'                => array(
					'note' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * The conversation list (FR-CNV-01).
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$pagination = $this->pagination( $request );
		$filters    = $this->filters( $request );

		$conversations = $this->conversations->paginate( $pagination, $filters );
		$agents        = $this->agentsFor( $conversations );

		return ApiResponse::collection(
			array_map(
				fn ( Conversation $conversation ): array => $this->summarise( $conversation, $agents ),
				$conversations
			),
			$pagination,
			$this->conversations->count( $filters ),
			array()
		);
	}

	/**
	 * What the retention policy will delete, and when.
	 *
	 * Shown on the screen the deletions happen to. A policy whose effect
	 * is invisible until history disappears is a policy nobody sets
	 * deliberately.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function retention( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$cutoff = $this->retention->cutoff();

		return ApiResponse::ok(
			array(
				'months'  => $this->retention->months(),
				'cutoff'  => $cutoff?->format( 'Y-m-d H:i:s' ),
				'pending' => $this->retention->pending(),
			)
		);
	}

	/**
	 * One conversation, with its transcript (FR-CNV-02).
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$conversation = $this->conversation( $request );

		if ( $conversation instanceof WP_Error ) {
			return $conversation;
		}

		$agent    = $this->agents->find( $conversation->agentId );
		$messages = $this->messages->transcript( (int) $conversation->id );

		$ids = array();

		foreach ( $messages as $message ) {
			if ( null !== $message->id ) {
				$ids[] = $message->id;
			}
		}

		$citations = array() === $ids ? array() : $this->citations->forMessages( $ids );

		return ApiResponse::ok(
			array_merge(
				$this->summarise( $conversation, array( $conversation->agentId => $agent ) ),
				array(
					'page_url'   => $conversation->pageUrl,
					'page_title' => $conversation->pageTitle,
					'language'   => $conversation->language,
					'summary'    => $conversation->summary,
					'notes'      => array_map(
						static fn ( ConversationNote $note ): array => $note->toArray(),
						$conversation->notes
					),
					'messages'   => array_map(
						fn ( Message $message ): array => $this->presentMessage( $message, $citations ),
						$messages
					),
				)
			)
		);
	}

	/**
	 * Take the conversation over (FR-CNV-03).
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function takeover( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$conversation = $this->conversation( $request );

		if ( $conversation instanceof WP_Error ) {
			return $conversation;
		}

		$conversation = $this->handoff->takeover( $conversation, get_current_user_id() );

		return ApiResponse::ok( $this->summarise( $conversation ) );
	}

	/**
	 * Reply as a human.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reply( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$conversation = $this->conversation( $request );

		if ( $conversation instanceof WP_Error ) {
			return $conversation;
		}

		$text = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
		$text = trim( $text );

		if ( '' === $text ) {
			return ApiResponse::error(
				ErrorCode::VALIDATION_FAILED,
				__( 'Write something before sending it.', 'hiveclerk' ),
				422
			);
		}

		$message = $this->handoff->reply(
			$conversation,
			get_current_user_id(),
			mb_substr( $text, 0, 4000 )
		);

		return ApiResponse::ok(
			array(
				'message'      => $this->presentMessage( $message, array() ),
				'conversation' => $this->summarise( $conversation ),
			),
			array(),
			201
		);
	}

	/**
	 * Mark a conversation closed.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function resolve( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$conversation = $this->conversation( $request );

		if ( $conversation instanceof WP_Error ) {
			return $conversation;
		}

		// Never counted as resolved by the clerk: a person pressing this
		// button is the definition of a conversation a human closed. The
		// dashboard's headline number is only worth reading if it cannot
		// be inflated by an operator tidying their queue.
		$conversation = $this->handoff->resolve( $conversation, false );

		return ApiResponse::ok( $this->summarise( $conversation ) );
	}

	/**
	 * Tag and star (FR-CNV-04).
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function tags( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$conversation = $this->conversation( $request );

		if ( $conversation instanceof WP_Error ) {
			return $conversation;
		}

		$tags = $request->get_param( 'tags' );

		if ( is_array( $tags ) ) {
			$conversation->tags = $this->cleanTags( $tags );
		}

		if ( null !== $request->get_param( 'starred' ) ) {
			$conversation->starred = (bool) $request->get_param( 'starred' );
		}

		$this->conversations->save( $conversation );

		return ApiResponse::ok( $this->summarise( $conversation ) );
	}

	/**
	 * Add an internal note (FR-CNV-04).
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function note( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$conversation = $this->conversation( $request );

		if ( $conversation instanceof WP_Error ) {
			return $conversation;
		}

		$text = trim( sanitize_textarea_field( (string) $request->get_param( 'note' ) ) );

		if ( '' === $text ) {
			return ApiResponse::error(
				ErrorCode::VALIDATION_FAILED,
				__( 'A note needs something in it.', 'hiveclerk' ),
				422
			);
		}

		$user = wp_get_current_user();

		$note = new ConversationNote(
			text: mb_substr( $text, 0, ConversationNote::MAX_LENGTH ),
			authorId: $user->ID > 0 ? (int) $user->ID : null,
			authorName: '' !== $user->display_name ? $user->display_name : __( 'Someone', 'hiveclerk' ),
			createdAt: $this->clock->nowSql(),
		);

		$conversation->notes = array_slice(
			array_merge( $conversation->notes, array( $note ) ),
			-self::MAX_NOTES
		);

		$this->conversations->save( $conversation );

		return ApiResponse::ok(
			array(
				'notes' => array_map(
					static fn ( ConversationNote $entry ): array => $entry->toArray(),
					$conversation->notes
				),
			),
			array(),
			201
		);
	}

	/**
	 * Delete a conversation and everything hanging off it.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function destroy( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$conversation = $this->conversation( $request );

		if ( $conversation instanceof WP_Error ) {
			return $conversation;
		}

		$this->conversations->delete( (int) $conversation->id );

		return ApiResponse::ok( array( 'deleted' => true ) );
	}

	/**
	 * Resolve the conversation named in the route.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return Conversation|WP_Error
	 */
	private function conversation( WP_REST_Request $request ): Conversation|WP_Error {
		$uuid = (string) $request->get_param( 'uuid' );

		if ( ! Uuid::isValid( $uuid ) ) {
			return ApiResponse::error(
				ErrorCode::VALIDATION_FAILED,
				__( 'That is not a valid conversation id.', 'hiveclerk' ),
				422
			);
		}

		$conversation = $this->conversations->findByUuid( new Uuid( $uuid ) );

		if ( null === $conversation || null === $conversation->id ) {
			return ApiResponse::error(
				ErrorCode::NOT_FOUND,
				__( 'That conversation does not exist. It may have passed the retention policy.', 'hiveclerk' ),
				404
			);
		}

		return $conversation;
	}

	/**
	 * The filters the list accepts.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return array<string, mixed>
	 */
	private function filters( WP_REST_Request $request ): array {
		$filters = array();

		foreach ( array( 'status', 'sentiment', 'search', 'tag', 'date_from', 'date_to' ) as $key ) {
			$value = $this->stringParam( $request, $key );

			if ( null !== $value ) {
				$filters[ $key ] = $value;
			}
		}

		$agent = $this->stringParam( $request, 'agent' );

		if ( null !== $agent && Uuid::isValid( $agent ) ) {
			// The list filters by the clerk's public identifier, because
			// that is what the roster rail holds. A storage id in a query
			// string is an invitation to enumerate.
			$found = $this->agents->findByUuid( new Uuid( $agent ) );

			// A clerk that does not exist filters to nothing rather than to
			// everything, which is the difference between an empty list and
			// a privacy incident.
			$filters['agent_id'] = null !== $found && null !== $found->id ? $found->id : 0;
		}

		foreach ( array( 'handoff', 'starred', 'has_lead' ) as $flag ) {
			$value = $request->get_param( $flag );

			if ( null !== $value ) {
				$filters[ $flag ] = $this->boolean( $value );
			}
		}

		$rating = $request->get_param( 'rating' );

		if ( is_numeric( $rating ) ) {
			$filters['rating'] = (int) $rating >= 0 ? 1 : -1;
		}

		return $filters;
	}

	/**
	 * A query-string boolean.
	 *
	 * Written out rather than delegated so the false-ish strings a query
	 * string actually carries — "0", "false", "" — mean false. PHP's own
	 * cast disagrees with every one of them.
	 *
	 * @param mixed $value Raw parameter.
	 * @return bool
	 */
	private function boolean( mixed $value ): bool {
		if ( is_string( $value ) ) {
			return ! in_array( strtolower( $value ), array( '', '0', 'false', 'no' ), true );
		}

		return (bool) $value;
	}

	/**
	 * The clerks referenced by a page of conversations, read once each.
	 *
	 * @param array<int, Conversation> $conversations Conversations.
	 * @return array<int, Agent|null>
	 */
	private function agentsFor( array $conversations ): array {
		$agents = array();

		foreach ( $conversations as $conversation ) {
			if ( ! array_key_exists( $conversation->agentId, $agents ) ) {
				$agents[ $conversation->agentId ] = $this->agents->find( $conversation->agentId );
			}
		}

		return $agents;
	}

	/**
	 * Shape a conversation for the list.
	 *
	 * @param Conversation           $conversation The conversation.
	 * @param array<int, Agent|null> $agents       Clerks, keyed by id.
	 * @return array<string, mixed>
	 */
	private function summarise( Conversation $conversation, array $agents = array() ): array {
		$agent = $agents[ $conversation->agentId ] ?? null;

		return array(
			'uuid'            => $conversation->uuid->value,
			'agent'           => array(
				'id'   => $conversation->agentId,
				// A deleted clerk still handled these conversations, and the
				// transcript has to say who without pretending it is still
				// on the roster.
				'name' => null === $agent ? __( 'Retired clerk', 'hiveclerk' ) : $agent->name,
				'uuid' => $agent?->uuid->value,
			),
			'status'          => $conversation->status->value,
			'needs_attention' => $conversation->status->needsAttention(),
			'human_handled'   => $conversation->isHumanHandled(),
			'handoff_at'      => $conversation->handoffAt?->format( 'Y-m-d H:i:s' ),
			'handoff_user'    => $this->userName( $conversation->handoffUserId ),
			'message_count'   => $conversation->messageCount,
			'has_lead'        => $conversation->hasLead(),
			'rating'          => $conversation->rating,
			'sentiment'       => $conversation->sentiment,
			'starred'         => $conversation->starred,
			'tags'            => $conversation->tags,
			'note_count'      => count( $conversation->notes ),
			'preview'         => $this->preview( $conversation ),
			'tokens'          => $conversation->totalTokens(),
			'cost'            => round( $conversation->totalCost, 6 ),
			'started_at'      => $conversation->startedAt?->format( 'Y-m-d H:i:s' ),
			'last_message_at' => $conversation->lastMessageAt?->format( 'Y-m-d H:i:s' ),
			'resolved_by_ai'  => $conversation->resolvedByAi,
		);
	}

	/**
	 * Shape one message, with the sources it used.
	 *
	 * @param Message                          $message   The message.
	 * @param array<int, array<int, Citation>> $citations Citations keyed by message id.
	 * @return array<string, mixed>
	 */
	private function presentMessage( Message $message, array $citations ): array {
		$own = $citations[ (int) $message->id ] ?? array();

		return array(
			'uuid'            => $message->uuid->value,
			'role'            => $message->role->value,
			// Sent as text, never as HTML. The admin renders it as text
			// too; this is model output and visitor input, and the one
			// thing neither may ever become is markup in our own screen.
			'content'         => $message->content,
			'author'          => $this->userName( $message->wpUserId ),
			'created_at'      => $message->createdAt?->format( 'Y-m-d H:i:s' ),
			'tokens_in'       => $message->tokensIn,
			'tokens_out'      => $message->tokensOut,
			'cost'            => round( $message->cost, 6 ),
			'latency_ms'      => $message->latencyMs,
			'retrieval_score' => $message->retrievalScore,
			'grounded'        => $message->isGrounded,
			'rating'          => $message->rating,
			'flags'           => $message->guardrailFlags,
			'model'           => $message->model,
			'provider'        => $message->provider,
			'citations'       => array_map(
				static fn ( Citation $citation ): array => array(
					'chunk_id'     => $citation->chunkId,
					'document_id'  => $citation->documentId,
					'title'        => $citation->title,
					'url'          => $citation->url,
					'heading_path' => $citation->headingPath,
					'excerpt'      => $citation->excerpt,
					'score'        => round( $citation->score, 4 ),
				),
				$own
			),
		);
	}

	/**
	 * The line the list shows under each conversation.
	 *
	 * The summary when one exists, otherwise the page it started on. The
	 * automatic summary is Sprint 9 (FR-CNV-05), so for now this is
	 * usually the page — which is still the most useful thing we know.
	 *
	 * @param Conversation $conversation The conversation.
	 * @return string
	 */
	private function preview( Conversation $conversation ): string {
		if ( null !== $conversation->summary && '' !== trim( $conversation->summary ) ) {
			return mb_substr( trim( $conversation->summary ), 0, 160 );
		}

		if ( null !== $conversation->pageTitle ) {
			return mb_substr( $conversation->pageTitle, 0, 160 );
		}

		return '';
	}

	/**
	 * A display name for a WordPress user id.
	 *
	 * @param int|null $userId User id.
	 * @return string|null
	 */
	private function userName( ?int $userId ): ?string {
		if ( null === $userId || $userId <= 0 ) {
			return null;
		}

		$user = get_userdata( $userId );

		return false === $user ? __( 'A former colleague', 'hiveclerk' ) : $user->display_name;
	}

	/**
	 * Clean a tag list.
	 *
	 * @param array<mixed> $tags Raw tags.
	 * @return array<int, string>
	 */
	private function cleanTags( array $tags ): array {
		$clean = array();

		foreach ( $tags as $tag ) {
			if ( ! is_string( $tag ) ) {
				continue;
			}

			$value = trim( sanitize_text_field( $tag ) );

			if ( '' === $value ) {
				continue;
			}

			$clean[] = mb_substr( $value, 0, self::MAX_TAG_LENGTH );

			if ( count( $clean ) >= self::MAX_TAGS ) {
				break;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Arguments the list accepts.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function listArgs(): array {
		return array_merge(
			$this->collectionArgs(),
			array(
				'agent'     => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'status'    => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_key',
				),
				'sentiment' => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_key',
				),
				'tag'       => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'handoff'   => array(
					'type'     => 'boolean',
					'required' => false,
				),
				'starred'   => array(
					'type'     => 'boolean',
					'required' => false,
				),
				'has_lead'  => array(
					'type'     => 'boolean',
					'required' => false,
				),
				'rating'    => array(
					'type'     => 'integer',
					'required' => false,
				),
				'date_from' => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'date_to'   => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
			)
		);
	}
}
