<?php
/**
 * Settings storage.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Settings;

/**
 * Reads and writes plugin settings.
 *
 * All values live under a single option so a settings read costs one row
 * rather than one row per key. Autoload is off because the admin app
 * fetches settings over REST, not on every front-end page load.
 */
final class SettingsRepository {

	private const OPTION = 'hiveclerk_settings';

	/**
	 * Cached settings for this request.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $cache = null;

	/**
	 * Read a setting.
	 *
	 * @param string $key      Dot-notation key.
	 * @param mixed  $fallback Returned when the key is unset.
	 * @return mixed
	 */
	public function get( string $key, mixed $fallback = null ): mixed {
		$settings = $this->all();
		$segments = explode( '.', $key );
		$value    = $settings;

		foreach ( $segments as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return $fallback;
			}

			$value = $value[ $segment ];
		}

		return $value;
	}

	/**
	 * Write a setting.
	 *
	 * @param string $key   Dot-notation key.
	 * @param mixed  $value Value.
	 * @return void
	 */
	public function set( string $key, mixed $value ): void {
		$settings = $this->all();
		$segments = explode( '.', $key );
		$target   = &$settings;

		foreach ( $segments as $segment ) {
			if ( ! isset( $target[ $segment ] ) || ! is_array( $target[ $segment ] ) ) {
				$target[ $segment ] = array();
			}

			$target = &$target[ $segment ];
		}

		$target = $value;

		$this->save( $settings );
	}

	/**
	 * All settings merged over defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$this->cache = array_replace_recursive( $this->defaults(), $stored );

		return $this->cache;
	}

	/**
	 * Replace all settings.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return void
	 */
	public function save( array $settings ): void {
		$this->cache = $settings;
		update_option( self::OPTION, $settings, false );
	}

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	private function defaults(): array {
		return array(
			// Left empty rather than defaulted to a provider. Which
			// embedding model a site uses decides what its vectors mean,
			// and guessing on the operator's behalf would pin a choice they
			// never made to content they cannot then search with anything
			// else. EmbeddingService falls back to the first configured
			// provider and records what it used.
			'retrieval'  => array(
				'embed_provider' => null,
				'embed_model'    => null,
			),
			/*
			 * Every key here is read somewhere. `anonymise_ip` used to sit
			 * in this list and was read by nothing: an address is salted
			 * and hashed at the point it is read on every path, and the
			 * original is never held, so the setting described a choice
			 * the product does not offer. `store_ip_hash` replaces it with
			 * one it does — whether the hash is kept at all. See
			 * {@see \Hiveclerk\Core\Privacy\PrivacySettings}.
			 */
			'privacy'    => array(
				'retention_months'    => 12,
				'store_ip_hash'       => true,
				'require_consent'     => false,
				'consent_text'        => null,
				'delete_on_uninstall' => false,
			),
			'branding'   => array(
				'white_label'  => false,
				'product_name' => 'Hiveclerk',
			),
			'appearance' => array(
				'theme' => 'auto',
			),
		);
	}
}
