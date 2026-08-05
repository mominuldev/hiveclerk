<?php
/**
 * Contact details read out of a message.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Leads\Support;

/**
 * What one visitor message gave up about who is typing it.
 *
 * Every field is nullable and most of them usually are. This object
 * exists so the caller can ask "did anything change?" without inspecting
 * five separate return values, and so a message that mentioned only an
 * address does not overwrite a name captured three turns earlier.
 */
final readonly class ExtractedContact {

	/**
	 * Construct.
	 *
	 * @param string|null $email     Address.
	 * @param string|null $phone     Telephone, as typed.
	 * @param string|null $firstName Given name.
	 * @param string|null $lastName  Family name.
	 * @param string|null $company   Organisation.
	 * @param string|null $website   Site.
	 */
	public function __construct(
		public ?string $email = null,
		public ?string $phone = null,
		public ?string $firstName = null,
		public ?string $lastName = null,
		public ?string $company = null,
		public ?string $website = null,
	) {
	}

	/**
	 * Whether anything at all was found.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		return null === $this->email
			&& null === $this->phone
			&& null === $this->firstName
			&& null === $this->lastName
			&& null === $this->company
			&& null === $this->website;
	}

	/**
	 * The fields found, keyed by the Lead property they fill.
	 *
	 * @return array<string, string>
	 */
	public function fields(): array {
		$fields = array(
			'firstName' => $this->firstName,
			'lastName'  => $this->lastName,
			'phone'     => $this->phone,
			'company'   => $this->company,
			'website'   => $this->website,
		);

		return array_filter(
			$fields,
			static fn ( ?string $value ): bool => null !== $value && '' !== $value
		);
	}

	/**
	 * A copy with the fields of another merged in where this one is blank.
	 *
	 * Earlier wins. A conversation is scanned turn by turn and the first
	 * time somebody says their name is the time they meant it — a later
	 * message quoting a colleague's address should not replace it.
	 *
	 * @param self $other Later extraction.
	 * @return self
	 */
	public function mergedWith( self $other ): self {
		return new self(
			email: $this->email ?? $other->email,
			phone: $this->phone ?? $other->phone,
			firstName: $this->firstName ?? $other->firstName,
			lastName: $this->lastName ?? $other->lastName,
			company: $this->company ?? $other->company,
			website: $this->website ?? $other->website,
		);
	}
}
