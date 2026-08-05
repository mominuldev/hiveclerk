<?php
/**
 * Streaming chat endpoint.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Chat\Http;

use Hiveclerk\Api\Streaming\SseStream;
use Hiveclerk\Core\Container\Container;
use Hiveclerk\Core\Support\RateLimiter;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Modules\Chat\Services\ChatService;
use Hiveclerk\Modules\Chat\Services\SessionService;
use Hiveclerk\Modules\Chat\Streaming\SseSink;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `POST /public/chat/stream` — the fast path.
 *
 * The response is produced through `rest_pre_serve_request` because there
 * is no version of "return a value and let WordPress serialise it" that is
 * also an event stream. Everything after that hook is bytes on a socket.
 *
 * The probe comment goes out inside `SseStream::open()`, before retrieval
 * or any provider call, and that ordering is the whole fallback mechanism:
 * the widget starts a 2,500 ms timer when it sends the request, and if the
 * probe has not arrived it stops waiting and switches to polling for the
 * rest of the session. A probe emitted after the model call would arrive
 * late on a working host and prove nothing about a broken one.
 *
 * @see docs/06-system-architecture.md §5.1
 * @see docs/17-sse-spike.md
 */
final class StreamController extends PublicController {

	/**
	 * Messages allowed per minute, per session and address.
	 *
	 * @see docs/09-api-specification.md §1.6
	 */
	private const CHAT_LIMIT = 20;

	/**
	 * Construct.
	 *
	 * @param SessionService                  $sessions      Session validation.
	 * @param RateLimiter                     $limiter       Rate limiter.
	 * @param ChatService                     $chat          Orchestration.
	 * @param AgentRepositoryInterface        $agents        Clerk storage.
	 * @param ConversationRepositoryInterface $conversations Conversation storage.
	 * @param Container                       $container     For a per-connection stream.
	 */
	public function __construct(
		SessionService $sessions,
		RateLimiter $limiter,
		private readonly ChatService $chat,
		private readonly AgentRepositoryInterface $agents,
		private readonly ConversationRepositoryInterface $conversations,
		private readonly Container $container
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
			'/public/chat/stream',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'stream' ),
				'permission_callback' => $this->requiresSession( self::CHAT_LIMIT ),
				'args'                => array(
					'message' => array(
						'type'              => 'string',
						'required'          => true,
						// Multi-line: a visitor pasting an order confirmation
						// keeps its line breaks, and the single-line version
						// silently flattens them into one wall of text.
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'url'     => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'esc_url_raw',
					),
					'title'   => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Answer, streaming.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function stream( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$session = $this->session;

		if ( null === $session || null === $session->conversationId ) {
			return $this->expired();
		}

		$conversation = $this->conversations->find( $session->conversationId );

		if ( null === $conversation ) {
			return $this->expired();
		}

		$agent = $this->agents->find( $conversation->agentId );

		if ( null === $agent ) {
			return $this->expired();
		}

		$message = (string) $request->get_param( 'message' );
		$context = $this->pageContext( $request );

		$this->sessions->recordTransport( $session, 'sse' );

		add_filter(
			'rest_pre_serve_request',
			function ( bool $served ) use ( $agent, $conversation, $message, $context ): bool {
				unset( $served );

				$stream = $this->container->get( SseStream::class );

				$stream->open();

				$this->chat->reply( $agent, $conversation, $message, new SseSink( $stream ), $context );

				return true;
			}
		);

		return new WP_REST_Response( null, 200 );
	}
}
