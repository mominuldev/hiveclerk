<?php
/**
 * Transcript restore and visitor feedback.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Chat\Http;

use Hiveclerk\Api\ErrorCode;
use Hiveclerk\Api\Response\ApiResponse;
use Hiveclerk\Core\Support\RateLimiter;
use Hiveclerk\Domain\Conversation\Citation;
use Hiveclerk\Domain\Conversation\CitationRepositoryInterface;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Domain\Conversation\Message;
use Hiveclerk\Domain\Conversation\MessageRepositoryInterface;
use Hiveclerk\Domain\Conversation\MessageRole;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Modules\Chat\Services\SessionService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Reading a conversation back, and saying whether it helped.
 *
 * Neither route takes a conversation identifier. The specification shows
 * `GET /public/chat/history?conversation={uuid}`, and accepting that
 * parameter is the exact shape of SEC-11: a visitor who changes one uuid
 * reads somebody else's transcript. The conversation is read from the
 * session token instead, so there is no parameter to tamper with — and
 * for feedback, the message being rated is checked to belong to that same
 * conversation before anything is written.
 */
final class HistoryController extends PublicController {

	/**
	 * History reads allowed per minute.
	 */
	private const HISTORY_LIMIT = 30;

	/**
	 * Ratings allowed per minute.
	 */
	private const FEEDBACK_LIMIT = 20;

	/**
	 * Turns returned when restoring a transcript.
	 *
	 * The widget is a panel a few hundred pixels tall. Returning a
	 * thousand-turn conversation to render into it costs a large response
	 * to display the last six.
	 */
	private const HISTORY_LIMIT_TURNS = 50;

	/**
	 * Construct.
	 *
	 * @param SessionService              $sessions  Session validation.
	 * @param RateLimiter                 $limiter   Rate limiter.
	 * @param MessageRepositoryInterface  $messages  Message storage.
	 * @param CitationRepositoryInterface     $citations     Citation storage.
	 * @param ConversationRepositoryInterface $conversations Conversation storage.
	 */
	public function __construct(
		SessionService $sessions,
		RateLimiter $limiter,
		private readonly MessageRepositoryInterface $messages,
		private readonly CitationRepositoryInterface $citations,
		private readonly ConversationRepositoryInterface $conversations
	) {
		parent::__construct( $sessions, $limiter );
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/public/chat/history',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'history' ),
				'permission_callback' => $this->requiresSession( self::HISTORY_LIMIT ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/public/chat/feedback',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'feedback' ),
				'permission_callback' => $this->requiresSession( self::FEEDBACK_LIMIT ),
				'args'                => array(
					'message' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'rating'  => array(
						'type'     => 'integer',
						'required' => true,
						'enum'     => array( -1, 1 ),
					),
					'comment' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);
	}

	/**
	 * The transcript this session may read.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function history( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		unset( $request );

		$session = $this->session;

		if ( null === $session || null === $session->conversationId ) {
			return $this->expired();
		}

		$messages = $this->messages->recent( $session->conversationId, self::HISTORY_LIMIT_TURNS );
		$ids      = array();

		foreach ( $messages as $message ) {
			if ( null !== $message->id ) {
				$ids[] = $message->id;
			}
		}

		$citations = $this->citations->forMessages( $ids );

		$payload = array();

		foreach ( $messages as $message ) {
			if ( ! $message->role->isVisible() ) {
				continue;
			}

			$payload[] = array(
				'id'         => $message->uuid->value,
				'role'       => MessageRole::Visitor === $message->role ? 'visitor' : 'clerk',
				// A reply from a person is still shown in the clerk's lane —
				// it is the same conversation — but the visitor is told a
				// human wrote it. Passing it off as the clerk would be the
				// product lying about who they are talking to.
				'from_human' => MessageRole::HumanAgent === $message->role,
				'text'       => $message->content,
				'created_at' => null === $message->createdAt ? null : $message->createdAt->format( DATE_ATOM ),
				'rating'     => $message->rating,
				'citations'  => $this->citationPayloads( $citations[ (int) $message->id ] ?? array() ),
			);
		}

		$conversation = $this->conversations->find( $session->conversationId );

		return ApiResponse::ok(
			array(
				'messages'       => $payload,
				'status'         => null === $conversation ? 'active' : $conversation->status->value,
				'awaiting_human' => null !== $conversation && ! $conversation->acceptsAiReplies(),
			)
		);
	}

	/**
	 * Record a thumbs up or down.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function feedback( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$session = $this->session;

		if ( null === $session || null === $session->conversationId ) {
			return $this->expired();
		}

		$uuid = $request->get_param( 'message' );

		if ( ! is_string( $uuid ) || ! Uuid::isValid( $uuid ) ) {
			return ApiResponse::error( ErrorCode::NOT_FOUND, 'No such message.', 404 );
		}

		$message = $this->messages->findByUuid( new Uuid( $uuid ) );

		// Ownership, not just existence. Without this check a visitor could
		// rate any message on the site by guessing a uuid — which is hard,
		// and "hard to guess" is not an authorisation model.
		if ( null === $message || $message->conversationId !== $session->conversationId ) {
			return ApiResponse::error( ErrorCode::NOT_FOUND, 'No such message.', 404 );
		}

		$rating  = (int) $request->get_param( 'rating' );
		$comment = $this->safeText( $request->get_param( 'comment' ), 500 );

		$this->messages->rate( $message->uuid, $rating >= 0 ? 1 : -1, $comment );

		/**
		 * Fires when a visitor rates a reply.
		 *
		 * @param Message $message The rated message.
		 * @param int     $rating  1 or -1.
		 */
		do_action( 'hiveclerk/chat/rated', $message, $rating >= 0 ? 1 : -1 );

		return ApiResponse::ok( array( 'rated' => true ) );
	}

	/**
	 * Citations in the shape the widget reads.
	 *
	 * @param array<int, Citation> $citations Citations.
	 * @return array<int, array<string, mixed>>
	 */
	private function citationPayloads( array $citations ): array {
		return array_map(
			static fn ( Citation $citation ): array => array(
				'title'        => $citation->title,
				'url'          => $citation->url,
				'heading_path' => $citation->headingPath,
				'excerpt'      => $citation->excerpt,
				'score'        => round( $citation->score, 4 ),
			),
			$citations
		);
	}
}
