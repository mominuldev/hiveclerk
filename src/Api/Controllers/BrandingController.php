<?php
/**
 * Branding endpoints.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Api\Controllers;

use Hiveclerk\Api\AbstractController;
use Hiveclerk\Api\Response\ApiResponse;
use Hiveclerk\Core\Audit\AuditLogger;
use Hiveclerk\Core\Branding\BrandingService;
use Hiveclerk\Core\Capabilities\Capabilities;
use Hiveclerk\Core\Licence\Feature;
use Hiveclerk\Core\Licence\LicenceGate;
use WP_REST_Request;
use WP_REST_Response;

/**
 * White-label settings (FR-SYS-08).
 *
 * Saving is never refused on tier. The preferences are stored whatever
 * the licence says and the response reports which of them are actually in
 * force — see {@see BrandingService} for why a refusal would cost an
 * upgrading agency their whole configuration.
 *
 * The consequence is that this endpoint has no 402, which is deliberate
 * and worth stating: nothing here is a paid *action*. What is paid for is
 * the effect, and the effect is computed on read.
 */
final class BrandingController extends AbstractController {

	/**
	 * Construct.
	 *
	 * @param BrandingService $branding Branding.
	 * @param LicenceGate     $gate     Entitlements.
	 * @param AuditLogger     $audit    Audit trail.
	 */
	public function __construct(
		private readonly BrandingService $branding,
		private readonly LicenceGate $gate,
		private readonly AuditLogger $audit
	) {
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		$capability = $this->requires( Capabilities::MANAGE_SETTINGS );

		register_rest_route(
			self::NAMESPACE,
			'/admin/settings/branding',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'show' ),
					'permission_callback' => $capability,
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $capability,
					'args'                => $this->fields(),
				),
			)
		);
	}

	/**
	 * The saved preferences and what is in force.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function show( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return ApiResponse::ok( $this->present() );
	}

	/**
	 * Replace the preferences.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response {
		$before = $this->branding->stored();
		$input  = array();

		foreach ( array_keys( $this->fields() ) as $field ) {
			if ( null !== $request->get_param( $field ) ) {
				$input[ $field ] = $request->get_param( $field );
			}
		}

		$after = $this->branding->save( $input );

		$this->audit->record(
			'settings.branding_updated',
			array(
				'before' => $before,
				'after'  => $after,
			),
			'settings'
		);

		return ApiResponse::ok( $this->present() );
	}

	/**
	 * Wire form.
	 *
	 * @return array<string, mixed>
	 */
	private function present(): array {
		$current = $this->branding->current();

		return array(
			'settings'     => $this->branding->stored(),
			// What the customer will actually see, which differs from
			// what they saved whenever the licence does not cover it. A
			// screen that showed only the settings would let an operator
			// tick "hide the badge", see the tick stay, and find the badge
			// still on their client's site.
			'effective'    => $current->forAdmin() + array( 'showBadge' => $current->showBadge ),
			'entitlements' => array(
				Feature::WhiteLabel->value  => $this->gate->allows( Feature::WhiteLabel ),
				Feature::RemoveBadge->value => $this->gate->allows( Feature::RemoveBadge ),
			),
		);
	}

	/**
	 * Route arguments.
	 *
	 * Every value is re-cleaned in the service as well. These callbacks
	 * are the boundary; the service is what protects the JSON column from
	 * anything that reaches it another way.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function fields(): array {
		$url = array(
			'type'              => 'string',
			'required'          => false,
			'sanitize_callback' => 'esc_url_raw',
		);

		return array(
			'white_label'  => array(
				'type'     => 'boolean',
				'required' => false,
			),
			'product_name' => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'hide_badge'   => array(
				'type'     => 'boolean',
				'required' => false,
			),
			'badge_label'  => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'badge_url'    => $url,
			'logo_url'     => $url,
			'accent'       => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'support_url'  => $url,
		);
	}
}
