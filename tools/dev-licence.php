<?php
/**
 * Plugin Name:  Hiveclerk dev licence
 * Description:  Stands in for the licence server so paid features can be exercised locally. Never ship this.
 * Version:      1.0.0
 *
 * Copy or symlink this file into wp-content/mu-plugins/ on a development
 * install. It is deliberately not loaded by the plugin itself: a file that
 * grants every entitlement is not a file that should be one `if` away from
 * running in production.
 *
 *     ln -s "$PWD/tools/dev-licence.php" ../../mu-plugins/hiveclerk-dev-licence.php
 *
 * Two independent mechanisms, because they answer different questions:
 *
 * 1. `HIVECLERK_DEV_TIER` pins the tier. This is what you want when you are
 *    building a gated screen and just need it to render — it lifts the
 *    feature gates *and* the numeric limits, because both are read off the
 *    tier.
 *
 * 2. The stub server answers activate/deactivate/check, so the licence
 *    screen's own flow can be walked: pasting a key, watching it verify,
 *    re-checking it, releasing the seat. Any key starting `HVC-DEV`
 *    activates; anything else is refused, so the failure path is reachable
 *    too.
 *
 * @package Hiveclerk
 */

defined( 'ABSPATH' ) || exit;

/**
 * The tier this install pretends to hold.
 *
 * Set to null to leave the stored licence alone and use the stub server
 * instead. Recognised values: free, pro, business, agency, managed.
 */
if ( ! defined( 'HIVECLERK_DEV_TIER' ) ) {
	define( 'HIVECLERK_DEV_TIER', 'agency' );
}

/*
 * Pin the tier by writing the state the licence service reads.
 *
 * `masked` is left null on purpose. LicenceService::refreshIfStale() skips
 * an install with no key, so nothing here ever tries to phone home — which
 * is what stops the admin taking a ten-second timeout on every page load
 * against a server that does not exist.
 */
add_action(
	'admin_init',
	static function (): void {
		if ( null === HIVECLERK_DEV_TIER ) {
			return;
		}

		$state = get_option( 'hiveclerk_licence_state', array() );

		// Both fields, not just the tier. Walking the stub server's
		// invalid-key path leaves the tier the server reported and a
		// status of `invalid`, and a guard that only compared tiers would
		// then decide the state was already correct and leave every paid
		// feature switched off until somebody cleared the option by hand.
		if (
			is_array( $state )
			&& ( $state['tier'] ?? null ) === HIVECLERK_DEV_TIER
			&& 'active' === ( $state['status'] ?? null )
		) {
			return;
		}

		update_option(
			'hiveclerk_licence_state',
			array(
				'tier'       => HIVECLERK_DEV_TIER,
				'status'     => 'active',
				'masked'     => null,
				'sites'      => 1,
				'expires_at' => null,
				'checked_at' => gmdate( 'c' ),
				'customer'   => 'Local development',
			),
			false
		);
	},
	1
);

/*
 * Answer the licence API without leaving the machine.
 *
 * Intercepts at `pre_http_request` rather than repointing
 * `hiveclerk/licence/endpoint`, so there is no second server to run and the
 * request the plugin actually builds — headers, body, TLS flag — is the one
 * being exercised.
 */
add_filter(
	'pre_http_request',
	static function ( $pre, array $args, string $url ) {
		if ( ! str_contains( $url, 'licence.hiveclerk.com' ) ) {
			return $pre;
		}

		$body = json_decode( (string) ( $args['body'] ?? '' ), true );
		$key  = is_array( $body ) ? (string) ( $body['key'] ?? '' ) : '';

		// Anything not starting HVC-DEV is refused, so the invalid-key
		// path on the settings screen is reachable without inventing a
		// second stub.
		$status = str_starts_with( $key, 'HVC-DEV' ) ? 'active' : 'invalid';

		if ( str_contains( $url, '/deactivate' ) ) {
			$status = 'inactive';
		}

		return array(
			'headers'  => array(),
			'cookies'  => array(),
			'filename' => null,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => (string) wp_json_encode(
				array(
					'status'     => $status,
					'tier'       => null === HIVECLERK_DEV_TIER ? 'agency' : HIVECLERK_DEV_TIER,
					'sites'      => 1,
					'expires_at' => gmdate( 'c', time() + YEAR_IN_SECONDS ),
					'customer'   => 'Local development',
					'message'    => 'invalid' === $status ? 'Dev keys start with HVC-DEV.' : null,
				)
			),
		);
	},
	10,
	3
);

/*
 * A visible mark that this is on.
 *
 * A development install that silently behaves like an Agency licence is one
 * where "it works on my machine" means something different from what it
 * usually means.
 *
 * Note that this notice does *not* appear on Hiveclerk's own screen:
 * `body.hvc-page .notice` is hidden by the SPA's wp-admin reset, and a dev
 * tool should not fight the plugin's own stylesheet to be seen. On that
 * screen the tell is the licence tab, which reads "Local development" as
 * the account name, and the sidebar footer, which reads the pinned tier.
 */
add_action(
	'admin_notices',
	static function (): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Hiveclerk dev licence is active.', 'hiveclerk' ),
			esc_html(
				sprintf(
					/* translators: %s: tier name. */
					__( 'Paid features are unlocked at the %s tier by a must-use plugin. Remove it to see what a real install sees.', 'hiveclerk' ),
					null === HIVECLERK_DEV_TIER ? 'stubbed' : HIVECLERK_DEV_TIER
				)
			)
		);
	}
);
