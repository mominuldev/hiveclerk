<?php
/**
 * Scoring rule kind.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Lead\Scoring;

/**
 * The four things a rule can look at (FR-LED-03).
 *
 * Four kinds rather than one general expression language. An operator
 * writing "budget over £5k is worth 25 points" should not have to learn
 * a syntax, and a syntax is a thing customers paste into from the
 * internet — at which point the product is evaluating strings nobody
 * reviewed on every conversation.
 */
enum RuleKind: string {

	case Field      = 'field';
	case Keyword    = 'keyword';
	case Page       = 'page';
	case Engagement = 'engagement';

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Field      => 'Field value',
			self::Keyword    => 'Keyword',
			self::Page       => 'Page visited',
			self::Engagement => 'Engagement',
		};
	}

	/**
	 * Parse a stored value.
	 *
	 * @param string|null $value Stored value.
	 * @return self|null
	 */
	public static function fromStorage( ?string $value ): ?self {
		return self::tryFrom( (string) $value );
	}
}
