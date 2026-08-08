<?php
/**
 * The data a run reasons about.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Workflow;

/**
 * A flat, read-only map of dotted keys to scalars.
 *
 * Flat rather than nested, and scalar rather than object: this is what a
 * condition reads and what the run log quotes back, and both need a value
 * that can be printed. An entity here would mean the log holding a lead
 * whose score has since changed, explaining a decision with a number that
 * was not the number used.
 *
 * Values arrive already redacted of anything the log should not carry.
 * The builder shows an email address because the operator can see the
 * lead anyway; nothing puts a token or a key in here.
 */
final readonly class WorkflowContext {

	/**
	 * Construct.
	 *
	 * @param array<string, mixed> $values Dotted keys to scalars.
	 */
	public function __construct( private array $values = array() ) {
	}

	/**
	 * A value, or null when it was never set.
	 *
	 * @param string $key Dotted key.
	 * @return mixed
	 */
	public function get( string $key ): mixed {
		return $this->values[ $key ] ?? null;
	}

	/**
	 * Whether a key holds something other than null or the empty string.
	 *
	 * @param string $key Dotted key.
	 * @return bool
	 */
	public function filled( string $key ): bool {
		$value = $this->get( $key );

		if ( null === $value ) {
			return false;
		}

		if ( is_string( $value ) ) {
			return '' !== trim( $value );
		}

		if ( is_array( $value ) ) {
			return array() !== $value;
		}

		return true;
	}

	/**
	 * A string value.
	 *
	 * @param string $key Dotted key.
	 * @return string|null
	 */
	public function string( string $key ): ?string {
		$value = $this->get( $key );

		if ( is_string( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}

		return null;
	}

	/**
	 * An integer value.
	 *
	 * @param string $key Dotted key.
	 * @return int|null
	 */
	public function int( string $key ): ?int {
		$value = $this->get( $key );

		return is_numeric( $value ) ? (int) $value : null;
	}

	/**
	 * A copy carrying additional values.
	 *
	 * @param array<string, mixed> $values Values to merge over this one.
	 * @return self
	 */
	public function with( array $values ): self {
		return new self( array_merge( $this->values, $values ) );
	}

	/**
	 * Everything, for storage and for the run log.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		return $this->values;
	}
}
