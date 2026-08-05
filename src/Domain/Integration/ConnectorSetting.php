<?php
/**
 * One field on a connect form.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Integration;

/**
 * What the connect dialog asks for, described by the connector itself.
 *
 * `secret` decides two things at once: the input is a password field, and
 * the value never comes back out of the API afterwards. Both follow from
 * the same fact, so they are one flag rather than two that can disagree.
 */
final readonly class ConnectorSetting {

	/**
	 * Construct.
	 *
	 * @param string $key         Field name inside the credential bag.
	 * @param string $label       Field label.
	 * @param string $type        text, url, password or select.
	 * @param bool   $secret      Whether the value is encrypted and never returned.
	 * @param bool   $required    Whether connecting without it is refused.
	 * @param string $help        One line under the field.
	 * @param string $placeholder Placeholder text.
	 */
	public function __construct(
		public string $key,
		public string $label,
		public string $type = 'text',
		public bool $secret = false,
		public bool $required = true,
		public string $help = '',
		public string $placeholder = ''
	) {
	}

	/**
	 * Wire form.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'key'         => $this->key,
			'label'       => $this->label,
			'type'        => $this->type,
			'secret'      => $this->secret,
			'required'    => $this->required,
			'help'        => $this->help,
			'placeholder' => $this->placeholder,
		);
	}
}
