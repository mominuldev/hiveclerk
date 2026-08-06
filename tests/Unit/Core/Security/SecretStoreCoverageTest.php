<?php
/**
 * Every encrypted store is covered by rotation.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Core\Security;

use PHPUnit\Framework\TestCase;

/**
 * Fails when something new starts encrypting and rotation is not told.
 *
 * The failure this prevents is quiet and delayed. Add a store that calls
 * `Encryptor::encrypt()` and everything works: the value writes, reads back,
 * and behaves. It keeps working through a rotation, too, because the old key
 * is still readable during the window. It breaks at the moment the operator
 * closes the rotation — the one action framed as "you are now safe" — and it
 * breaks by reading as "not configured", which looks like the customer never
 * set it up rather than like the plugin destroyed it.
 *
 * Nothing else catches that. The rotator's own tests all pass, because they
 * only know about the stores it already walks.
 *
 * @internal
 */
final class SecretStoreCoverageTest extends TestCase {

	/**
	 * Files allowed to call `encrypt()`, each with the store it writes to.
	 *
	 * Adding a line here is a promise that `SecretRotator::pending()` finds
	 * that ciphertext. Adding one without doing so moves the failure from
	 * this test to a customer's install.
	 *
	 * @var array<string, string>
	 */
	private const ROTATED = array(
		'src/Core/Licence/LicenceService.php' => 'hiveclerk_licence_key option',
		'src/Ai/KeyResolver.php'              => 'hiveclerk_provider_keys option',
		'src/Modules/Integrations/Services/CredentialStore.php' => 'hvc_integrations.credentials column',
	);

	public function testEveryEncryptCallSiteIsAccountedForByRotation(): void {
		$found = $this->callSites();

		$unaccounted = array_diff( $found, array_keys( self::ROTATED ) );

		self::assertSame(
			array(),
			array_values( $unaccounted ),
			"A new store encrypts secrets but rotation does not know about it.\n"
			. "Teach SecretRotator::pending() to find it, then list it in this test.\n"
			. 'Unaccounted: ' . implode( ', ', $unaccounted )
		);
	}

	public function testTheListDoesNotNameAStoreThatStoppedEncrypting(): void {
		$found = $this->callSites();

		$stale = array_diff( array_keys( self::ROTATED ), $found );

		/*
		 * The other direction, and the reason this is two tests. A list that
		 * silently accumulates dead entries stops being evidence of anything
		 * — and a store removed from the code but left in the rotator makes
		 * the sweep look more thorough than it is.
		 */
		self::assertSame(
			array(),
			array_values( $stale ),
			'Listed as rotated but no longer encrypts: ' . implode( ', ', $stale )
		);
	}

	/**
	 * Source files containing a call to `encrypt()`.
	 *
	 * Read from the source rather than from a registry, because a registry
	 * is another thing somebody can forget to update — and forgetting it
	 * would make this test pass while the plugin was losing secrets.
	 *
	 * @return list<string> Repository-relative paths, sorted.
	 */
	private function callSites(): array {
		$root  = dirname( __DIR__, 4 );
		$found = array();

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $files as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}

			$path = $file->getPathname();

			// The cipher itself, and the rotator that rewrites what others
			// wrote, are not stores.
			if ( str_contains( $path, 'Support/Encryptor.php' ) || str_contains( $path, 'Security/SecretRotator.php' ) ) {
				continue;
			}

			$source = (string) file_get_contents( $path );

			if ( preg_match( '/->encrypt\s*\(/', $source ) === 1 ) {
				$found[] = str_replace( $root . '/', '', $path );
			}
		}

		sort( $found );

		return $found;
	}
}
