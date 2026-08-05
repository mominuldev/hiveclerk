<?php
/**
 * A message about to be sent.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Email;

/**
 * One rendered email, ready for the mailer.
 *
 * Built by the renderer and handed to the sender. Merge tags are already
 * resolved and the body is already filtered — nothing downstream of this
 * object touches the copy, which is what makes "what exactly did we send"
 * answerable by looking at one place.
 */
final readonly class EmailMessage {

	/**
	 * Construct.
	 *
	 * @param string                $to             Recipient address.
	 * @param string                $subject        Subject line.
	 * @param string                $html           HTML body.
	 * @param string                $text           Plain-text alternative.
	 * @param array<string, string> $headers        Extra headers, including List-Unsubscribe.
	 * @param string|null           $fromName       Sender name.
	 * @param string|null           $fromEmail      Sender address.
	 * @param string|null           $replyTo        Reply-to address.
	 * @param int|null              $leadId         Lead this concerns.
	 * @param int|null              $enrollmentId   Enrolment this belongs to.
	 * @param int|null              $stepId         Step this renders.
	 */
	public function __construct(
		public string $to,
		public string $subject,
		public string $html,
		public string $text = '',
		public array $headers = array(),
		public ?string $fromName = null,
		public ?string $fromEmail = null,
		public ?string $replyTo = null,
		public ?int $leadId = null,
		public ?int $enrollmentId = null,
		public ?int $stepId = null
	) {
	}

	/**
	 * Headers in the `Name: value` form wp_mail() expects.
	 *
	 * @return array<int, string>
	 */
	public function headerLines(): array {
		$lines = array( 'Content-Type: text/html; charset=UTF-8' );

		if ( null !== $this->fromEmail && '' !== $this->fromEmail ) {
			$lines[] = null === $this->fromName || '' === $this->fromName
				? 'From: ' . $this->fromEmail
				: sprintf( 'From: %s <%s>', $this->fromName, $this->fromEmail );
		}

		if ( null !== $this->replyTo && '' !== $this->replyTo ) {
			$lines[] = 'Reply-To: ' . $this->replyTo;
		}

		foreach ( $this->headers as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}

		return $lines;
	}
}
