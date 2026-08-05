<?php
/**
 * Streaming diagnostics.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Api\Controllers;

use Hiveclerk\Api\AbstractController;
use Hiveclerk\Api\Response\ApiResponse;
use Hiveclerk\Api\Streaming\SseStream;
use Hiveclerk\Api\Streaming\StreamEnvironment;
use Hiveclerk\Core\Capabilities\Capabilities;
use Hiveclerk\Core\Support\RateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Answers "will streaming work on this host, and does it".
 *
 * Two endpoints, because there are two different questions. The
 * environment endpoint reports what PHP can see about itself, which is
 * cheap and often enough. The probe actually holds a connection open and
 * emits frames on a known cadence, which is the only way to find out
 * what everything between PHP and the browser does to them — a proxy's
 * buffer is not visible from the process it is buffering.
 *
 * Both are gated on the settings capability. Neither reveals anything a
 * site administrator could not read from phpinfo, but a stream that any
 * visitor could open is a request that any visitor could hold open, and
 * a few hundred of those exhaust the PHP worker pool. That is the reason
 * for the capability check and for the rate limit, in that order.
 */
final class StreamController extends AbstractController {

	/**
	 * Probe runs allowed per minute, per operator.
	 *
	 * Low, because each one occupies a PHP worker for its whole duration.
	 */
	private const PROBE_LIMIT = 6;

	/**
	 * Frames the probe sends unless asked otherwise.
	 */
	private const DEFAULT_FRAMES = 12;

	/**
	 * Milliseconds between frames unless asked otherwise.
	 *
	 * Comfortably longer than a network jitter and comfortably shorter
	 * than any buffer's flush timer, so the two cases separate cleanly
	 * in the measurement.
	 */
	private const DEFAULT_GAP_MS = 250;

	/**
	 * Construct.
	 *
	 * @param SseStream         $stream      Transport.
	 * @param StreamEnvironment $environment Host inspector.
	 * @param RateLimiter       $limiter     Rate limiter.
	 */
	public function __construct(
		private readonly SseStream $stream,
		private readonly StreamEnvironment $environment,
		private readonly RateLimiter $limiter
	) {
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/system/stream/environment',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'environment' ),
				'permission_callback' => $this->requires( Capabilities::MANAGE_SETTINGS ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/system/stream/probe',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'probe' ),
				'permission_callback' => $this->requires( Capabilities::MANAGE_SETTINGS ),
				'args'                => array(
					'frames' => array(
						'type'              => 'integer',
						'default'           => self::DEFAULT_FRAMES,
						'minimum'           => 2,
						'maximum'           => 60,
						'sanitize_callback' => 'absint',
					),
					'gap'    => array(
						'type'              => 'integer',
						'default'           => self::DEFAULT_GAP_MS,
						'minimum'           => 50,
						'maximum'           => 2000,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Report what the host does to responses.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function environment( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return ApiResponse::ok( $this->environment->summary() );
	}

	/**
	 * Hold a connection open and emit timed frames.
	 *
	 * The response is produced through rest_pre_serve_request rather than
	 * by returning a body. WordPress would otherwise JSON-encode whatever
	 * we returned and send it with its own headers, and there is no
	 * version of that which is also an event stream. Returning a response
	 * object keeps the callback testable: the decision to stream and the
	 * streaming itself are separate methods.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function probe( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$throttled = $this->throttle( $this->limiter, 'stream-probe', self::PROBE_LIMIT );

		if ( $throttled instanceof WP_Error ) {
			return $throttled;
		}

		$frames = max( 2, min( 60, (int) $request->get_param( 'frames' ) ) );
		$gap    = max( 50, min( 2000, (int) $request->get_param( 'gap' ) ) );

		add_filter(
			'rest_pre_serve_request',
			function ( bool $served ) use ( $frames, $gap ): bool {
				unset( $served );

				$this->emit( $frames, $gap );

				return true;
			}
		);

		return new WP_REST_Response( null, 200 );
	}

	/**
	 * Emit the probe stream.
	 *
	 * Each frame carries the server's own elapsed milliseconds. The
	 * client records when each one arrived. Comparing the two series is
	 * the whole measurement: on a working host they track each other, and
	 * on a buffering host the server series climbs while every arrival
	 * lands in the same instant at the end.
	 *
	 * @param int $frames How many frames.
	 * @param int $gap    Milliseconds between them.
	 * @return void
	 */
	public function emit( int $frames, int $gap ): void {
		$this->stream->open();

		$started = microtime( true );
		$sent    = 0;

		for ( $seq = 1; $seq <= $frames; $seq++ ) {
			$alive = $this->stream->send(
				'tick',
				array(
					'seq'       => $seq,
					'of'        => $frames,
					'server_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
				)
			);

			++$sent;

			if ( ! $alive ) {
				// The operator closed the tab. Nothing here is billable,
				// but stopping is still right: the worker is needed.
				break;
			}

			if ( $seq < $frames ) {
				usleep( $gap * 1000 );
			}
		}

		$this->stream->send(
			'done',
			array(
				'frames_sent'     => $sent,
				'gap_ms'          => $gap,
				'server_total_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
				'discarded_bytes' => $this->stream->discardedBytes(),
				'environment'     => $this->environment->summary(),
			)
		);
	}
}
