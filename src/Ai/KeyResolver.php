<?php
/**
 * Provider credential storage.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai;

use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Core\Support\Encryptor;
use SensitiveParameter;

/**
 * Stores and resolves provider credentials.
 *
 * Three rules hold this together, and each exists because the alternative
 * has a failure mode worth avoiding:
 *
 * 1. **No read path returns a decrypted key, and only one probes.** The
 *    mask is computed once at write time and stored alongside the
 *    ciphertext, so rendering the settings screen never puts a plaintext
 *    key in a response. `describe()` is the single exception and it
 *    decrypts only to throw the result away: without that, a stored key
 *    that this install can no longer read is indistinguishable from a
 *    working one. `decrypt()` returns null for tampering, for a salt
 *    rotation and for a database restored without its salt option, and
 *    every caller reads null as "not configured" — so an operator whose
 *    salts moved sees a configured key, a plausible mask, and provider
 *    errors that point at the provider. The probe is what makes that
 *    state say so. It costs one AES-GCM open of a short string on an
 *    admin screen, and it never reaches a client as anything but a
 *    boolean.
 * 2. **Constants win over the database.** A site defining
 *    HIVECLERK_ANTHROPIC_KEY in wp-config.php keeps its key out of the
 *    database entirely, which is what an agency managing forty sites
 *    through version control actually wants.
 * 3. **Keys live in their own option.** Not in the settings blob, so a
 *    settings export, a staging sync or a support-requested dump of
 *    hiveclerk_settings never carries ciphertext with it.
 */
final class KeyResolver {

	private const OPTION = 'hiveclerk_provider_keys';

	/**
	 * Cached option contents for this request.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $cache = null;

	/**
	 * Construct.
	 *
	 * @param Encryptor      $encryptor Secret encryption.
	 * @param ClockInterface $clock     Clock.
	 */
	public function __construct(
		private readonly Encryptor $encryptor,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * Credentials for a provider.
	 *
	 * @param string $provider Provider identifier.
	 * @return Credentials
	 */
	public function credentials( string $provider ): Credentials {
		$record = $this->record( $provider );

		$constant = self::fromConstant( $provider );
		$key      = null !== $constant
			? $constant
			: $this->decrypt( $record );

		if ( null === $key ) {
			return Credentials::none();
		}

		return new Credentials(
			$key,
			self::string( $record, 'endpoint' ),
			self::string( $record, 'api_version' )
		);
	}

	/**
	 * Whether a usable key exists.
	 *
	 * @param string $provider Provider identifier.
	 * @return bool
	 */
	public function isConfigured( string $provider ): bool {
		if ( null !== self::fromConstant( $provider ) ) {
			return true;
		}

		return '' !== self::string( $this->record( $provider ), 'key' );
	}

	/**
	 * What the settings screen is allowed to know.
	 *
	 * Never includes the key, encrypted or otherwise. There is no code
	 * path anywhere that returns a decrypted key to a client — the
	 * requirement behind FR-SYS-03.
	 *
	 * @param string $provider Provider identifier.
	 * @return array<string, mixed>
	 */
	public function describe( string $provider ): array {
		$record   = $this->record( $provider );
		$constant = self::fromConstant( $provider );

		return array(
			'provider'    => $provider,
			'is_set'      => $this->isConfigured( $provider ),
			// False only in the state that is genuinely broken: ciphertext
			// is stored and this install cannot open it. A site with no key
			// is not unreadable, it is unset, and `is_set` already says so.
			'is_readable' => $this->isReadable( $record, $constant ),
			// A key held in wp-config has no stored mask, and deriving one
			// would mean reading the constant into a response. Saying where
			// it comes from is more useful than showing four characters of
			// it anyway.
			'masked'      => null !== $constant
				? 'Set in wp-config.php'
				: self::string( $record, 'masked' ),
			'from_config' => null !== $constant,
			'endpoint'    => self::string( $record, 'endpoint' ),
			'api_version' => self::string( $record, 'api_version' ),
			'model'       => self::string( $record, 'model' ),
			'verified_at' => self::string( $record, 'verified_at' ),
			'model_count' => (int) ( $record['model_count'] ?? 0 ),
		);
	}

	/**
	 * Store a key.
	 *
	 * Storing a key clears any previous verification: the old timestamp
	 * described a different credential, and showing it beside a new key
	 * would claim a check that never happened.
	 *
	 * @param string $provider   Provider identifier.
	 * @param string $key        Plaintext key.
	 * @param string $endpoint   Base URL, where required.
	 * @param string $apiVersion API version, where required.
	 * @return void
	 */
	public function store(
		string $provider,
		#[SensitiveParameter]
		string $key,
		string $endpoint = '',
		string $apiVersion = ''
	): void {
		$all    = $this->all();
		$record = $all[ $provider ] ?? array();

		$trimmed = trim( $key );

		if ( '' !== $trimmed ) {
			$record['key']         = $this->encryptor->encrypt( $trimmed );
			$record['masked']      = $this->encryptor->mask( $trimmed );
			$record['verified_at'] = '';
			$record['model_count'] = 0;
		}

		$record['endpoint']    = $endpoint;
		$record['api_version'] = $apiVersion;

		$all[ $provider ] = $record;

		$this->save( $all );
	}

	/**
	 * Remember which model this provider should use.
	 *
	 * @param string $provider Provider identifier.
	 * @param string $model    Model identifier.
	 * @return void
	 */
	public function setModel( string $provider, string $model ): void {
		$all              = $this->all();
		$record           = $all[ $provider ] ?? array();
		$record['model']  = $model;
		$all[ $provider ] = $record;

		$this->save( $all );
	}

	/**
	 * Record a successful verification.
	 *
	 * @param string $provider   Provider identifier.
	 * @param int    $modelCount Models the key can reach.
	 * @return void
	 */
	public function markVerified( string $provider, int $modelCount ): void {
		$all                   = $this->all();
		$record                = $all[ $provider ] ?? array();
		$record['verified_at'] = $this->clock->nowSql();
		$record['model_count'] = $modelCount;
		$all[ $provider ]      = $record;

		$this->save( $all );
	}

	/**
	 * Remove a provider's credentials.
	 *
	 * @param string $provider Provider identifier.
	 * @return void
	 */
	public function forget( string $provider ): void {
		$all = $this->all();

		unset( $all[ $provider ] );

		$this->save( $all );
	}

	/**
	 * Providers that currently have a key.
	 *
	 * @return array<int, string>
	 */
	public function configured(): array {
		$configured = array();

		foreach ( ProviderId::all() as $provider ) {
			if ( $this->isConfigured( $provider->value ) ) {
				$configured[] = $provider->value;
			}
		}

		return $configured;
	}

	/**
	 * Read a provider's stored record.
	 *
	 * @param string $provider Provider identifier.
	 * @return array<string, mixed>
	 */
	private function record( string $provider ): array {
		$all = $this->all();

		return isset( $all[ $provider ] ) && is_array( $all[ $provider ] )
			? $all[ $provider ]
			: array();
	}

	/**
	 * Whether a stored key can still be opened on this install.
	 *
	 * Answers true when there is nothing stored, because "unreadable" is a
	 * claim about a secret that exists. The failure this reports is a
	 * one-way one — a rotated salt cannot be un-rotated and the plaintext
	 * is gone — so the only thing an operator can do about it is paste the
	 * key again, and the only thing the product can do is say so instead
	 * of showing a mask that implies otherwise.
	 *
	 * @param array<string, mixed> $record   Stored record.
	 * @param string|null          $constant Key from wp-config, if any.
	 * @return bool
	 */
	private function isReadable( array $record, ?string $constant ): bool {
		if ( null !== $constant ) {
			return true;
		}

		if ( '' === self::string( $record, 'key' ) ) {
			return true;
		}

		return null !== $this->decrypt( $record );
	}

	/**
	 * Decrypt a stored key.
	 *
	 * A null here means the ciphertext is unreadable — most often because
	 * the site's salts were rotated. Treating that as "not configured"
	 * prompts for a new key, which is the only thing that can actually
	 * fix it; `isReadable()` exists so the screen can say which of the two
	 * it is looking at.
	 *
	 * @param array<string, mixed> $record Stored record.
	 * @return string|null
	 */
	private function decrypt( array $record ): ?string {
		$stored = self::string( $record, 'key' );

		if ( '' === $stored ) {
			return null;
		}

		$plaintext = $this->encryptor->decrypt( $stored );

		return null === $plaintext || '' === $plaintext ? null : $plaintext;
	}

	/**
	 * A key defined in wp-config.php, if any.
	 *
	 * @param string $provider Provider identifier.
	 * @return string|null
	 */
	private static function fromConstant( string $provider ): ?string {
		$name = 'HIVECLERK_' . strtoupper( $provider ) . '_KEY';

		if ( ! defined( $name ) ) {
			return null;
		}

		$value = constant( $name );

		return is_string( $value ) && '' !== trim( $value ) ? trim( $value ) : null;
	}

	/**
	 * All stored records.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function all(): array {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$stored = get_option( self::OPTION, array() );
		$clean  = array();

		if ( is_array( $stored ) ) {
			foreach ( $stored as $provider => $record ) {
				if ( is_string( $provider ) && is_array( $record ) ) {
					$clean[ $provider ] = $record;
				}
			}
		}

		$this->cache = $clean;

		return $this->cache;
	}

	/**
	 * Persist all records.
	 *
	 * @param array<string, array<string, mixed>> $all Records.
	 * @return void
	 */
	private function save( array $all ): void {
		$this->cache = $all;

		// Autoload off: this option is read when a completion is made or
		// the settings screen is opened, never on a front-end page load.
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Read a string field from a record.
	 *
	 * @param array<string, mixed> $record Record.
	 * @param string               $key    Field.
	 * @return string
	 */
	private static function string( array $record, string $key ): string {
		$value = $record[ $key ] ?? '';

		return is_string( $value ) ? $value : '';
	}
}
