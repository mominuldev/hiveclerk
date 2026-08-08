<?php
/**
 * What a run is about.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Workflow;

/**
 * The kind of record a run follows.
 *
 * A run always has a subject, even the scheduled ones: a workflow that
 * runs every morning over a segment opens one run per lead, not one run
 * that loops. That costs a row per lead and buys the thing that matters —
 * a run that fails on lead 400 does not take leads 401 to 900 with it,
 * and the run log can say which lead each decision was about.
 */
enum SubjectType: string {

	case Lead         = 'lead';
	case Conversation = 'conversation';

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Lead         => 'Lead',
			self::Conversation => 'Conversation',
		};
	}

	/**
	 * Read a stored value.
	 *
	 * @param string|null $value Stored value.
	 * @return self
	 */
	public static function fromStorage( ?string $value ): self {
		if ( null === $value ) {
			return self::Lead;
		}

		return self::tryFrom( $value ) ?? self::Lead;
	}
}
