<?php
/**
 * Extractor base class.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Extractors;

use Hiveclerk\Domain\Knowledge\KnowledgeSource;

/**
 * Defaults so an extractor only declares what it actually differs on.
 */
abstract class AbstractExtractor implements ExtractorInterface {

	/**
	 * Most extractors have no external dependency.
	 *
	 * @return bool
	 */
	public function isAvailable(): bool {
		return true;
	}

	/**
	 * Nothing to explain when nothing is missing.
	 *
	 * @return string
	 */
	public function unavailableReason(): string {
		return '';
	}

	/**
	 * No estimate unless the extractor can produce a real one.
	 *
	 * @param KnowledgeSource $source Source.
	 * @return int|null
	 */
	public function estimate( KnowledgeSource $source ): ?int {
		unset( $source );

		return null;
	}

	/**
	 * Read a list of strings out of a source's configuration.
	 *
	 * Configuration arrives as JSON written by a settings form, possibly
	 * by an older version of the plugin. Nothing about its shape is
	 * guaranteed, and an extractor that assumes otherwise fatals on the
	 * sites that upgraded rather than the ones being tested.
	 *
	 * @param KnowledgeSource $source Source.
	 * @param string          $key    Configuration key.
	 * @return array<int, string>
	 */
	protected function stringList( KnowledgeSource $source, string $key ): array {
		$value = $source->config[ $key ] ?? null;

		if ( ! is_array( $value ) ) {
			return array();
		}

		$strings = array();

		foreach ( $value as $item ) {
			if ( is_string( $item ) && '' !== trim( $item ) ) {
				$strings[] = trim( $item );
			} elseif ( is_int( $item ) ) {
				$strings[] = (string) $item;
			}
		}

		return $strings;
	}

	/**
	 * Read an integer from a source's configuration.
	 *
	 * @param KnowledgeSource $source   Source.
	 * @param string          $key      Configuration key.
	 * @param int             $fallback Value when absent or unusable.
	 * @return int
	 */
	protected function intConfig( KnowledgeSource $source, string $key, int $fallback ): int {
		$value = $source->config[ $key ] ?? null;

		return is_numeric( $value ) ? (int) $value : $fallback;
	}

	/**
	 * Read a string from a source's configuration.
	 *
	 * @param KnowledgeSource $source   Source.
	 * @param string          $key      Configuration key.
	 * @param string          $fallback Value when absent or unusable.
	 * @return string
	 */
	protected function stringConfig( KnowledgeSource $source, string $key, string $fallback = '' ): string {
		$value = $source->config[ $key ] ?? null;

		return is_string( $value ) && '' !== trim( $value ) ? trim( $value ) : $fallback;
	}

	/**
	 * Read a boolean from a source's configuration.
	 *
	 * @param KnowledgeSource $source   Source.
	 * @param string          $key      Configuration key.
	 * @param bool            $fallback Value when absent.
	 * @return bool
	 */
	protected function boolConfig( KnowledgeSource $source, string $key, bool $fallback = false ): bool {
		$value = $source->config[ $key ] ?? null;

		return null === $value ? $fallback : (bool) $value;
	}
}
