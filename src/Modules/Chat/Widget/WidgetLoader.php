<?php
/**
 * Front-end widget enqueue.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Chat\Widget;

use Hiveclerk\Domain\Agent\Agent;
use Hiveclerk\Modules\Chat\Services\WidgetConfig;

/**
 * Puts the widget on the customer's site, or doesn't.
 *
 * "Or doesn't" is the interesting half. The performance budget allows the
 * widget 40 KB and a 50 ms LCP contribution, and the cheapest way to meet
 * both on a page with nobody on duty is to send nothing at all — the
 * wireframes call for no launcher in that case, so there is no reason for
 * the bytes to travel.
 *
 * The configuration is inlined rather than fetched. A round trip before
 * first paint is the difference between a launcher that is there when the
 * page settles and one that pops in afterwards, and the payload is a few
 * hundred bytes of public data. `GET /public/bootstrap` still exists and
 * returns the same payload from the same builder — it is what a
 * full-page-cached site needs, and what a themer embedding the widget by
 * hand can call.
 */
final class WidgetLoader {

	/**
	 * Script handle.
	 */
	public const HANDLE = 'hiveclerk-widget';

	/**
	 * Built bundle, relative to the plugin directory.
	 *
	 * Unhashed, unlike the admin build. The widget is one file loaded by
	 * PHP that knows the plugin version, so `ver=` busts the cache and a
	 * manifest read on every front-end request buys nothing.
	 */
	private const BUNDLE = 'assets/widget/hiveclerk-widget.js';

	/**
	 * Construct.
	 *
	 * @param WidgetConfig $config Clerk selection and public payload.
	 */
	public function __construct(
		private readonly WidgetConfig $config
	) {
	}

	/**
	 * Attach hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'script_loader_tag', array( $this, 'asModule' ), 10, 2 );
	}

	/**
	 * Enqueue the widget where a clerk is on duty.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( ! $this->shouldRender() ) {
			return;
		}

		$agent = $this->config->select();

		if ( null === $agent ) {
			return;
		}

		if ( ! is_readable( HIVECLERK_DIR . self::BUNDLE ) ) {
			// Nothing is printed and no notice is raised. A missing build is
			// a developer's problem, and a visitor's page is not where it
			// should be reported.
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			HIVECLERK_URL . self::BUNDLE,
			array(),
			HIVECLERK_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_add_inline_script(
			self::HANDLE,
			'window.HVC_WIDGET = ' . wp_json_encode( $this->bootData( $agent ) ) . ';',
			'before'
		);
	}

	/**
	 * Mark the widget's tag as an ES module.
	 *
	 * @param string $tag    Script tag HTML.
	 * @param string $handle Script handle.
	 * @return string
	 */
	public function asModule( string $tag, string $handle ): string {
		if ( self::HANDLE !== $handle ) {
			return $tag;
		}

		return str_replace( '<script ', '<script type="module" ', $tag );
	}

	/**
	 * Whether this request is a page a visitor is reading.
	 *
	 * @return bool
	 */
	private function shouldRender(): bool {
		if ( is_admin() || is_feed() || is_embed() || is_preview() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		/**
		 * Filter whether the widget loads on this request.
		 *
		 * The documented way to keep a clerk off checkout, off a landing
		 * page, or off anything else until display rules land.
		 *
		 * @param bool $render Whether to enqueue.
		 */
		return (bool) apply_filters( 'hiveclerk/widget/render', true );
	}

	/**
	 * The object the widget boots from.
	 *
	 * @param Agent $agent The clerk.
	 * @return array<string, mixed>
	 */
	private function bootData( Agent $agent ): array {
		$payload = $this->config->payload( $agent );

		$payload['rest_url'] = esc_url_raw( rest_url( 'hiveclerk/v1' ) );
		$payload['version']  = HIVECLERK_VERSION;

		return $payload;
	}
}
