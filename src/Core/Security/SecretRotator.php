<?php
/**
 * Re-encrypting every stored secret under a new key.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Security;

use Hiveclerk\Ai\KeyResolver;
use Hiveclerk\Core\Licence\LicenceService;
use Hiveclerk\Core\Support\Encryptor;
use Hiveclerk\Domain\Integration\IntegrationRepositoryInterface;

/**
 * Moves stored secrets from the retiring key to the current one.
 *
 * The reason this exists: the encryption key is derived from a per-install
 * salt, so changing that salt makes every stored secret unreadable at the
 * moment it changes. Without a window where both keys decrypt, "rotate the
 * key" would mean "lose every provider key, every integration token and the
 * licence" — and it would do it silently, because an unreadable secret reads
 * as "not configured" everywhere in this plugin.
 *
 * So rotation is three steps, not one: open a window, rewrite everything
 * inside it, then close it. The old key material stops being useful only at
 * the third step, which is the one an operator has to actually reach.
 *
 * Bounded on purpose. An install with hundreds of integrations must not turn
 * a rotation into a request that dies half-way, leaving the operator unable
 * to tell which half moved.
 */
final class SecretRotator {

	/**
	 * How many secrets one sweep will rewrite.
	 *
	 * Each one is a decrypt, an encrypt and a write. Fifty is far below
	 * anything that threatens the ~20s job budget and high enough that a
	 * normal install finishes in a single pass.
	 */
	public const BATCH = 50;

	/**
	 * @param Encryptor                      $encryptor    Cipher.
	 * @param IntegrationRepositoryInterface $integrations Integration store.
	 */
	public function __construct(
		private readonly Encryptor $encryptor,
		private readonly IntegrationRepositoryInterface $integrations
	) {
	}

	/**
	 * Open the dual-key window.
	 *
	 * @return bool False when a rotation is already in progress.
	 */
	public function begin(): bool {
		return $this->encryptor->beginRotation();
	}

	/**
	 * Whether a rotation is currently open.
	 *
	 * @return bool
	 */
	public function isRotating(): bool {
		return $this->encryptor->isRotating();
	}

	/**
	 * Rewrite up to one batch of secrets under the current key.
	 *
	 * @param int $limit Maximum secrets to rewrite.
	 * @return array{rewritten: int, remaining: int, unreadable: int}
	 */
	public function sweep( int $limit = self::BATCH ): array {
		$budget     = max( 1, $limit );
		$rewritten  = 0;
		$unreadable = 0;

		foreach ( $this->pending() as $secret ) {
			if ( $rewritten >= $budget ) {
				break;
			}

			$plaintext = $this->encryptor->decrypt( $secret['ciphertext'] );

			/*
			 * Left exactly as it is, and counted.
			 *
			 * A secret that neither key can read is already lost — the salts
			 * changed under it, or the row is corrupt. Rewriting it is
			 * impossible and deleting it would destroy the only evidence
			 * that something was configured there. The operator needs to be
			 * told which one, so they can paste it in again.
			 */
			if ( null === $plaintext ) {
				++$unreadable;

				continue;
			}

			$secret['store']( $this->encryptor->encrypt( $plaintext ) );

			++$rewritten;
		}

		/*
		 * "Remaining" is what still *can* be rewritten, which is the same
		 * condition finish() blocks on. Counting everything still pending
		 * would include the unreadable ones, and an operator would watch a
		 * number that never reaches zero while the rotation was in fact
		 * ready to close.
		 */
		$remaining = 0;

		foreach ( $this->pending() as $secret ) {
			if ( null !== $this->encryptor->decrypt( $secret['ciphertext'] ) ) {
				++$remaining;
			}
		}

		return array(
			'rewritten'  => $rewritten,
			'remaining'  => $remaining,
			'unreadable' => $unreadable,
		);
	}

	/**
	 * Close the window, making the retired key material worthless.
	 *
	 * Refuses while anything is still readable only by the old key. That is
	 * the whole safety property: an operator cannot finish a rotation into a
	 * state where a secret they still need has just become undecryptable.
	 *
	 * @return bool False when secrets remain unrewritten.
	 */
	public function finish(): bool {
		foreach ( $this->pending() as $secret ) {
			if ( null !== $this->encryptor->decrypt( $secret['ciphertext'] ) ) {
				return false;
			}
		}

		$this->encryptor->finishRotation();

		return true;
	}

	/**
	 * Every stored secret not yet written under the current key.
	 *
	 * The single list of everything this plugin encrypts. A store that is
	 * added and not listed here keeps working right up until a rotation
	 * completes, and then loses its secret — which is why
	 * `SecretStoreCoverageTest` fails the build when a new `encrypt()` call
	 * site appears that this method does not account for.
	 *
	 * @return list<array{ciphertext: string, label: string, store: callable(string): void}>
	 */
	private function pending(): array {
		$pending = array();

		$licence = get_option( LicenceService::KEY_OPTION, '' );

		if ( is_string( $licence ) && '' !== $licence && ! $this->encryptor->isCurrent( $licence ) ) {
			$pending[] = array(
				'ciphertext' => $licence,
				'label'      => 'Licence key',
				'store'      => static function ( string $ciphertext ): void {
					update_option( LicenceService::KEY_OPTION, $ciphertext, false );
				},
			);
		}

		$providers = get_option( KeyResolver::OPTION, array() );

		if ( is_array( $providers ) ) {
			foreach ( $providers as $provider => $record ) {
				if ( ! is_array( $record ) || ! is_string( $record['key'] ?? null ) || '' === $record['key'] ) {
					continue;
				}

				if ( $this->encryptor->isCurrent( $record['key'] ) ) {
					continue;
				}

				$pending[] = array(
					'ciphertext' => $record['key'],
					'label'      => sprintf( 'Provider key: %s', (string) $provider ),
					'store'      => static function ( string $ciphertext ) use ( $provider ): void {
						$all = get_option( KeyResolver::OPTION, array() );

						if ( ! is_array( $all ) || ! is_array( $all[ $provider ] ?? null ) ) {
							return;
						}

						$all[ $provider ]['key'] = $ciphertext;

						update_option( KeyResolver::OPTION, $all, false );
					},
				);
			}
		}

		foreach ( $this->integrations->all() as $integration ) {
			$id = $integration->id;

			// An unsaved entity has no row and so no stored ciphertext.
			if ( null === $id ) {
				continue;
			}

			$secret = $this->integrations->secret( $id );

			if ( null === $secret || '' === $secret || $this->encryptor->isCurrent( $secret ) ) {
				continue;
			}

			$pending[] = array(
				'ciphertext' => $secret,
				'label'      => sprintf( 'Integration: %s', $integration->provider ),
				'store'      => function ( string $ciphertext ) use ( $id ): void {
					$this->integrations->storeSecret( $id, $ciphertext );
				},
			);
		}

		return $pending;
	}

	/**
	 * What is left to rewrite, for a screen to report.
	 *
	 * @return list<string>
	 */
	public function outstanding(): array {
		return array_map(
			static fn ( array $secret ): string => $secret['label'],
			$this->pending()
		);
	}
}
