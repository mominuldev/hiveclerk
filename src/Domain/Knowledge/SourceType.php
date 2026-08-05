<?php
/**
 * Knowledge source type.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Knowledge;

/**
 * Where a source's content comes from.
 */
enum SourceType: string {

	case WpContent    = 'wp_content';
	case WooProducts  = 'woo_products';
	case WebsiteCrawl = 'website_crawl';
	case Pdf          = 'pdf';
	case Docx         = 'docx';
	case Faq          = 'faq';
	case Text         = 'text';

	/**
	 * Label shown in the admin.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::WpContent    => 'Site content',
			self::WooProducts  => 'Products',
			self::WebsiteCrawl => 'Website',
			self::Pdf          => 'PDF',
			self::Docx         => 'Word document',
			self::Faq          => 'FAQ',
			self::Text         => 'Text',
		};
	}

	/**
	 * Whether this source can be re-synced automatically.
	 *
	 * Uploaded files cannot: there is no origin to fetch again.
	 *
	 * @return bool
	 */
	public function supportsScheduledSync(): bool {
		return match ( $this ) {
			self::Pdf, self::Docx, self::Text, self::Faq => false,
			default => true,
		};
	}

	/**
	 * Parse a stored value.
	 *
	 * @param string|null $value Stored value.
	 * @return self
	 */
	public static function fromStorage( ?string $value ): self {
		return self::tryFrom( (string) $value ) ?? self::Text;
	}
}
