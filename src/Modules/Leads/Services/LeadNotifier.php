<?php
/**
 * Telling staff about a lead worth their time.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Leads\Services;

use Hiveclerk\Domain\Lead\Activity;
use Hiveclerk\Domain\Lead\ActivityRepositoryInterface;
use Hiveclerk\Domain\Lead\ActivityType;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Infrastructure\Http\OutboundUrlGuard;

/**
 * Threshold notifications by email and Slack (FR-LED-09).
 *
 * ## Sent once, ever
 *
 * A lead's score moves several times in one conversation — an address,
 * then a page rule, then the model's adjustment — and each of those
 * crosses the threshold from the point of view of the write that did it.
 * Four emails about one person is how a sales team learns to filter this
 * sender into a folder, at which point the feature has negative value.
 *
 * The record of having sent it is a timeline row rather than a column,
 * so it also answers "did anyone get told about this?" on the screen
 * where somebody asks.
 */
final class LeadNotifier {

	/**
	 * Seconds to wait for Slack.
	 *
	 * Short. This runs inside a background job, but a webhook host that
	 * hangs would still hold a worker, and a missed Slack message is a
	 * smaller problem than a stuck queue.
	 */
	private const SLACK_TIMEOUT = 5;

	/**
	 * Construct.
	 *
	 * @param ScoringPolicy               $policy     Alert settings.
	 * @param ActivityRepositoryInterface $activities Timeline.
	 * @param OutboundUrlGuard            $guard      Private-network check.
	 */
	public function __construct(
		private readonly ScoringPolicy $policy,
		private readonly ActivityRepositoryInterface $activities,
		private readonly OutboundUrlGuard $guard
	) {
	}

	/**
	 * Notify if this lead has just become worth notifying about.
	 *
	 * @param Lead $lead The lead.
	 * @return bool Whether anything was sent.
	 */
	public function onScoreChanged( Lead $lead ): bool {
		$alerts = $this->policy->alerts();

		if ( ! $alerts['enabled'] || null === $lead->id || $lead->score < $alerts['score'] ) {
			return false;
		}

		if ( $this->activities->hasType( $lead->id, ActivityType::NotificationSent ) ) {
			return false;
		}

		$emailed = $this->email( $lead, $alerts['emails'] );
		$slacked = $this->slack( $lead, $alerts['slack_webhook'] );

		if ( ! $emailed && ! $slacked ) {
			return false;
		}

		$this->activities->record(
			new Activity(
				id: null,
				type: ActivityType::NotificationSent,
				title: __( 'Staff notified: lead crossed the alert threshold', 'hiveclerk' ),
				leadId: $lead->id,
				metadata: array(
					'score'   => $lead->score,
					'email'   => $emailed,
					'slack'   => $slacked,
					'channel' => $alerts['score'],
				),
			)
		);

		/**
		 * Fires after a threshold notification was attempted.
		 *
		 * @param Lead $lead    The lead.
		 * @param bool $emailed Whether wp_mail() accepted it.
		 * @param bool $slacked Whether Slack accepted it.
		 */
		do_action( 'hiveclerk/lead/notified', $lead, $emailed, $slacked );

		return true;
	}

	/**
	 * Email the staff list.
	 *
	 * Sent with `wp_mail()`, so it inherits whatever the site has
	 * configured for mail — including nothing. The result is reported on
	 * the action above rather than swallowed: "the email did not arrive"
	 * is the failure mode of every alerting feature ever shipped, and a
	 * site with no SMTP has to be able to find that out.
	 *
	 * @param Lead               $lead       The lead.
	 * @param array<int, string> $recipients Addresses.
	 * @return bool
	 */
	private function email( Lead $lead, array $recipients ): bool {
		if ( array() === $recipients ) {
			return false;
		}

		$lines = array(
			sprintf(
				/* translators: 1: lead name or email, 2: score. */
				__( '%1$s scored %2$d and is worth a look.', 'hiveclerk' ),
				$lead->displayName(),
				$lead->score
			),
			'',
		);

		if ( null !== $lead->email ) {
			$lines[] = sprintf(
				/* translators: %s: email address. */
				__( 'Email: %s', 'hiveclerk' ),
				$lead->email
			);
		}

		if ( null !== $lead->phone ) {
			$lines[] = sprintf(
				/* translators: %s: telephone number. */
				__( 'Phone: %s', 'hiveclerk' ),
				$lead->phone
			);
		}

		if ( null !== $lead->company ) {
			$lines[] = sprintf(
				/* translators: %s: company name. */
				__( 'Company: %s', 'hiveclerk' ),
				$lead->company
			);
		}

		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: link to the lead in the admin. */
			__( 'Open the lead: %s', 'hiveclerk' ),
			$this->link( $lead )
		);

		return wp_mail(
			$recipients,
			sprintf(
				/* translators: %s: lead name or email. */
				__( 'New qualified lead: %s', 'hiveclerk' ),
				$lead->displayName()
			),
			implode( "\n", $lines )
		);
	}

	/**
	 * Post to a Slack incoming webhook.
	 *
	 * The URL comes from a settings field, which makes it the same
	 * server-side request forgery primitive as a crawl target — a webhook
	 * pointed at `169.254.169.254` would have the server fetch its own
	 * cloud credentials on a schedule the attacker controls. It goes
	 * through the same guard the crawler uses.
	 *
	 * @param Lead        $lead    The lead.
	 * @param string|null $webhook Webhook URL.
	 * @return bool
	 */
	private function slack( Lead $lead, ?string $webhook ): bool {
		if ( null === $webhook || $this->guard->isBlocked( $webhook ) ) {
			return false;
		}

		$text = sprintf(
			/* translators: 1: lead name or email, 2: score, 3: band, 4: admin link. */
			__( '*%1$s* scored *%2$d* (%3$s) — %4$s', 'hiveclerk' ),
			$lead->displayName(),
			$lead->score,
			$lead->band->label(),
			$this->link( $lead )
		);

		$response = wp_safe_remote_post(
			$webhook,
			array(
				'timeout'     => self::SLACK_TIMEOUT,
				'sslverify'   => true,
				// Redirects are not followed. The guard above checks the URL
				// the operator typed and cannot check where that URL sends
				// us next, and WordPress's own per-hop check permits
				// link-local — so a "Slack webhook" pointing at a public host
				// that 302s to 169.254.169.254 would have this server post to
				// the cloud metadata endpoint on every qualified lead. Slack
				// does not redirect its webhook endpoint; nothing legitimate
				// is lost.
				'redirection' => 0,
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => (string) wp_json_encode( array( 'text' => $text ) ),
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return wp_remote_retrieve_response_code( $response ) < 300;
	}

	/**
	 * The admin URL for a lead.
	 *
	 * @param Lead $lead The lead.
	 * @return string
	 */
	private function link( Lead $lead ): string {
		return add_query_arg( array( 'page' => 'hiveclerk' ), admin_url( 'admin.php' ) )
			. '#/leads/' . $lead->uuid->value;
	}
}
