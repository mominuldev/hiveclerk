<?php
/**
 * Privacy settings endpoints (FR-SYS-04).
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Api\Controllers;

use Hiveclerk\Api\AbstractController;
use Hiveclerk\Api\Response\ApiResponse;
use Hiveclerk\Core\Audit\AuditLogger;
use Hiveclerk\Core\Capabilities\Capabilities;
use Hiveclerk\Core\Privacy\PrivacySettings;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The four decisions a site owner makes about visitors' data.
 *
 * `manage_settings` rather than `view_conversations`, and the gap is the
 * point: a shop manager supervising conversations must not be able to
 * shorten the retention policy, because doing so deletes history
 * irreversibly and unattended, and the person answerable for that is the
 * one who also holds the API key.
 *
 * The response carries how many conversations the current policy would
 * remove on its next run. A retention setting whose consequence is
 * invisible until the job runs is one an operator changes from 24 months
 * to 6 without realising they have just scheduled the deletion of four
 * fifths of their history.
 */
final class PrivacyController extends AbstractController {

	/**
	 * Construct.
	 *
	 * @param PrivacySettings                 $privacy       Privacy preferences.
	 * @param ConversationRepositoryInterface $conversations Conversations.
	 * @param AuditLogger                     $audit         Audit trail.
	 */
	public function __construct(
		private readonly PrivacySettings $privacy,
		private readonly ConversationRepositoryInterface $conversations,
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
			'/admin/settings/privacy',
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
	 * The current preferences and what they imply.
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
		$before = $this->privacy->current();
		$input  = array();

		foreach ( array_keys( $this->fields() ) as $field ) {
			if ( null !== $request->get_param( $field ) ) {
				$input[ $field ] = $request->get_param( $field );
			}
		}

		$after = $this->privacy->save( $input );

		/*
		 * `privacy.` is one of the prefixes AuditEntry redacts, so the
		 * consent text — which is the only free-form value here — is
		 * recorded as changed rather than quoted. What matters to whoever
		 * reads this log later is that somebody turned the retention
		 * policy down, not what wording they used.
		 */
		$this->audit->record(
			'privacy.settings_updated',
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
		$settings = $this->privacy->current();
		$cutoff   = $this->privacy->cutoff();

		return array(
			'settings'  => $settings,
			'retention' => array(
				'max_months' => PrivacySettings::MAX_MONTHS,
				/*
				 * Counted live rather than cached. It is the number that
				 * tells an operator what the save they are about to make
				 * will destroy, and a stale one is worse than none.
				 */
				'pending'    => null === $cutoff
					? 0
					: $this->conversations->countStartedBefore( $cutoff->format( 'Y-m-d H:i:s' ) ),
				'cutoff'     => $cutoff?->format( 'Y-m-d H:i:s' ),
			),
		);
	}

	/**
	 * Route arguments.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function fields(): array {
		$flag = array(
			'type'     => 'boolean',
			'required' => false,
		);

		return array(
			'retention_months'    => array(
				'type'              => 'integer',
				'required'          => false,
				'minimum'           => 0,
				'maximum'           => PrivacySettings::MAX_MONTHS,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'store_ip_hash'       => $flag,
			'require_consent'     => $flag,
			'consent_text'        => array(
				'type'              => 'string',
				'required'          => false,
				/*
				 * The textarea variant. The single-line version silently
				 * flattens a two-paragraph consent notice, and the
				 * operator would find out from a visitor.
				 */
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'delete_on_uninstall' => $flag,
		);
	}
}
