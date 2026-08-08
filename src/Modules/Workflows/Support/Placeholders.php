<?php
/**
 * Context placeholders in operator-written text.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Workflows\Support;

use Hiveclerk\Domain\Workflow\WorkflowContext;

/**
 * Replaces `{lead.name}` and friends in notes and notification bodies.
 *
 * ## Only keys the context already holds
 *
 * A placeholder naming something that is not in the context is left
 * exactly as it was typed rather than replaced with an empty string. An
 * operator who mistypes `{lead.frist_name}` sees their typo in the note
 * and fixes it; a silent blank reads as "the lead has no name" and gets
 * reported as a data bug months later.
 *
 * ## Nothing here escapes anything
 *
 * Output escaping belongs at the point of output, and these strings end
 * up in three different contexts — a timeline note rendered as text, an
 * email body, a log line. Escaping here would double-encode two of them.
 */
final class Placeholders {

	/**
	 * The pattern a placeholder takes.
	 */
	private const PATTERN = '/\{([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)*)\}/i';

	/**
	 * Fill placeholders from the context.
	 *
	 * @param string          $text    Operator-written text.
	 * @param WorkflowContext $context What the run knows.
	 * @return string
	 */
	public static function fill( string $text, WorkflowContext $context ): string {
		$filled = preg_replace_callback(
			self::PATTERN,
			static function ( array $matches ) use ( $context ): string {
				$key   = strtolower( $matches[1] );
				$value = $context->get( $key );

				if ( null === $value || is_array( $value ) ) {
					return $matches[0];
				}

				if ( is_bool( $value ) ) {
					return $value ? 'yes' : 'no';
				}

				return (string) $value;
			},
			$text
		);

		return is_string( $filled ) ? $filled : $text;
	}

	/**
	 * The placeholders the builder offers, with what each resolves to.
	 *
	 * @return array<int, array{tag: string, key: string, description: string}>
	 */
	public static function available(): array {
		return array(
			array(
				'tag'         => '{lead.name}',
				'key'         => 'lead.name',
				'description' => __( 'The lead’s name, or their email address when there is no name.', 'hiveclerk' ),
			),
			array(
				'tag'         => '{lead.email}',
				'key'         => 'lead.email',
				'description' => __( 'The lead’s email address.', 'hiveclerk' ),
			),
			array(
				'tag'         => '{lead.company}',
				'key'         => 'lead.company',
				'description' => __( 'The company, when one was captured.', 'hiveclerk' ),
			),
			array(
				'tag'         => '{lead.score}',
				'key'         => 'lead.score',
				'description' => __( 'The score at the moment this step runs.', 'hiveclerk' ),
			),
			array(
				'tag'         => '{lead.stage}',
				'key'         => 'lead.stage',
				'description' => __( 'The pipeline stage the lead is in.', 'hiveclerk' ),
			),
			array(
				'tag'         => '{lead.source}',
				'key'         => 'lead.source',
				'description' => __( 'Which clerk or channel captured the lead.', 'hiveclerk' ),
			),
			array(
				'tag'         => '{workflow.name}',
				'key'         => 'workflow.name',
				'description' => __( 'The name of this workflow.', 'hiveclerk' ),
			),
		);
	}
}
