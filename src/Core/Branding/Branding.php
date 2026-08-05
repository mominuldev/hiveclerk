<?php
/**
 * Branding, as it applies right now.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Branding;

/**
 * What the customer chose, already reconciled with what they may have.
 *
 * ## Why the licence check happens before this object exists
 *
 * An operator on Pro can save `white_label => true`; the setting is kept.
 * What they get is this object with `whiteLabel` false, because
 * {@see BrandingService} resolves the saved preference against the tier
 * on the way out. Storing the refusal instead would mean an agency
 * upgrading has to go back and re-tick every box they already ticked,
 * and a lapsed licence would silently erase their configuration.
 *
 * So the setting is a preference and this is the outcome. Nothing that
 * renders branding sees the preference.
 */
final class Branding {

	/**
	 * The name shipped when nobody has chosen one.
	 */
	public const DEFAULT_NAME = 'Hiveclerk';

	/**
	 * Construct.
	 *
	 * @param string      $productName Name shown throughout the admin.
	 * @param bool        $whiteLabel  Whether the admin is fully rebranded.
	 * @param bool        $showBadge   Whether the widget carries the badge.
	 * @param string      $badgeLabel  Text on the badge.
	 * @param string|null $badgeUrl    Where the badge links.
	 * @param string|null $logoUrl     Replacement logo.
	 * @param string|null $accent      Replacement accent colour.
	 * @param string|null $supportUrl  Where the admin's help links go.
	 */
	public function __construct(
		public readonly string $productName = self::DEFAULT_NAME,
		public readonly bool $whiteLabel = false,
		public readonly bool $showBadge = true,
		public readonly string $badgeLabel = 'Powered by Hiveclerk',
		public readonly ?string $badgeUrl = null,
		public readonly ?string $logoUrl = null,
		public readonly ?string $accent = null,
		public readonly ?string $supportUrl = null
	) {
	}

	/**
	 * What the widget is told.
	 *
	 * @return array<string, mixed>
	 */
	public function forWidget(): array {
		return array(
			'show_badge' => $this->showBadge,
			'label'      => $this->badgeLabel,
			'url'        => $this->badgeUrl,
		);
	}

	/**
	 * What the admin app is told at first paint.
	 *
	 * @return array<string, mixed>
	 */
	public function forAdmin(): array {
		return array(
			'productName' => $this->productName,
			'whiteLabel'  => $this->whiteLabel,
			'logoUrl'     => $this->logoUrl,
			'accent'      => $this->accent,
			'supportUrl'  => $this->supportUrl,
		);
	}
}
