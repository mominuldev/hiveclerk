<?php
/**
 * Field mapping.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Integration;

/**
 * Which Hiveclerk field lands in which field over there (FR-CRM-07).
 *
 * ## Why the map is stored our-key-first
 *
 * A lead has a fixed set of things worth sending and a CRM has an
 * unbounded set of places to put them. Keying the map by our side means
 * an operator who renames a custom field in HubSpot breaks one row of the
 * mapping screen; keying it the other way would leave orphaned entries
 * pointing at a source that no longer exists, and nothing to show the
 * operator that would explain what happened.
 *
 * Qualification answers use the `answer:` prefix rather than being
 * enumerated, because they are whatever the customer configured on their
 * clerk this morning.
 */
final readonly class FieldMap {

	public const ANSWER_PREFIX = 'answer:';

	/**
	 * Fields that exist on every lead.
	 *
	 * The order is the order the mapping screen renders, which is why
	 * email is first: it is the locked row at the top of D11 §8.
	 */
	public const SOURCES = array(
		'email',
		'first_name',
		'last_name',
		'phone',
		'company',
		'job_title',
		'website',
		'score',
		'band',
		'status',
		'stage',
		'source',
		'transcript',
	);

	/**
	 * Construct.
	 *
	 * @param array<string, string> $pairs Our field name => their field key.
	 */
	public function __construct( public array $pairs = array() ) {
	}

	/**
	 * Build from stored or submitted data, dropping anything unrecognised.
	 *
	 * A mapping row whose source is not a real lead field would produce a
	 * payload key holding nothing on every push, and a connector that
	 * rejects unknown fields would then fail every sync for a reason no
	 * screen explains.
	 *
	 * @param array<mixed> $raw Decoded JSON column or request body.
	 * @return self
	 */
	public static function fromArray( array $raw ): self {
		$pairs = array();

		foreach ( $raw as $source => $target ) {
			if ( ! is_string( $source ) || ! is_string( $target ) || '' === trim( $target ) ) {
				continue;
			}

			if ( ! self::isKnownSource( $source ) ) {
				continue;
			}

			$pairs[ $source ] = trim( $target );
		}

		return new self( $pairs );
	}

	/**
	 * Whether a source name addresses something a lead actually has.
	 *
	 * @param string $source Source name.
	 * @return bool
	 */
	public static function isKnownSource( string $source ): bool {
		if ( in_array( $source, self::SOURCES, true ) ) {
			return true;
		}

		return str_starts_with( $source, self::ANSWER_PREFIX )
			&& '' !== substr( $source, strlen( self::ANSWER_PREFIX ) );
	}

	/**
	 * The target field for one source, if it is mapped.
	 *
	 * @param string $source Source name.
	 * @return string|null
	 */
	public function target( string $source ): ?string {
		return $this->pairs[ $source ] ?? null;
	}

	/**
	 * A copy with one pair forced in.
	 *
	 * Used for the email row, which is locked: whatever the operator sent
	 * for it, the connector's own contact key wins.
	 *
	 * @param string $source Source name.
	 * @param string $target Target field key.
	 * @return self
	 */
	public function with( string $source, string $target ): self {
		return new self( array_merge( $this->pairs, array( $source => $target ) ) );
	}

	/**
	 * Whether anything is mapped.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		return array() === $this->pairs;
	}

	/**
	 * Storage form.
	 *
	 * @return array<string, string>
	 */
	public function toArray(): array {
		return $this->pairs;
	}
}
