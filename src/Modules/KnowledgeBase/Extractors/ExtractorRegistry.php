<?php
/**
 * Extractor lookup.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Extractors;

use Hiveclerk\Domain\Knowledge\SourceType;

/**
 * Finds the extractor for a source type.
 *
 * A registry rather than a match statement so a third-party plugin can
 * add a source type — the integration story the API specification
 * commits to — without editing this file.
 */
final class ExtractorRegistry {

	/**
	 * Extractors by source type value.
	 *
	 * @var array<string, ExtractorInterface>
	 */
	private array $extractors = array();

	/**
	 * Register an extractor.
	 *
	 * Last registration wins, so a site can replace a built-in extractor
	 * with its own without the original having to know.
	 *
	 * @param ExtractorInterface $extractor Extractor.
	 * @return void
	 */
	public function add( ExtractorInterface $extractor ): void {
		$this->extractors[ $extractor->type()->value ] = $extractor;
	}

	/**
	 * Find the extractor for a type.
	 *
	 * @param SourceType $type Source type.
	 * @return ExtractorInterface|null
	 */
	public function for( SourceType $type ): ?ExtractorInterface {
		return $this->extractors[ $type->value ] ?? null;
	}

	/**
	 * Every registered extractor.
	 *
	 * @return array<string, ExtractorInterface>
	 */
	public function all(): array {
		return $this->extractors;
	}

	/**
	 * Which source types can actually be used on this installation.
	 *
	 * Drives the add-source screen: offering a WooCommerce source on a
	 * site without WooCommerce produces a source that can only ever fail,
	 * and the customer finds out after configuring it.
	 *
	 * @return array<string, string> Type value to unavailability reason,
	 *                               empty string when available.
	 */
	public function availability(): array {
		$map = array();

		foreach ( $this->extractors as $value => $extractor ) {
			$map[ $value ] = $extractor->isAvailable() ? '' : $extractor->unavailableReason();
		}

		return $map;
	}
}
