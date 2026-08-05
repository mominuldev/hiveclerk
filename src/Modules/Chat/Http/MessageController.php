<?php
/**
 * Polling chat endpoints.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Chat\Http;

use Hiveclerk\Api\ErrorCode;
use Hiveclerk\Api\Response\ApiResponse;
use Hiveclerk\Core\Support\RateLimiter;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Modules\Chat\Services\ChatService;
use Hiveclerk\Modules\Chat\Services\SessionService;
use Hiveclerk\Modules\Chat\Streaming\BufferSink;
use Hiveclerk\Modules\Chat\Streaming\StreamBuffer;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The transport for hosts that buffer, which is most shared hosting.
 *
 * ## The reference is minted by the widget, not by the server
 *
 * The specification sketches this as `POST /chat/message → 202
 * {message_id}` followed by `GET /chat/poll?message={id}`. Implemented
 * literally, it cannot work on the hosts it exists for. The 202 can only
 * reach the browser once the response is flushed, and a host that buffers
 * the stream buffers that too — so the poller would be waiting for an
 * identifier that arrives at the same moment as the finished answer, which
 * is precisely the failure polling was added to avoid.
 *
 * So the widget generates the reference and sends it. Polling can begin
 * immediately, in parallel with the POST that is still generating. The
 * obvious worry with a client-supplied identifier is that a caller
 * addresses somebody else's data; that is closed by construction rather
 * than by validation — the buffer key is derived from the session *and*
 * the reference, so a caller can only ever name buffers inside the session
 * they already hold a token for.
 *
 * `fastcgi_finish_request()` is used where it exists, which returns the
 * 202 immediately and lets generation continue. It is an optimisation, not
 * the mechanism: the design above works without it.
 *
 * @see docs/06-system-architecture.md §5.1
 */
final class MessageController extends PublicController {

	/**
	 * Messages allowed per minute, per session and address.
	 */
	private const CHAT_LIMIT = 20;

	/**
	 * Poll requests allowed per minute, per session and address.
	 *
	 * The widget polls about four times a second while a reply is
	 * generating, so this is high by design — and it is still a ceiling,
	 * which is the point. Each poll is a cache read, not a provider call.
	 */
	private const POLL_LIMIT = 240;

	/**
	 * Construct.
	 *
	 * @param SessionService                  $sessions      Session validation.
	 * @param RateLimiter                     $limiter       Rate limiter.
	 * @param ChatService                     $chat          Orchestration.
	 * @param AgentRepositoryInterface        $agents        Clerk storage.
	 * @param ConversationRepositoryInterface $conversations Conversation storage.
	 * @param StreamBuffer                    $buffer        Partial-reply store.
	 */
	public function __construct(
		SessionService $sessions,
		RateLimiter $limiter,
		private readonly ChatService $chat,
		private readonly AgentRepositoryInterface $agents,
		private readonly ConversationRepositoryInterface $conversations,
		private readonly StreamBuffer $buffer
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
			'/public/chat/message',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'send' ),
				'permission_callback' => $this->requiresSession( self::CHAT_LIMIT ),
				'args'                => array(
					'message'   => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'reference' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'url'       => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'esc_url_raw',
					),
					'title'     => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/public/chat/poll',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'poll' ),
				'permission_callback' => $this->requiresSession( self::POLL_LIMIT ),
				'args'                => array(
					'message' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'cursor'  => array(
						'type'              => 'integer',
						'default'           => 0,
						'minimum'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Accept a message and generate the reply into a buffer.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function send( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$session = $this->session;

		if ( null === $session || null === $session->conversationId ) {
			return $this->expired();
		}

		$reference = $this->reference( $request );

		if ( null === $reference ) {
			return ApiResponse::error(
				ErrorCode::VALIDATION_FAILED,
				'The request could not be validated.',
				422,
				array( 'reference' => array( 'A reference must be a version 4 UUID.' ) )
			);
		}

		$conversation = $this->conversations->find( $session->conversationId );

		if ( null === $conversation ) {
			return $this->expired();
		}

		$agent = $this->agents->find( $conversation->agentId );

		if ( null === $agent ) {
			return $this->expired();
		}

		$key     = StreamBuffer::key( $session->uuid->value, $reference );
		$message = (string) $request->get_param( 'message' );
		$context = $this->pageContext( $request );

		$this->sessions->recordTransport( $session, 'poll' );
		$this->buffer->open( $key );

		// The reply is generated after the response is delivered. Tokens
		// already produced are already billed, so the generation must not be
		// killed by the visitor closing the tab before the usage row is
		// written — the same reasoning as the streaming path.
		ignore_user_abort( true );

		add_action(
			'shutdown',
			function () use ( $agent, $conversation, $message, $context, $key ): void {
				$this->chat->reply(
					$agent,
					$conversation,
					$message,
					new BufferSink( $this->buffer, $key ),
					$context
				);
			}
		);

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			// Flushes the 202 and frees the connection while this process
			// keeps generating. Where it does not exist the client is
			// already polling and does not need the response to proceed.
			add_filter( 'rest_post_dispatch', array( $this, 'finishRequest' ), 999, 1 );
		}

		return ApiResponse::ok( array( 'reference' => $reference ), array(), 202 );
	}

	/**
	 * Read what has been generated so far.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function poll( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$session = $this->session;

		if ( null === $session ) {
			return $this->expired();
		}

		$reference = $this->reference( $request, 'message' );

		if ( null === $reference ) {
			return ApiResponse::error( ErrorCode::NOT_FOUND, 'No such reply.', 404 );
		}

		$state = $this->buffer->read( StreamBuffer::key( $session->uuid->value, $reference ) );

		if ( null === $state ) {
			// Not an error: the POST may not have opened the buffer yet, and
			// the poller is expected to be early. Reporting 404 would make
			// the widget give up on a reply that is about to exist.
			return ApiResponse::ok(
				array(
					'text'     => '',
					'cursor'   => 0,
					'complete' => false,
					'pending'  => true,
				)
			);
		}

		$text   = is_string( $state['text'] ?? null ) ? $state['text'] : '';
		$cursor = (int) $request->get_param( 'cursor' );
		$total  = mb_strlen( $text );

		// A cursor past the end means the reply was replaced — a guardrail
		// rejected it after part of it had been read. Rewinding to zero is
		// what makes the widget redraw rather than append.
		$slice = $cursor > $total ? $text : mb_substr( $text, $cursor );

		return ApiResponse::ok(
			array(
				'text'       => $slice,
				'cursor'     => $total,
				'replaced'   => $cursor > $total,
				'complete'   => (bool) ( $state['complete'] ?? false ),
				'citations'  => is_array( $state['citations'] ?? null ) ? $state['citations'] : array(),
				'message_id' => is_string( $state['message_id'] ?? null ) ? $state['message_id'] : null,
				'error'      => is_array( $state['error'] ?? null ) ? $state['error'] : null,
			)
		);
	}

	/**
	 * Send the response and keep the process alive to finish generating.
	 *
	 * @param WP_REST_Response $response Response about to be served.
	 * @return WP_REST_Response
	 */
	public function finishRequest( WP_REST_Response $response ): WP_REST_Response {
		add_action(
			'shutdown',
			static function (): void {
				if ( function_exists( 'fastcgi_finish_request' ) ) {
					fastcgi_finish_request();
				}
			},
			-1
		);

		return $response;
	}

	/**
	 * The generation reference a request carries, validated as a UUID.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @param string                                $key     Parameter name.
	 * @return string|null
	 */
	private function reference( WP_REST_Request $request, string $key = 'reference' ): ?string {
		$value = $request->get_param( $key );

		if ( ! is_string( $value ) || ! Uuid::isValid( $value ) ) {
			return null;
		}

		return $value;
	}
}
