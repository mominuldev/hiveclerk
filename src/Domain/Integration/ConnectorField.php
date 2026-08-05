<?php
/**
 * A field on the far side of a connector.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Integration;

/**
 * One destination field the mapping screen can point at.
 *
 * `locked` exists because email is not a choice. Every connector here
 * identifies a contact by address, so offering a dropdown that lets an
 * operator map email to "Company" produces a CRM full of contacts keyed
 * on nothing. D11 §8 draws it as the word "locked" beside the row.
 */
final readonly class ConnectorField {

	/**
	 * Construct.
	 *
	 * @param string $key      Machine name, as the connector's API expects it.
	 * @param string $label    What the operator sees.
	 * @param string $type     text, email, phone, number, date, url or textarea.
	 * @param bool   $custom   Whether this is a custom field rather than a native one.
	 * @param bool   $locked   Whether the mapping is fixed and cannot be changed.
	 * @param bool   $required Whether the connector refuses a payload without it.
	 */
	public function __construct(
		public string $key,
		public string $label,
		public string $type = 'text',
		public bool $custom = false,
		public bool $locked = false,
		public bool $required = false
	) {
	}

	/**
	 * Wire form.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'key'      => $this->key,
			'label'    => $this->label,
			'type'     => $this->type,
			'custom'   => $this->custom,
			'locked'   => $this->locked,
			'required' => $this->required,
		);
	}
}
