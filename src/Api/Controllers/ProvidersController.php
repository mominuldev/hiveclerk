<?php
/**
 * Provider settings endpoints.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Api\Controllers;

use Hiveclerk\Ai\AiService;
use Hiveclerk\Ai\Credentials;
use Hiveclerk\Ai\KeyResolver;
use Hiveclerk\Ai\PricingTable;
use Hiveclerk\Ai\ProviderException;
use Hiveclerk\Ai\ProviderId;
use Hiveclerk\Api\AbstractController;
use Hiveclerk\Api\ErrorCode;
use Hiveclerk\Api\Response\ApiResponse;
use Hiveclerk\Core\Audit\AuditLogger;
use Hiveclerk\Core\Capabilities\Capabilities;
use Hiveclerk\Core\Support\RateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Manages model provider credentials.
 *
 * Every route here requires manage_settings, which is the capability a
 * shop manager deliberately does not have: this screen holds the key that
 * spends the site owner's money.
 *
 * No response from this controller contains a key. The read path returns
 * a mask that was computed at write time, so a decrypted key never exists
 * in memory while rendering the settings screen — FR-SYS-03 is a property
 * of the code path, not a promise about remembering to strip a field.
 */
final class ProvidersController extends AbstractController {

	/**
	 * Characters a provider key may contain.
	 *
	 * Printable ASCII without whitespace. Deliberately validated rather
	 * than sanitised: sanitize_text_field() would silently alter a key
	 * containing an unexpected character, and a quietly corrupted key
	 * fails later with an unauthorised error that points at the provider
	 * rather than at us.
	 */
	private const KEY_PATTERN = '/^[\x21-\x7E]+$/';

	/**
	 * Verify calls allowed per minute, per user.
	 *
	 * Each one makes an outbound request on the site's behalf, so this is
	 * as much about not hammering the provider as about our own load.
	 */
	private const VERIFY_LIMIT = 10;

	/**
	 * Construct.
	 *
	 * @param AiService   $ai      Model access.
	 * @param KeyResolver $keys    Credential storage.
	 * @param AuditLogger $audit   Audit log.
	 * @param RateLimiter $limiter Rate limiter.
	 */
	public function __construct(
		private readonly AiService $ai,
		private readonly KeyResolver $keys,
		private readonly AuditLogger $audit,
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
			'/admin/settings/providers',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $this->requires( Capabilities::MANAGE_SETTINGS ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $this->requires( Capabilities::MANAGE_SETTINGS ),
					'args'                => $this->updateArgs(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/settings/providers/(?P<provider>[a-z0-9_-]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'destroy' ),
				'permission_callback' => $this->requires( Capabilities::MANAGE_SETTINGS ),
				'args'                => array( 'provider' => $this->providerArg() ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/settings/providers/(?P<provider>[a-z0-9_-]+)/verify',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'verify' ),
				'permission_callback' => $this->requires( Capabilities::MANAGE_SETTINGS ),
				'args'                => array_merge(
					array( 'provider' => $this->providerArg() ),
					$this->credentialArgs()
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/settings/providers/(?P<provider>[a-z0-9_-]+)/models',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'models' ),
				'permission_callback' => $this->requires( Capabilities::MANAGE_SETTINGS ),
				'args'                => array(
					'provider' => $this->providerArg(),
					'refresh'  => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	/**
	 * Every provider and its configuration state.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$providers = array();

		foreach ( $this->ai->registry()->all() as $id => $provider ) {
			$providers[] = array_merge(
				$this->keys->describe( $id ),
				array(
					'label'         => $provider->label(),
					'capabilities'  => $provider->capabilities()->jsonSerialize(),
					'default_model' => $provider->defaultModel(),
					'console_url'   => self::consoleUrl( $id ),
					'key_hint'      => self::keyHint( $id ),
				)
			);
		}

		return ApiResponse::ok(
			$providers,
			array(
				// Surfaced so the UI can say when the price list was last
				// checked rather than presenting estimates as invoices.
				'pricing_as_of' => PricingTable::AS_OF,
			)
		);
	}

	/**
	 * Store a key, endpoint or model choice.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$provider = (string) $request->get_param( 'provider' );

		if ( ! $this->ai->registry()->has( $provider ) ) {
			return ApiResponse::error( ErrorCode::NOT_FOUND, 'Unknown model provider.', 404 );
		}

		$key = $this->stringParam( $request, 'api_key' );

		if ( null !== $key && 1 !== preg_match( self::KEY_PATTERN, $key ) ) {
			return ApiResponse::error(
				ErrorCode::VALIDATION_FAILED,
				'That key contains characters no provider issues. Check for a stray space or line break.',
				422,
				array( 'api_key' => array( 'Invalid characters.' ) )
			);
		}

		$endpoint = $this->stringParam( $request, 'endpoint' );

		if ( null !== $endpoint && ! self::isHttpsUrl( $endpoint ) ) {
			return ApiResponse::error(
				ErrorCode::VALIDATION_FAILED,
				'The endpoint must be an https:// URL.',
				422,
				array( 'endpoint' => array( 'Must be an https URL.' ) )
			);
		}

		$this->keys->store(
			$provider,
			$key ?? '',
			$endpoint ?? '',
			$this->stringParam( $request, 'api_version' ) ?? ''
		);

		$model = $this->stringParam( $request, 'model' );

		if ( null !== $model ) {
			$this->keys->setModel( $provider, $model );
		}

		// The cached list belonged to whatever key was there before.
		if ( null !== $key ) {
			$this->ai->forgetModels( $provider );
		}

		/*
		 * The key itself is never passed to the audit logger. Only the
		 * fact that one was set. The logger redacts secret-looking fields
		 * as a second line of defence, but the first is not handing it
		 * one.
		 */
		$this->audit->record(
			null !== $key ? AuditLogger::PROVIDER_KEY_SET : AuditLogger::PROVIDER_MODEL_SET,
			array(
				'provider'    => $provider,
				'key_changed' => null !== $key,
				'endpoint'    => $endpoint,
				'model'       => $model,
			),
			'provider'
		);

		return ApiResponse::ok( $this->keys->describe( $provider ) );
	}

	/**
	 * Remove a provider's credentials.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function destroy( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$provider = (string) $request->get_param( 'provider' );

		if ( ! $this->ai->registry()->has( $provider ) ) {
			return ApiResponse::error( ErrorCode::NOT_FOUND, 'Unknown model provider.', 404 );
		}

		$this->keys->forget( $provider );
		$this->ai->forgetModels( $provider );

		$this->audit->record(
			AuditLogger::PROVIDER_KEY_REMOVED,
			array( 'provider' => $provider ),
			'provider'
		);

		return ApiResponse::ok( $this->keys->describe( $provider ) );
	}

	/**
	 * Check a key against the provider.
	 *
	 * Accepts a key in the body so it can be checked before it is stored.
	 * Pasting a wrong key, saving it, and only then discovering it is
	 * wrong means the site spends time with a broken configuration it
	 * believes is good.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function verify( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$provider = (string) $request->get_param( 'provider' );

		if ( ! $this->ai->registry()->has( $provider ) ) {
			return ApiResponse::error( ErrorCode::NOT_FOUND, 'Unknown model provider.', 404 );
		}

		$throttle = $this->throttle(
			$this->limiter,
			'provider_verify:' . get_current_user_id(),
			self::VERIFY_LIMIT
		);

		if ( $throttle instanceof WP_Error ) {
			return $throttle;
		}

		$key      = $this->stringParam( $request, 'api_key' );
		$override = null;

		if ( null !== $key ) {
			$override = new Credentials(
				$key,
				$this->stringParam( $request, 'endpoint' ) ?? '',
				$this->stringParam( $request, 'api_version' ) ?? ''
			);
		} elseif ( ! $this->keys->isConfigured( $provider ) ) {
			return ApiResponse::error(
				ErrorCode::PROVIDER_UNCONFIGURED,
				'Enter an API key first.',
				409
			);
		}

		try {
			$result = $this->ai->verify( $provider, $override );
		} catch ( ProviderException $e ) {
			return ApiResponse::error( ErrorCode::PROVIDER_ERROR, $e->getMessage(), 502 );
		}

		$this->audit->record(
			AuditLogger::PROVIDER_VERIFIED,
			array(
				'provider' => $provider,
				'ok'       => $result->ok,
				'tested'   => null !== $key ? 'submitted key' : 'stored key',
			),
			'provider'
		);

		return ApiResponse::ok(
			array_merge(
				$result->jsonSerialize(),
				array( 'state' => $this->keys->describe( $provider ) )
			),
			array(),
			200,
			$throttle
		);
	}

	/**
	 * Models the stored key can reach.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function models( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$provider = (string) $request->get_param( 'provider' );

		if ( ! $this->ai->registry()->has( $provider ) ) {
			return ApiResponse::error( ErrorCode::NOT_FOUND, 'Unknown model provider.', 404 );
		}

		if ( ! $this->keys->isConfigured( $provider ) ) {
			return ApiResponse::error(
				ErrorCode::PROVIDER_UNCONFIGURED,
				'Add an API key to see which models this account can use.',
				409
			);
		}

		try {
			$models = $this->ai->models( $provider, (bool) $request->get_param( 'refresh' ) );
		} catch ( ProviderException $e ) {
			return ApiResponse::error( ErrorCode::PROVIDER_ERROR, $e->getMessage(), 502 );
		}

		return ApiResponse::ok(
			array_map( static fn ( $model ): array => $model->jsonSerialize(), $models ),
			array( 'pricing_as_of' => PricingTable::AS_OF )
		);
	}

	/**
	 * Route argument for the provider slug.
	 *
	 * @return array<string, mixed>
	 */
	private function providerArg(): array {
		return array(
			'type'              => 'string',
			'required'          => true,
			'sanitize_callback' => 'sanitize_key',
		);
	}

	/**
	 * Arguments accepted when submitting credentials.
	 *
	 * The key has no sanitize_callback on purpose. Sanitising a secret
	 * risks changing it; validate() rejects a malformed one instead.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function credentialArgs(): array {
		return array(
			'api_key'     => array(
				'type'     => 'string',
				'required' => false,
			),
			'endpoint'    => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'esc_url_raw',
			),
			'api_version' => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Arguments accepted on update.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function updateArgs(): array {
		return array_merge(
			array(
				'provider' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
				),
				'model'    => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
			$this->credentialArgs()
		);
	}

	/**
	 * Whether a string is an https URL.
	 *
	 * Plain http is refused rather than warned about: the endpoint
	 * carries an API key on every request, and there is no configuration
	 * in which sending it unencrypted is the right answer.
	 *
	 * @param string $url Candidate.
	 * @return bool
	 */
	private static function isHttpsUrl( string $url ): bool {
		return false !== filter_var( $url, FILTER_VALIDATE_URL )
			&& 'https' === wp_parse_url( $url, PHP_URL_SCHEME );
	}

	/**
	 * Where a first-party provider issues keys.
	 *
	 * @param string $id Provider identifier.
	 * @return string
	 */
	private static function consoleUrl( string $id ): string {
		return ProviderId::tryFrom( $id )?->consoleUrl() ?? '';
	}

	/**
	 * Expected key prefix for a first-party provider.
	 *
	 * @param string $id Provider identifier.
	 * @return string
	 */
	private static function keyHint( string $id ): string {
		return ProviderId::tryFrom( $id )?->keyHint() ?? '';
	}
}
