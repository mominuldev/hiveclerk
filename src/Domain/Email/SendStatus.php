<?php
/**
 * Send status.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Email;

/**
 * What happened to one email.
 *
 * `Sent` means `wp_mail()` accepted it and nothing more. It is not
 * "delivered" and the log never says delivered, because this plugin has
 * no way to know — the site's SMTP plugin, its transactional provider and
 * the recipient's mail server all sit between us and the truth. Claiming
 * delivery we cannot observe is how a log becomes something nobody
 * believes.
 *
 * `Suppressed` is separate from `Failed` for the same reason: an email we
 * deliberately did not send is not an error, and it must not turn a
 * sequence red on a screen where red means "something is broken".
 */
enum SendStatus: string {

	case Queued     = 'queued';
	case Sent       = 'sent';
	case Failed     = 'failed';
	case Suppressed = 'suppressed';

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Queued     => 'Queued',
			self::Sent       => 'Handed to the mailer',
			self::Failed     => 'Failed',
			self::Suppressed => 'Not sent',
		};
	}

	/**
	 * Parse a stored value.
	 *
	 * @param string|null $value Stored value.
	 * @return self
	 */
	public static function fromStorage( ?string $value ): self {
		return self::tryFrom( (string) $value ) ?? self::Queued;
	}
}
