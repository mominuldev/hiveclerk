<?php
/**
 * Lead entity.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Lead;

use DateTimeImmutable;
use Hiveclerk\Domain\Shared\Uuid;

/**
 * A person the clerks have identified, and what is known about them.
 *
 * `score` is a materialised total, not a source of truth. The truth is
 * the append-only row set in `lead_scores`; this column exists so the
 * pipeline can sort ten thousand leads without summing an event log per
 * card. Anything that changes it goes through the scoring service, which
 * writes the event first.
 */
final class Lead {

	/**
	 * Construct.
	 *
	 * @param int|null               $id           Storage id, null before first save.
	 * @param Uuid                   $uuid         Public identifier.
	 * @param string|null            $email        Address, once known.
	 * @param string|null            $emailHash    Normalised hash carrying the unique index.
	 * @param string|null            $firstName    Given name.
	 * @param string|null            $lastName     Family name.
	 * @param string|null            $phone        Telephone, as given.
	 * @param string|null            $company      Organisation.
	 * @param string|null            $jobTitle     Role.
	 * @param string|null            $website      Site.
	 * @param int|null               $wpUserId     Matching WordPress account.
	 * @param int|null               $stageId      Pipeline column.
	 * @param int                    $score        Materialised total.
	 * @param ScoreBand              $band         Materialised band.
	 * @param LeadStatus             $status       Lifecycle state.
	 * @param string|null            $source       Which clerk or channel captured it.
	 * @param array<string, mixed>   $customFields Qualification answers.
	 * @param array<string, mixed>   $consent      Marketing consent record.
	 * @param int|null               $ownerUserId  Assigned staff member.
	 * @param DateTimeImmutable|null $firstSeenAt  First contact, UTC.
	 * @param DateTimeImmutable|null $lastActiveAt Most recent activity, UTC.
	 * @param DateTimeImmutable|null $convertedAt  When it converted, UTC.
	 * @param DateTimeImmutable|null $createdAt    Row creation, UTC.
	 * @param DateTimeImmutable|null $updatedAt    Last write, UTC.
	 */
	public function __construct(
		public ?int $id,
		public Uuid $uuid,
		public ?string $email = null,
		public ?string $emailHash = null,
		public ?string $firstName = null,
		public ?string $lastName = null,
		public ?string $phone = null,
		public ?string $company = null,
		public ?string $jobTitle = null,
		public ?string $website = null,
		public ?int $wpUserId = null,
		public ?int $stageId = null,
		public int $score = 0,
		public ScoreBand $band = ScoreBand::Cold,
		public LeadStatus $status = LeadStatus::New,
		public ?string $source = null,
		public array $customFields = array(),
		public array $consent = array(),
		public ?int $ownerUserId = null,
		public ?DateTimeImmutable $firstSeenAt = null,
		public ?DateTimeImmutable $lastActiveAt = null,
		public ?DateTimeImmutable $convertedAt = null,
		public ?DateTimeImmutable $createdAt = null,
		public ?DateTimeImmutable $updatedAt = null,
	) {
	}

	/**
	 * The name to put on a card, falling back to the address.
	 *
	 * Never "Unknown". A card labelled Unknown is one an operator skips,
	 * and a lead with an email and no name is still a lead worth calling.
	 *
	 * @return string
	 */
	public function displayName(): string {
		$name = trim( (string) $this->firstName . ' ' . (string) $this->lastName );

		if ( '' !== $name ) {
			return $name;
		}

		if ( null !== $this->email && '' !== $this->email ) {
			return $this->email;
		}

		if ( null !== $this->company && '' !== $this->company ) {
			return $this->company;
		}

		return 'Anonymous visitor';
	}

	/**
	 * Whether there is a way to contact this person.
	 *
	 * @return bool
	 */
	public function isContactable(): bool {
		return ( null !== $this->email && '' !== $this->email )
			|| ( null !== $this->phone && '' !== $this->phone );
	}

	/**
	 * Whether an email address has been resolved.
	 *
	 * @return bool
	 */
	public function isIdentified(): bool {
		return null !== $this->emailHash && '' !== $this->emailHash;
	}

	/**
	 * The stored form of an email address.
	 *
	 * Deliberately unsalted. The address itself sits in the next column,
	 * so a salt would protect nothing — and it would break the thing the
	 * hash exists for: a site whose WordPress salts are regenerated (a
	 * routine response to a suspected compromise, and something several
	 * security plugins do unprompted) would stop matching its own
	 * existing leads, and the unique index would start admitting the
	 * duplicates it was added to prevent.
	 *
	 * @param string $email Address in any casing.
	 * @return string|null Null when the address is not one.
	 */
	public static function hashEmail( string $email ): ?string {
		$normalised = self::normaliseEmail( $email );

		return null === $normalised ? null : hash( 'sha256', $normalised );
	}

	/**
	 * Lower-cased, trimmed address, or null when it is not an address.
	 *
	 * Validation lives here rather than at the boundary because the
	 * boundary is not the only caller: an address extracted from the
	 * middle of a sentence has never been near a form field.
	 *
	 * @param string $email Candidate.
	 * @return string|null
	 */
	public static function normaliseEmail( string $email ): ?string {
		$trimmed = strtolower( trim( $email ) );

		if ( '' === $trimmed || false === filter_var( $trimmed, FILTER_VALIDATE_EMAIL ) ) {
			return null;
		}

		return $trimmed;
	}

	/**
	 * A qualification answer.
	 *
	 * @param string $key Question key.
	 * @return string|null
	 */
	public function answer( string $key ): ?string {
		$value = $this->customFields[ $key ] ?? null;

		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}

		return is_string( $value ) && '' !== trim( $value ) ? trim( $value ) : null;
	}

	/**
	 * Fill in a field only where nothing is known.
	 *
	 * Capture runs on every reply, so the same address arrives many times
	 * over one conversation. Later mentions must not overwrite what an
	 * operator has since corrected by hand — the visitor typed their name
	 * once and the person who spoke to them typed it properly.
	 *
	 * @param string $field Property name.
	 * @param string $value Candidate value.
	 * @return bool Whether anything changed.
	 */
	public function fillIfEmpty( string $field, string $value ): bool {
		$value = trim( $value );

		if ( '' === $value || ! property_exists( $this, $field ) ) {
			return false;
		}

		$current = $this->{$field};

		if ( is_string( $current ) && '' !== trim( $current ) ) {
			return false;
		}

		$this->{$field} = $value;

		return true;
	}
}
