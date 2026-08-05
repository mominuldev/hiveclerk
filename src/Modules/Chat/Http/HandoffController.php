<?php
/**
 * Asking for a person.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Chat\Http;

use Hiveclerk\Api\Response\ApiResponse;
use Hiveclerk\Core\Support\RateLimiter;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Modules\Chat\Services\HandoffService;
use Hiveclerk\Modules\Chat\Services\SessionService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `POST /public/chat/handoff` (FR-WGT-07).
 *
 * The limit is low and the operation is idempotent, for the same reason:
 * this route sends an email to the site owner on the strength of an
 * anonymous request. Repeating it must cost the owner nothing, and a
 * script hammering it must not turn our handoff feature into a mail
 * cannon aimed at our own customer.
 *
 * Which conversation is being handed off is read from the session, never
 * from a parameter — the same reasoning as the history route (SEC-11).
 */
final class HandoffController extends PublicController {

	/**
	 * Handoff requests allowed per minute, per session and address.
	 *
	 * Three, because a visitor who presses the button twice is anxious,
	 * and one who presses it thirty times is not a visitor.
	 */
	private const HANDOFF_LIMIT = 3;

	/**
	 * Construct.
	 *
	 * @param SessionService                  $sessions      Session validation.
	 * @param RateLimiter                     $limiter       Rate limiter.
	 * @param HandoffService                  $handoff       Handoff.
	 * @param AgentRepositoryInterface        $agents        Clerk storage.
	 * @param ConversationRepositoryInterface $conversations Conversation storage.
	 */
	public function __construct(
		SessionService $sessions,
		RateLimiter $limiter,
		private readonly HandoffService $handoff,
		private readonly AgentRepositoryInterface $agents,
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
			'/public/chat/handoff',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'request' ),
				'permission_callback' => $this->requiresSession( self::HANDOFF_LIMIT ),
				'args'                => array(
					'reason' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);
	}

	/**
	 * Flag the conversation and tell somebody.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
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

		$reason = $this->stringParam( $request, 'reason' );

		$conversation = $this->handoff->request(
			$conversation,
			$agent,
			null === $reason ? null : mb_substr( $reason, 0, 500 )
		);

		return ApiResponse::ok(
			array(
				'status'         => $conversation->status->value,
				'awaiting_human' => true,
				// The visitor-facing acknowledgement was written into the
				// transcript, so the widget renders it by reloading history
				// rather than by being handed a second copy of it here.
				'message'        => __( 'Passed to a colleague. They will reply in this chat.', 'hiveclerk' ),
			)
		);
	}
}
