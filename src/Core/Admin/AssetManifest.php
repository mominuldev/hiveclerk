<?php
/**
 * Vite manifest reader.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Admin;

/**
 * Resolves hashed asset filenames from the Vite build manifest.
 */
final class AssetManifest {

	/**
	 * Parsed manifest, or null when not yet read.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $manifest = null;

	/**
	 * Construct.
	 *
	 * @param string $dir Absolute path to the build output directory.
	 * @param string $url Public URL of the build output directory.
	 */
	public function __construct(
		private readonly string $dir,
		private readonly string $url
	) {
	}

	/**
	 * Whether a usable build exists.
	 *
	 * @return bool
	 */
	public function exists(): bool {
		return null !== $this->read();
	}

	/**
	 * URL of the built JavaScript entry.
	 *
	 * @param string $entry Entry key as it appears in the manifest.
	 * @return string|null
	 */
	public function scriptUrl( string $entry ): ?string {
		$record = $this->entry( $entry );

		if ( null === $record || ! isset( $record['file'] ) || ! is_string( $record['file'] ) ) {
			return null;
		}

		return $this->url . $record['file'];
	}

	/**
	 * URLs of stylesheets emitted for an entry.
	 *
	 * @param string $entry Entry key.
	 * @return array<int, string>
	 */
	public function styleUrls( string $entry ): array {
		$record = $this->entry( $entry );

		if ( null === $record || ! isset( $record['css'] ) || ! is_array( $record['css'] ) ) {
			return array();
		}

		$urls = array();

		foreach ( $record['css'] as $file ) {
			if ( is_string( $file ) ) {
				$urls[] = $this->url . $file;
			}
		}

		return $urls;
	}

	/**
	 * A single manifest record.
	 *
	 * @param string $entry Entry key.
	 * @return array<string, mixed>|null
	 */
	private function entry( string $entry ): ?array {
		$manifest = $this->read();

		if ( null === $manifest || ! isset( $manifest[ $entry ] ) || ! is_array( $manifest[ $entry ] ) ) {
			return null;
		}

		return $manifest[ $entry ];
	}

	/**
	 * Read and cache the manifest.
	 *
	 * @return array<string, mixed>|null
	 */
	private function read(): ?array {
		if ( null !== $this->manifest ) {
			return $this->manifest;
		}

		$path = $this->dir . '.vite/manifest.json';

		if ( ! is_readable( $path ) ) {
			return null;
		}

		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $raw ) {
			return null;
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return null;
		}

		$this->manifest = $decoded;

		return $this->manifest;
	}
}
