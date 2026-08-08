<?php
/**
 * Email the team.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Workflows\Actions;

use Hiveclerk\Domain\Workflow\ActionHandlerInterface;
use Hiveclerk\Domain\Workflow\ActionResult;
use Hiveclerk\Domain\Workflow\ActionType;
use Hiveclerk\Domain\Workflow\WorkflowContext;
use Hiveclerk\Modules\Workflows\Support\Placeholders;

/**
 * One internal email, to addresses the operator names (FR-WFL-03).
 *
 * Sent with `wp_mail()`, so it inherits whatever the site has configured
 * for mail — including nothing — and the result is reported rather than
 * swallowed. "The alert never arrived" is the failure mode of every
 * notification feature ever built, and a site with no SMTP has to be able
 * to find that out from the run log rather than from a lost deal.
 *
 * Deliberately not routed through the sequence machinery. This is staff
 * mail: there is no unsubscribe link, no suppression check and no
 * enrolment, because a colleague is not a marketing recipient and adding
 * their address to the suppression list the first time they hit
 * unsubscribe would silently switch off the site's own alerting.
 *
 * The recipient list is capped, and that cap is the whole reason this is
 * not a general "send an email" action: a workflow that could mail any
 * address on any trigger is a workflow that can be used to send mail on
 * somebody else's behalf.
 */
final class NotifyAdminAction implements ActionHandlerInterface {

	/**
	 * Most addresses one node may write to.
	 */
	public const MAX_RECIPIENTS = 5;

	/**
	 * Which action this handles.
	 *
	 * @return ActionType
	 */
	public function type(): ActionType {
		return ActionType::NotifyAdmin;
	}

	/**
	 * Send it.
	 *
	 * @param WorkflowContext      $context What the run knows.
	 * @param array<string, mixed> $config  Node configuration.
	 * @return ActionResult
	 */
	public function execute( WorkflowContext $context, array $config ): ActionResult {
		$recipients = $this->recipients( $config );

		if ( array() === $recipients ) {
			return ActionResult::failed(
				__( 'This step has nobody to write to.', 'hiveclerk' )
			);
		}

		$subject = Placeholders::fill(
			$this->text( $config, 'subject' ) ?? __( 'Workflow notification', 'hiveclerk' ),
			$context
		);

		$body = Placeholders::fill( $this->text( $config, 'message' ) ?? '', $context );

		$sent = wp_mail( $recipients, $subject, $body );

		if ( ! $sent ) {
			// A retry rather than a failure: `wp_mail()` returning false is
			// most often a transient relay problem, and the run coming back
			// in a few minutes costs nothing. Three refusals and the engine
			// gives up on its own.
			return ActionResult::retry(
				__( 'The site could not send mail. Trying again shortly.', 'hiveclerk' )
			);
		}

		return ActionResult::done(
			sprintf(
				/* translators: %d: number of recipients. */
				_n( 'Emailed %d person.', 'Emailed %d people.', count( $recipients ), 'hiveclerk' ),
				count( $recipients )
			)
		);
	}

	/**
	 * Whether the node is complete.
	 *
	 * @param array<string, mixed> $config Node configuration.
	 * @return string|null
	 */
	public function validate( array $config ): ?string {
		if ( array() === $this->recipients( $config ) ) {
			return __( 'Add at least one valid email address to notify.', 'hiveclerk' );
		}

		return null === $this->text( $config, 'subject' )
			? __( 'Give the notification a subject line.', 'hiveclerk' )
			: null;
	}

	/**
	 * What it would do.
	 *
	 * @param WorkflowContext      $context What the run knows.
	 * @param array<string, mixed> $config  Node configuration.
	 * @return string
	 */
	public function describe( WorkflowContext $context, array $config ): string {
		unset( $context );

		$recipients = $this->recipients( $config );

		return sprintf(
			/* translators: %s: comma-separated email addresses. */
			__( 'Email %s', 'hiveclerk' ),
			array() === $recipients
				? __( 'nobody — no address is set', 'hiveclerk' )
				: implode( ', ', $recipients )
		);
	}

	/**
	 * The validated recipient list.
	 *
	 * Falls back to the site's admin address when the field is left empty,
	 * because an alert with no recipient is the one configuration mistake
	 * that produces no symptom at all.
	 *
	 * @param array<string, mixed> $config Node configuration.
	 * @return array<int, string>
	 */
	private function recipients( array $config ): array {
		$raw = $config['recipients'] ?? null;

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			$admin = get_option( 'admin_email' );

			return is_string( $admin ) && is_email( $admin ) ? array( $admin ) : array();
		}

		$addresses = array();

		foreach ( explode( ',', $raw ) as $candidate ) {
			$address = sanitize_email( trim( $candidate ) );

			if ( '' !== $address && is_email( $address ) ) {
				$addresses[] = $address;
			}
		}

		return array_slice( array_values( array_unique( $addresses ) ), 0, self::MAX_RECIPIENTS );
	}

	/**
	 * A trimmed configuration string.
	 *
	 * @param array<string, mixed> $config Node configuration.
	 * @param string               $key    Key.
	 * @return string|null
	 */
	private function text( array $config, string $key ): ?string {
		$value = $config[ $key ] ?? null;

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		return trim( $value );
	}
}
