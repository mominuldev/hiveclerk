<?php
/**
 * Visitor-facing lead capture and telemetry.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Leads\Http;

use Hiveclerk\Api\Response\ApiResponse;
use Hiveclerk\Core\Support\RateLimiter;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Modules\Chat\Http\PublicController;
use Hiveclerk\Modules\Chat\Services\SessionService;
use Hiveclerk\Modules\Chat\Services\WidgetConfig;
use Hiveclerk\Modules\Leads\Services\LeadCaptureService;
use Hiveclerk\Modules\Leads\Services\VisitorService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `POST /public/leads` and `POST /public/events` (D9 §2.5).
 *
 * ## Two different gates, for two different risks
 *
 * Capture is session-gated: it writes a named person into the customer's
 * database, so the caller has to be holding a token this site issued for
 * a conversation this site opened. Five a minute, because a visitor
 * submits that form once.
 *
 * Telemetry cannot be session-gated — it fires on page load, before
 * anybody has opened the chat. Its gate is a per-IP ceiling plus a
 * whitelisted event vocabulary, and it writes nothing at all on a page
 * where no clerk is serving. A site with the widget switched off does not
 * accumulate visitor rows.
 */
final class CaptureController extends PublicController {

	/**
	 * Capture submissions allowed per minute, per session and address.
	 */
	private const LEAD_LIMIT = 5;

	/**
	 * Telemetry events allowed per minute, per address.
	 *
	 * Generous enough for a person reading a catalogue in several tabs,
	 * tight enough that it is not a way to fill the activities table.
	 */
	private const EVENT_LIMIT = 40;

	/**
	 * Construct.
	 *
	 * @param SessionService                  $sessions      Session validation.
	 * @param RateLimiter                     $limiter       Rate limiter.
	 * @param LeadCaptureService              $capture       Lead capture.
	 * @param VisitorService                  $visitors      Visitor identification.
	 * @param WidgetConfig                    $config        Clerk selection.
	 * @param ConversationRepositoryInterface $conversations Conversation storage.
	 * @param AgentRepositoryInterface        $agents        Clerk storage.
	 */
	public function __construct(
		SessionService $sessions,
		RateLimiter $limiter,
		private readonly LeadCaptureService $capture,
		private readonly VisitorService $visitors,
		private readonly WidgetConfig $config,
		private readonly ConversationRepositoryInterface $conversations,
		private readonly AgentRepositoryInterface $agents
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
			'/public/leads',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'capture' ),
				'permission_callback' => $this->requiresSession( self::LEAD_LIMIT ),
				'args'                => array(
					'email'      => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_email',
					),
					'first_name' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'last_name'  => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'phone'      => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'company'    => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'answers'    => array(
						'type'     => 'object',
						'required' => false,
					),
					'consent'    => array(
						'type'     => 'boolean',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/public/events',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'event' ),
				'permission_callback' => $this->throttledPublic( 'events', self::EVENT_LIMIT ),
				'args'                => array(
					'type'     => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
						// Enumerated at the route as well as in the service.
						// This is an unauthenticated write, and a rejected
						// event should cost a validation error rather than a
						// database round trip.
						//
						// The validate_callback is not decoration: WordPress
						// ignores `enum` entirely unless one is present, so
						// without this line the whitelist below is a comment.
						'enum'              => VisitorService::EVENTS,
						'validate_callback' => 'rest_validate_request_arg',
					),
					'visitor'  => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'url'      => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'esc_url_raw',
					),
					'title'    => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'language' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * A visitor filled in the in-chat capture form (FR-LED-01).
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function capture( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( null === $this->session || null === $this->session->conversationId ) {
			return $this->expired();
		}

		$conversation = $this->conversations->find( $this->session->conversationId );

		if ( null === $conversation ) {
			return $this->expired();
		}

		$agent = $this->agents->find( $conversation->agentId );

		if ( null === $agent ) {
			return $this->expired();
		}

		$lead = $this->capture->captureFromForm( $conversation, $agent, $this->fields( $request ) );

		// The visitor is told it worked, never what it produced. A score, a
		// stage or a lead id echoed back to the browser is the customer's
		// commercial assessment of the person reading it.
		return ApiResponse::ok( array( 'captured' => null !== $lead ) );
	}

	/**
	 * A page view or another whitelisted signal (FR-LED-07).
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function event( WP_REST_Request $request ): WP_REST_Response {
		// No clerk on this page means no reason to know anybody was on it.
		// A site with the widget switched off does not quietly accumulate a
		// visitor table.
		if ( null === $this->config->select( null ) ) {
			return ApiResponse::noContent();
		}

		$visitor = $this->visitors->resolve(
			$this->safeText( $request->get_param( 'visitor' ), 36 ),
			array( 'language' => $this->safeText( $request->get_param( 'language' ), 10 ) )
		);

		$this->visitors->record(
			$visitor,
			(string) $request->get_param( 'type' ),
			array(
				'url'   => $this->safeUrl( $request->get_param( 'url' ) ),
				'title' => $this->safeText( $request->get_param( 'title' ), 191 ),
			)
		);

		return ApiResponse::ok( array( 'visitor' => $visitor->uuid->value ) );
	}

	/**
	 * Read the capture form's fields.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return array<string, mixed>
	 */
	private function fields( WP_REST_Request $request ): array {
		$fields = array(
			'consent' => (bool) $request->get_param( 'consent' ),
		);

		foreach ( array( 'email', 'first_name', 'last_name', 'phone', 'company' ) as $key ) {
			$value = $this->safeText( $request->get_param( $key ), 191 );

			if ( null !== $value ) {
				$fields[ $key ] = $value;
			}
		}

		$answers = $request->get_param( 'answers' );

		if ( is_array( $answers ) ) {
			$clean = array();

			foreach ( $answers as $key => $value ) {
				$key = sanitize_key( (string) $key );

				if ( '' === $key || ! is_scalar( $value ) ) {
					continue;
				}

				// Multi-line on purpose: a "tell us about your project"
				// answer is a paragraph, and the single-line sanitiser
				// silently flattens it into one.
				$clean[ $key ] = mb_substr( sanitize_textarea_field( (string) $value ), 0, 500 );
			}

			$fields['answers'] = $clean;
		}

		return $fields;
	}
}
