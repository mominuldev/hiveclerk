<?php
/**
 * Branding resolution and storage.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Branding;

use Hiveclerk\Core\Licence\Feature;
use Hiveclerk\Core\Licence\LicenceGate;
use Hiveclerk\Core\Settings\SettingsRepository;

/**
 * Reads the branding preferences and applies the licence to them
 * (FR-SYS-08).
 *
 * Two separate entitlements, because they are two separate products:
 *
 * - **Removing the badge** is Pro. It is the third upgrade trigger in
 *   D16 §3 and it bites in week one for anybody building sites for
 *   clients.
 * - **White-label** is Agency. Replacing the product's name and mark
 *   throughout the admin is what an agency is actually buying at $899 —
 *   the AI underneath it is the commodity.
 *
 * Both are resolved here rather than at each render site. A product name
 * read straight out of settings in four screens is four places that get
 * the tier check wrong, and the one that gets it wrong is the one an
 * agency's client sees.
 */
final class BrandingService {

	/**
	 * Settings key everything here lives under.
	 */
	private const KEY = 'branding';

	/**
	 * Longest a replacement product name may be.
	 *
	 * It renders in the sidebar, the menu and the browser tab, and a name
	 * that overflows all three is not a feature.
	 */
	private const MAX_NAME = 40;

	/**
	 * Construct.
	 *
	 * @param SettingsRepository $settings Settings.
	 * @param LicenceGate        $gate     Entitlements.
	 */
	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly LicenceGate $gate
	) {
	}

	/**
	 * Branding as it applies right now.
	 *
	 * @return Branding
	 */
	public function current(): Branding {
		$stored     = $this->stored();
		$whiteLabel = $stored['white_label'] && $this->gate->allows( Feature::WhiteLabel );
		$mayHide    = $this->gate->allows( Feature::RemoveBadge );

		return new Branding(
			$whiteLabel ? $stored['product_name'] : Branding::DEFAULT_NAME,
			$whiteLabel,
			// The badge shows unless the customer both asked to hide it
			// and is entitled to. Defaulting the other way round would put
			// a free install's badge behind a setting nobody set.
			! ( $stored['hide_badge'] && $mayHide ),
			$whiteLabel && '' !== $stored['badge_label']
				? $stored['badge_label']
				: sprintf(
					/* translators: %s: product name. */
					__( 'Powered by %s', 'hiveclerk' ),
					Branding::DEFAULT_NAME
				),
			$whiteLabel ? $this->nullable( $stored['badge_url'] ) : null,
			$whiteLabel ? $this->nullable( $stored['logo_url'] ) : null,
			$whiteLabel ? $this->nullable( $stored['accent'] ) : null,
			$whiteLabel ? $this->nullable( $stored['support_url'] ) : null
		);
	}

	/**
	 * The preferences as saved, whether or not they apply.
	 *
	 * The settings screen renders these rather than the resolved values,
	 * so an agency-in-waiting can see what they configured and why it is
	 * not in force.
	 *
	 * @return array{white_label: bool, product_name: string, hide_badge: bool, badge_label: string, badge_url: string, logo_url: string, accent: string, support_url: string}
	 */
	public function stored(): array {
		$raw = $this->settings->get( self::KEY );
		$raw = is_array( $raw ) ? $raw : array();

		return array(
			'white_label'  => (bool) ( $raw['white_label'] ?? false ),
			'product_name' => $this->string( $raw['product_name'] ?? null, Branding::DEFAULT_NAME ),
			'hide_badge'   => (bool) ( $raw['hide_badge'] ?? false ),
			'badge_label'  => $this->string( $raw['badge_label'] ?? null, '' ),
			'badge_url'    => $this->string( $raw['badge_url'] ?? null, '' ),
			'logo_url'     => $this->string( $raw['logo_url'] ?? null, '' ),
			'accent'       => $this->string( $raw['accent'] ?? null, '' ),
			'support_url'  => $this->string( $raw['support_url'] ?? null, '' ),
		);
	}

	/**
	 * Replace the preferences.
	 *
	 * Values are cleaned here as well as at the route, because they land
	 * in a JSON column that is read back by code that builds markup and
	 * HTTP requests from it. A URL that only passed a route's
	 * `sanitize_callback` is a URL nobody re-checks after an import.
	 *
	 * @param array<string, mixed> $input Submitted values.
	 * @return array<string, mixed> The preferences as stored.
	 */
	public function save( array $input ): array {
		$current = $this->stored();

		$clean = array(
			'white_label'  => array_key_exists( 'white_label', $input )
				? (bool) $input['white_label']
				: $current['white_label'],
			'product_name' => $this->name( $input['product_name'] ?? $current['product_name'] ),
			'hide_badge'   => array_key_exists( 'hide_badge', $input )
				? (bool) $input['hide_badge']
				: $current['hide_badge'],
			'badge_label'  => $this->name( $input['badge_label'] ?? $current['badge_label'], '' ),
			'badge_url'    => $this->url( $input['badge_url'] ?? $current['badge_url'] ),
			'logo_url'     => $this->url( $input['logo_url'] ?? $current['logo_url'] ),
			'accent'       => $this->hex( $input['accent'] ?? $current['accent'] ),
			'support_url'  => $this->url( $input['support_url'] ?? $current['support_url'] ),
		);

		$this->settings->set( self::KEY, $clean );

		return $clean;
	}

	/**
	 * A stored string, with anything else read as absent.
	 *
	 * The stored array is a JSON column, which means it can hold whatever
	 * a previous version wrote or an import put there. Every read goes
	 * through here so that a boolean where a string was expected produces
	 * the default rather than "1" in the sidebar.
	 *
	 * @param mixed  $value    Raw value.
	 * @param string $fallback Value when nothing usable is there.
	 * @return string
	 */
	private function string( mixed $value, string $fallback ): string {
		return is_string( $value ) && '' !== $value ? $value : $fallback;
	}

	/**
	 * A trimmed, bounded display name.
	 *
	 * @param mixed  $value    Raw value.
	 * @param string $fallback Value when nothing usable was given.
	 * @return string
	 */
	private function name( mixed $value, string $fallback = Branding::DEFAULT_NAME ): string {
		if ( ! is_string( $value ) ) {
			return $fallback;
		}

		$clean = trim( sanitize_text_field( $value ) );

		if ( '' === $clean ) {
			return $fallback;
		}

		return function_exists( 'mb_substr' )
			? mb_substr( $clean, 0, self::MAX_NAME )
			: substr( $clean, 0, self::MAX_NAME );
	}

	/**
	 * A stored URL, or the empty string.
	 *
	 * `esc_url_raw` with an explicit protocol list: the default allows
	 * `javascript:` through in some WordPress versions when the value is
	 * later printed into an attribute, and the logo URL is printed into
	 * exactly that.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function url( mixed $value ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}

		return (string) esc_url_raw( trim( $value ), array( 'http', 'https' ) );
	}

	/**
	 * A six-digit hex colour, or the empty string.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function hex( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$clean = sanitize_hex_color( trim( $value ) );

		return is_string( $clean ) ? $clean : '';
	}

	/**
	 * The empty string read as absent.
	 *
	 * @param string $value Stored value.
	 * @return string|null
	 */
	private function nullable( string $value ): ?string {
		return '' === $value ? null : $value;
	}
}
