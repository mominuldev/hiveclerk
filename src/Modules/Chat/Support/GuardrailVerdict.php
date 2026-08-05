<?php
/**
 * The outcome of a guardrail check.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Chat\Support;

/**
 * Allowed or not, what to say instead, and what to record.
 *
 * Blocking and flagging are separate outcomes on purpose. A blocked
 * message never reaches a provider and never costs anything; a flagged one
 * is answered normally but leaves a record. Collapsing the two would force
 * a choice between refusing legitimate questions and knowing nothing about
 * the ones that were probing.
 */
final class GuardrailVerdict {

	/**
	 * Construct.
	 *
	 * @param bool               $allowed     Whether the exchange may proceed.
	 * @param string             $replacement Visitor-facing text when blocked.
	 * @param array<int, string> $flags       Machine tags for the audit trail.
	 * @param string             $reason      Operator-facing explanation.
	 */
	private function __construct(
		public readonly bool $allowed,
		public readonly string $replacement = '',
		public readonly array $flags = array(),
		public readonly string $reason = ''
	) {
	}

	/**
	 * Proceed, optionally carrying flags worth recording.
	 *
	 * @param array<int, string> $flags Machine tags.
	 * @return self
	 */
	public static function allow( array $flags = array() ): self {
		return new self( true, '', $flags );
	}

	/**
	 * Stop, and say this instead.
	 *
	 * @param string             $replacement Visitor-facing text.
	 * @param string             $flag        Machine tag.
	 * @param string             $reason      Operator-facing explanation.
	 * @return self
	 */
	public static function block( string $replacement, string $flag, string $reason = '' ): self {
		return new self( false, $replacement, array( $flag ), $reason );
	}

	/**
	 * Whether anything at all was noted.
	 *
	 * @return bool
	 */
	public function hasFlags(): bool {
		return array() !== $this->flags;
	}
}
