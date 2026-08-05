<?php
/**
 * Admin app mount point.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Admin;

use DateTimeZone;
use Hiveclerk\Core\Branding\BrandingService;
use Hiveclerk\Core\Capabilities\Capabilities;
use Hiveclerk\Core\Capabilities\CapabilityManager;
use Hiveclerk\Core\Licence\LicenceService;
use Hiveclerk\Core\Settings\SettingsRepository;
use Hiveclerk\Core\Support\ClockInterface;

/**
 * Registers the admin menu and mounts the React SPA.
 */
final class AdminPage {

	public const SLUG  = 'hiveclerk';
	public const ENTRY = 'admin-app/src/main.tsx';

	/**
	 * Construct.
	 *
	 * @param AssetManifest      $assets   Build manifest reader.
	 * @param SettingsRepository $settings Settings.
	 * @param BrandingService    $branding Branding, already reconciled with the licence.
	 * @param LicenceService     $licences Licence state.
	 * @param ClockInterface     $clock    Clock.
	 */
	public function __construct(
		private readonly AssetManifest $assets,
		private readonly SettingsRepository $settings,
		private readonly BrandingService $branding,
		private readonly LicenceService $licences,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * Attach hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'registerMenu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'admin_body_class', array( $this, 'bodyClass' ) );
	}

	/**
	 * Register the menu entry.
	 *
	 * Submenus are deliberately not registered: the SPA owns its own
	 * navigation, and duplicating it in wp-admin would let the two drift
	 * out of sync.
	 *
	 * @return void
	 */
	public function registerMenu(): void {
		add_menu_page(
			__( 'Hiveclerk', 'hiveclerk' ),
			$this->menuLabel(),
			CapabilityManager::menuCapability(),
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-groups',
			58
		);
	}

	/**
	 * Add a body class on our screen so wp-admin chrome can be tamed.
	 *
	 * @param string $classes Existing classes.
	 * @return string
	 */
	public function bodyClass( string $classes ): string {
		if ( ! $this->isOurScreen() ) {
			return $classes;
		}

		return $classes . ' hvc-page';
	}

	/**
	 * Enqueue the SPA bundle on our screen only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( string $hook ): void {
		if ( ! $this->isOurScreen() ) {
			return;
		}

		$script = $this->assets->scriptUrl( self::ENTRY );

		if ( null === $script ) {
			return;
		}

		wp_enqueue_script(
			'hiveclerk-admin',
			$script,
			array(),
			HIVECLERK_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		// The bundle is an ES module; WordPress has no first-class way to
		// say so, so the type attribute is set on output.
		add_filter( 'script_loader_tag', array( $this, 'asModule' ), 10, 2 );

		foreach ( $this->assets->styleUrls( self::ENTRY ) as $index => $style ) {
			wp_enqueue_style(
				'hiveclerk-admin' . ( $index > 0 ? '-' . $index : '' ),
				$style,
				array(),
				HIVECLERK_VERSION
			);
		}

		wp_add_inline_script(
			'hiveclerk-admin',
			'window.HVC_BOOT = ' . wp_json_encode( $this->bootData() ) . ';',
			'before'
		);

		/*
		 * `wp_set_script_translations()` is deliberately not called here.
		 *
		 * It only does anything for a bundle that imports `@wordpress/i18n`,
		 * and this one cannot: ESLint's `no-restricted-imports` fails the
		 * build on any `@wordpress/*` package, which is a deliberate
		 * architectural constraint (see CLAUDE.md §5) and not an oversight.
		 * Calling it anyway added `wp-i18n` as a script dependency — so
		 * every admin page load fetched a core bundle nothing used — while
		 * advertising a translation path that does not exist. The admin SPA
		 * is English-only in V1, and saying so is better than shipping the
		 * appearance of translatability. The PHP surface is fully
		 * translatable and is what `languages/hiveclerk.pot` covers.
		 */
	}

	/**
	 * Mark our script tag as an ES module.
	 *
	 * @param string $tag    Script tag HTML.
	 * @param string $handle Script handle.
	 * @return string
	 */
	public function asModule( string $tag, string $handle ): string {
		if ( 'hiveclerk-admin' !== $handle ) {
			return $tag;
		}

		return str_replace( '<script ', '<script type="module" ', $tag );
	}

	/**
	 * Render the mount point.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( CapabilityManager::menuCapability() ) ) {
			wp_die( esc_html__( 'You do not have access to Hiveclerk.', 'hiveclerk' ) );
		}

		if ( ! $this->assets->exists() ) {
			$this->renderMissingBuild();
			return;
		}

		echo '<div id="hvc-root"></div>';
	}

	/**
	 * Explain a missing build rather than showing a blank screen.
	 *
	 * @return void
	 */
	private function renderMissingBuild(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Hiveclerk', 'hiveclerk' ) . '</h1>';
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__(
			'The admin app has not been built yet. Run "npm install && npm run build" in the plugin directory.',
			'hiveclerk'
		);
		echo '</p></div></div>';
	}

	/**
	 * Data handed to the SPA at first paint.
	 *
	 * Everything the app needs to render its shell without a round-trip:
	 * REST root, nonce, capabilities, locale and branding.
	 *
	 * @return array<string, mixed>
	 */
	private function bootData(): array {
		$user = wp_get_current_user();

		$capabilities = array();

		foreach ( Capabilities::all() as $capability ) {
			$capabilities[ $capability ] = current_user_can( $capability );
		}

		return array(
			'version'      => HIVECLERK_VERSION,
			'restUrl'      => esc_url_raw( rest_url( 'hiveclerk/v1' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'adminUrl'     => esc_url_raw( admin_url( 'admin.php?page=' . self::SLUG ) ),
			'assetsUrl'    => esc_url_raw( HIVECLERK_URL . 'assets/' ),
			'locale'       => get_user_locale(),
			'isRtl'        => is_rtl(),
			'capabilities' => $capabilities,
			'user'         => array(
				'id'     => $user->ID,
				'name'   => $user->display_name,
				'email'  => $user->user_email,
				'avatar' => get_avatar_url( $user->ID, array( 'size' => 64 ) ),
			),
			'branding'     => $this->branding->current()->forAdmin(),
			'appearance'   => array(
				'theme' => (string) $this->settings->get( 'appearance.theme', 'auto' ),
			),
			// The whole licence, not a tier string. The sidebar footer,
			// the upgrade prompts and the gated screens all need to know
			// which features are in force, and a first paint that only
			// carried the tier name would make every one of them repeat
			// the entitlement arithmetic in TypeScript.
			'licence'      => $this->licences->current()->toArray(
				$this->clock->now()->setTimezone( new DateTimeZone( 'UTC' ) )
			),
		);
	}

	/**
	 * Menu label, honouring white-label mode.
	 *
	 * Resolved through BrandingService rather than read from settings, so
	 * a Pro install that saved a replacement name does not get it in the
	 * wp-admin menu — which is the one place a customer would see it
	 * before the licence check ever ran.
	 *
	 * @return string
	 */
	private function menuLabel(): string {
		return $this->branding->current()->productName;
	}

	/**
	 * Whether the current request is our admin screen.
	 *
	 * @return bool
	 */
	private function isOurScreen(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return self::SLUG === $page;
	}
}
