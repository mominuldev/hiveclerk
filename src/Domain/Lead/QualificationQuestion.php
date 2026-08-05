<?php
/**
 * A qualification question.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Lead;

/**
 * One thing a clerk is told to find out (FR-LED-02).
 *
 * The `key` is the contract. It is what the answer is stored under in
 * `custom_fields`, what a scoring rule addresses as `custom.budget`, and
 * what a CRM field mapping will bind to in Sprint 8. It is therefore
 * fixed at creation and never follows the wording of the question — an
 * operator rephrasing "What's your budget?" must not orphan every answer
 * already collected and every rule already written against it.
 */
final readonly class QualificationQuestion {

	/**
	 * Question types the widget and the prompt understand.
	 */
	public const TYPES = array( 'text', 'choice', 'number', 'email', 'phone' );

	/**
	 * Most questions one clerk may ask.
	 *
	 * A clerk with fifteen questions is an interrogation, and the
	 * conversation it produces is one visitors leave. Six is already
	 * more than any design partner asked for.
	 */
	public const MAX_QUESTIONS = 6;

	/**
	 * Construct.
	 *
	 * @param string             $key      Stable storage key.
	 * @param string             $question What the clerk asks, in its own words.
	 * @param string             $type     One of self::TYPES.
	 * @param array<int, string> $options  Choices, for a choice question.
	 * @param bool               $required Whether the clerk should press for an answer.
	 */
	public function __construct(
		public string $key,
		public string $question,
		public string $type = 'text',
		public array $options = array(),
		public bool $required = false,
	) {
	}

	/**
	 * Build from stored configuration, or null when it is not a question.
	 *
	 * @param array<string, mixed> $stored Stored question.
	 * @return self|null
	 */
	public static function fromArray( array $stored ): ?self {
		$key      = self::key( $stored['key'] ?? null );
		$question = is_string( $stored['question'] ?? null ) ? trim( (string) $stored['question'] ) : '';

		if ( '' === $key || '' === $question ) {
			return null;
		}

		$type = is_string( $stored['type'] ?? null ) ? strtolower( trim( (string) $stored['type'] ) ) : 'text';

		return new self(
			key: $key,
			question: substr( $question, 0, 300 ),
			type: in_array( $type, self::TYPES, true ) ? $type : 'text',
			options: self::options( $stored['options'] ?? null ),
			required: (bool) ( $stored['required'] ?? false ),
		);
	}

	/**
	 * Storage form.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'key'      => $this->key,
			'question' => $this->question,
			'type'     => $this->type,
			'options'  => $this->options,
			'required' => $this->required,
		);
	}

	/**
	 * How this question reads in a prompt.
	 *
	 * @return string
	 */
	public function describe(): string {
		$line = $this->question;

		if ( array() !== $this->options ) {
			$line .= ' (one of: ' . implode( ', ', $this->options ) . ')';
		}

		return $line;
	}

	/**
	 * Reduce a candidate key to the stable form.
	 *
	 * Lower-case, underscores, no leading digit. Deliberately not
	 * WordPress's sanitize_key(): this class is in the domain, and the
	 * key ends up inside a scoring rule target, not inside an option
	 * name.
	 *
	 * @param mixed $value Raw key.
	 * @return string
	 */
	public static function key( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$key = strtolower( trim( $value ) );
		$key = (string) preg_replace( '/[^a-z0-9_]+/', '_', $key );
		$key = trim( $key, '_' );

		if ( '' === $key || 1 === preg_match( '/^\d/', $key ) ) {
			$key = '' === $key ? '' : 'q_' . $key;
		}

		return substr( $key, 0, 40 );
	}

	/**
	 * Clean a choice list.
	 *
	 * @param mixed $value Raw options.
	 * @return array<int, string>
	 */
	private static function options( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$options = array();

		foreach ( $value as $option ) {
			if ( ! is_string( $option ) ) {
				continue;
			}

			$trimmed = trim( $option );

			if ( '' !== $trimmed ) {
				$options[] = substr( $trimmed, 0, 100 );
			}

			if ( count( $options ) >= 12 ) {
				break;
			}
		}

		return array_values( array_unique( $options ) );
	}
}
