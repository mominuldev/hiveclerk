<?php
/**
 * Message rendering.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Email\Services;

use Hiveclerk\Domain\Email\EmailMessage;
use Hiveclerk\Domain\Email\EmailSequence;
use Hiveclerk\Domain\Email\Enrollment;
use Hiveclerk\Domain\Email\SequenceStep;
use Hiveclerk\Domain\Lead\Lead;

/**
 * Turns a step plus a lead into a message that can be sent.
 *
 * ## The body is filtered on the way out, not on the way in
 *
 * A step's HTML is stored as the operator wrote it — `wp_kses` at save
 * time would silently delete a table or a style attribute they had a
 * reason for, and they would find out weeks later from a recipient. It is
 * filtered here instead, at the point it becomes an email, against the
 * set of tags mail clients actually render.
 *
 * ## Why List-Unsubscribe carries two values
 *
 * `mailto:` is what the old RFC specifies and what several clients still
 * use. The `https:` form plus `List-Unsubscribe-Post` is what Gmail and
 * Yahoo require from bulk senders as of 2024 — without it, mail from a
 * site sending any volume goes to spam regardless of content. Both are
 * sent because there is no single header that satisfies both worlds.
 */
final class EmailRenderer {

	/**
	 * HTML a mail client will actually render.
	 *
	 * Deliberately narrow. Script and form tags are stripped by every mail
	 * client anyway; leaving them out here means a compromised admin
	 * account cannot use the email builder to store a payload that renders
	 * in the *preview* screen, which is a browser.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowedHtml(): array {
		return array(
			'a'          => array(
				'href'   => true,
				'title'  => true,
				'target' => true,
				'style'  => true,
			),
			'p'          => array( 'style' => true ),
			'br'         => array(),
			'strong'     => array(),
			'b'          => array(),
			'em'         => array(),
			'i'          => array(),
			'u'          => array(),
			'ul'         => array( 'style' => true ),
			'ol'         => array( 'style' => true ),
			'li'         => array( 'style' => true ),
			'h1'         => array( 'style' => true ),
			'h2'         => array( 'style' => true ),
			'h3'         => array( 'style' => true ),
			'h4'         => array( 'style' => true ),
			'blockquote' => array( 'style' => true ),
			'hr'         => array( 'style' => true ),
			'div'        => array( 'style' => true ),
			'span'       => array( 'style' => true ),
			'table'      => array(
				'style'       => true,
				'width'       => true,
				'cellpadding' => true,
				'cellspacing' => true,
				'border'      => true,
			),
			'thead'      => array(),
			'tbody'      => array(),
			'tr'         => array( 'style' => true ),
			'td'         => array(
				'style'   => true,
				'width'   => true,
				'align'   => true,
				'valign'  => true,
				'colspan' => true,
			),
			'th'         => array(
				'style' => true,
				'width' => true,
				'align' => true,
			),
			'img'        => array(
				'src'    => true,
				'alt'    => true,
				'width'  => true,
				'height' => true,
				'style'  => true,
			),
		);
	}

	/**
	 * Construct.
	 *
	 * @param MergeTags         $tags   Tag rendering.
	 * @param UnsubscribeTokens $tokens Unsubscribe links.
	 */
	public function __construct(
		private readonly MergeTags $tags,
		private readonly UnsubscribeTokens $tokens
	) {
	}

	/**
	 * Render one step for one lead.
	 *
	 * @param SequenceStep    $step       The email.
	 * @param EmailSequence   $sequence   Its sequence, for sender details.
	 * @param Lead            $lead       Recipient.
	 * @param Enrollment|null $enrollment Enrolment, for the log.
	 * @return EmailMessage|null Null when the lead has no address.
	 */
	public function render(
		SequenceStep $step,
		EmailSequence $sequence,
		Lead $lead,
		?Enrollment $enrollment = null
	): ?EmailMessage {
		if ( null === $lead->email ) {
			return null;
		}

		$unsubscribe = $this->tokens->url( $lead->email );

		// The subject is not HTML and must not be escaped as if it were —
		// a company called "Smith & Sons" would arrive as "Smith &amp;
		// Sons" in every inbox that received it.
		$subject = $this->tags->render( $step->subject, $lead, $unsubscribe, false );

		$body = $this->tags->render( $step->bodyHtml, $lead, $unsubscribe, true );
		$html = wp_kses( $body, self::allowedHtml() );

		$text = null === $step->bodyText || '' === trim( $step->bodyText )
			? wp_strip_all_tags( $html )
			: $this->tags->render( $step->bodyText, $lead, $unsubscribe, false );

		return new EmailMessage(
			to: $lead->email,
			subject: $subject,
			html: $this->withFooter( $html, $unsubscribe ),
			text: $text . ( null === $unsubscribe ? '' : "\n\n" . __( 'Unsubscribe:', 'hiveclerk' ) . ' ' . $unsubscribe ),
			headers: $this->headers( $unsubscribe ),
			fromName: $sequence->fromName,
			fromEmail: $sequence->fromEmail,
			replyTo: $sequence->replyTo,
			leadId: $lead->id,
			enrollmentId: $enrollment?->id,
			stepId: $step->id
		);
	}

	/**
	 * The unsubscribe headers for one recipient.
	 *
	 * @param string|null $unsubscribe Unsubscribe URL.
	 * @return array<string, string>
	 */
	private function headers( ?string $unsubscribe ): array {
		if ( null === $unsubscribe ) {
			return array();
		}

		$mailto = get_option( 'admin_email' );

		$targets = array( '<' . $unsubscribe . '>' );

		if ( is_string( $mailto ) && '' !== $mailto ) {
			$targets[] = '<mailto:' . $mailto . '?subject=unsubscribe>';
		}

		return array(
			'List-Unsubscribe'      => implode( ', ', $targets ),
			'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
		);
	}

	/**
	 * Append the visible unsubscribe line.
	 *
	 * The header alone is not enough. It is honoured by two mail clients
	 * and invisible in the rest, and a commercial email with no visible
	 * way out is unlawful in most of the jurisdictions this ships to.
	 * Appended by the renderer rather than left to the operator's
	 * template, because a template they wrote is a template they can
	 * forget.
	 *
	 * @param string      $html        Rendered body.
	 * @param string|null $unsubscribe Unsubscribe URL.
	 * @return string
	 */
	private function withFooter( string $html, ?string $unsubscribe ): string {
		if ( null === $unsubscribe ) {
			return $html;
		}

		return $html . sprintf(
			'<p style="margin-top:24px;font-size:12px;color:#666"><a href="%s" style="color:#666">%s</a></p>',
			esc_url( $unsubscribe ),
			esc_html__( 'Unsubscribe from these emails', 'hiveclerk' )
		);
	}
}
