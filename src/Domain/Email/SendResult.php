<?php
/**
 * Send outcome.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Email;

/**
 * What happened when a message was handed to the mailer.
 *
 * `sent` never means delivered — see `SendStatus`. It means `wp_mail()`
 * returned true, which is the furthest this plugin can see.
 */
final readonly class SendResult {

	/**
	 * Construct.
	 *
	 * @param SendStatus  $status What happened.
	 * @param string|null $error  Why not, when it did not.
	 */
	public function __construct(
		public SendStatus $status,
		public ?string $error = null
	) {
	}

	/**
	 * Handed over successfully.
	 *
	 * @return self
	 */
	public static function sent(): self {
		return new self( SendStatus::Sent );
	}

	/**
	 * Deliberately not sent.
	 *
	 * @param string $reason Why.
	 * @return self
	 */
	public static function suppressed( string $reason ): self {
		return new self( SendStatus::Suppressed, $reason );
	}

	/**
	 * The mailer refused it.
	 *
	 * @param string $error Why.
	 * @return self
	 */
	public static function failed( string $error ): self {
		return new self( SendStatus::Failed, $error );
	}

	/**
	 * Whether the mailer took it.
	 *
	 * @return bool
	 */
	public function ok(): bool {
		return SendStatus::Sent === $this->status;
	}
}
