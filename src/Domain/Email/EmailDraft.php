<?php
/**
 * AI-drafted copy.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Email;

/**
 * What a model proposed for one step (FR-EML-03).
 *
 * A draft, never a step. Nothing here is stored until an operator saves
 * it, and once saved it carries `aiGenerated` and cannot send until a
 * person has approved it. The two-stage shape is the point: generation is
 * cheap and reversible, sending under the customer's name to a real
 * person is neither.
 */
final readonly class EmailDraft {

	/**
	 * Construct.
	 *
	 * @param string $subject  Proposed subject line.
	 * @param string $bodyHtml Proposed HTML body.
	 * @param string $bodyText Proposed plain-text alternative.
	 * @param string $goal     What the operator asked for.
	 */
	public function __construct(
		public string $subject,
		public string $bodyHtml,
		public string $bodyText = '',
		public string $goal = ''
	) {
	}

	/**
	 * Whether the model produced anything usable.
	 *
	 * @return bool
	 */
	public function isUsable(): bool {
		return '' !== trim( $this->subject ) && '' !== trim( $this->bodyHtml );
	}

	/**
	 * Wire form.
	 *
	 * @return array<string, string>
	 */
	public function toArray(): array {
		return array(
			'subject'   => $this->subject,
			'body_html' => $this->bodyHtml,
			'body_text' => $this->bodyText,
			'goal'      => $this->goal,
		);
	}
}
