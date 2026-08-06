<?php
/**
 * A lock without a database.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support;

use Hiveclerk\Core\Support\LockInterface;

/**
 * Exclusion within one process, which is all a unit test can observe.
 *
 * Whether the real lock excludes across processes is not something a
 * fake can answer, and pretending otherwise is how the previous
 * implementation passed its tests while five of sixteen concurrent
 * callers each believed they had won. That property is measured against a
 * real database instead; this exists so the callers' *behaviour* around a
 * lock — degrade when refused, always release — can be tested at all.
 */
final class InMemoryLock implements LockInterface {

	/**
	 * Names currently held.
	 *
	 * @var array<string, bool>
	 */
	public array $held = array();

	/**
	 * Every name ever acquired, in order.
	 *
	 * @var array<int, string>
	 */
	public array $acquired = array();

	/**
	 * When true, every acquire fails as though somebody else holds it.
	 *
	 * @var bool
	 */
	public bool $refuseEverything = false;

	public function acquire( string $name ): bool {
		if ( $this->refuseEverything || isset( $this->held[ $name ] ) ) {
			return false;
		}

		$this->held[ $name ] = true;
		$this->acquired[]    = $name;

		return true;
	}

	public function release( string $name ): void {
		unset( $this->held[ $name ] );
	}
}
