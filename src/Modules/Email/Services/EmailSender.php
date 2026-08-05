<?php
/**
 * Sending.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Email\Services;

use Hiveclerk\Core\Settings\SettingsRepository;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Email\EmailLogEntry;
use Hiveclerk\Domain\Email\EmailLogRepositoryInterface;
use Hiveclerk\Domain\Email\EmailMessage;
use Hiveclerk\Domain\Email\SendResult;
use Hiveclerk\Domain\Email\SendStatus;
use Hiveclerk\Domain\Lead\Activity;
use Hiveclerk\Domain\Lead\ActivityRepositoryInterface;
use Hiveclerk\Domain\Lead\ActivityType;

/**
 * Hands a message to `wp_mail()` and writes down what happened (FR-EML-05).
 *
 * ## Why wp_mail and not SMTP of our own
 *
 * Every serious WordPress site already has a mail solution — WP Mail SMTP,
 * Post SMTP, a host-level relay, SES through a plugin. All of them work by
 * filtering `wp_mail`. Shipping our own transport would bypass every one
 * of them, so a site with carefully configured DKIM would suddenly have
 * this plugin's mail failing SPF while everything else passed.
 *
 * ## The hourly ceiling
 *
 * Shared hosts cut off sites that send in bursts, and the cut-off is
 * usually the whole account rather than the plugin. A ceiling counted
 * from the log — not from a counter that can disagree with it — keeps a
 * sequence that enrolled two thousand leads at once from taking the
 * customer's transactional mail down with it. Work over the ceiling is
 * not dropped; it stays due and goes out on the next tick.
 */
final class EmailSender {

	/**
	 * Emails per hour, unless the site says otherwise.
	 *
	 * Two hundred is below every shared-host limit worth naming and above
	 * anything a lead-follow-up sequence on a normal site produces.
	 */
	public const DEFAULT_HOURLY_LIMIT = 200;

	/**
	 * Construct.
	 *
	 * @param EmailLogRepositoryInterface $log        Send log.
	 * @param SuppressionList             $suppression Do-not-email list.
	 * @param ActivityRepositoryInterface $activities Lead timeline.
	 * @param SettingsRepository          $settings   Site settings.
	 * @param ClockInterface              $clock      Clock.
	 */
	public function __construct(
		private readonly EmailLogRepositoryInterface $log,
		private readonly SuppressionList $suppression,
		private readonly ActivityRepositoryInterface $activities,
		private readonly SettingsRepository $settings,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * Send one message, or record why it was not sent.
	 *
	 * @param EmailMessage $message Rendered message.
	 * @return SendResult
	 */
	public function send( EmailMessage $message ): SendResult {
		if ( $this->suppression->blocks( $message->to ) ) {
			$this->record( $message, SendResult::suppressed( __( 'That address is on the suppression list.', 'hiveclerk' ) ) );

			return SendResult::suppressed( __( 'That address is on the suppression list.', 'hiveclerk' ) );
		}

		$sent = wp_mail(
			$message->to,
			$message->subject,
			$message->html,
			$message->headerLines()
		);

		$result = $sent
			? SendResult::sent()
			: SendResult::failed( __( 'wp_mail() refused the message. Check the site’s mail configuration.', 'hiveclerk' ) );

		$this->record( $message, $result );

		if ( $result->ok() && null !== $message->leadId ) {
			$this->activities->record(
				new Activity(
					id: null,
					type: ActivityType::EmailSent,
					title: sprintf(
						/* translators: %s: email subject. */
						__( 'Email sent: %s', 'hiveclerk' ),
						$message->subject
					),
					leadId: $message->leadId,
					subjectType: 'sequence_step',
					subjectId: $message->stepId,
					createdAt: $this->clock->now(),
				)
			);
		}

		/**
		 * Fires after a message was handed to the mailer.
		 *
		 * @param EmailMessage $message The message.
		 * @param SendResult   $result  What happened.
		 */
		do_action( 'hiveclerk/email/sent', $message, $result );

		return $result;
	}

	/**
	 * How many more emails may go out this hour.
	 *
	 * @return int
	 */
	public function remainingThisHour(): int {
		$since = $this->clock->now()->modify( '-1 hour' )->format( 'Y-m-d H:i:s' );

		return max( 0, $this->hourlyLimit() - $this->log->sentSince( $since ) );
	}

	/**
	 * The site's hourly ceiling.
	 *
	 * @return int
	 */
	public function hourlyLimit(): int {
		$configured = $this->settings->get( 'email.hourly_limit' );

		$limit = is_numeric( $configured ) && (int) $configured > 0
			? (int) $configured
			: self::DEFAULT_HOURLY_LIMIT;

		/**
		 * The most emails this site sends in an hour.
		 *
		 * @param int $limit Ceiling.
		 */
		$filtered = apply_filters( 'hiveclerk/email/hourly_limit', $limit );

		return is_numeric( $filtered ) && (int) $filtered > 0 ? (int) $filtered : $limit;
	}

	/**
	 * Write one row to the send log.
	 *
	 * @param EmailMessage $message Message.
	 * @param SendResult   $result  Outcome.
	 * @return void
	 */
	private function record( EmailMessage $message, SendResult $result ): void {
		if ( null === $message->leadId ) {
			return;
		}

		$this->log->append(
			new EmailLogEntry(
				id: null,
				leadId: $message->leadId,
				toEmail: $message->to,
				subject: $message->subject,
				status: $result->status,
				enrollmentId: $message->enrollmentId,
				stepId: $message->stepId,
				error: $result->error,
				sentAt: SendStatus::Sent === $result->status ? $this->clock->now() : null,
				createdAt: $this->clock->now(),
			)
		);
	}
}
