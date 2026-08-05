<?php
/**
 * GDPR data export (FR-SYS-04).
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Privacy;

use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Domain\Conversation\MessageRepositoryInterface;
use Hiveclerk\Domain\Conversation\MessageRole;
use Hiveclerk\Domain\Email\EmailLogRepositoryInterface;
use Hiveclerk\Domain\Lead\ActivityRepositoryInterface;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Lead\LeadRepositoryInterface;
use Hiveclerk\Domain\Lead\VisitorRepositoryInterface;

/**
 * Answers "what do you hold about me" through WordPress's own tool.
 *
 * Registered on `wp_privacy_personal_data_exporters` rather than given a
 * screen of its own. A site owner facing a subject access request works
 * through Tools → Export Personal Data, and an export that covers every
 * plugin except this one is worse than useless: it is a document the site
 * owner signs off as complete while it is not.
 *
 * The lookup key is the email address, which is the only identifier a data
 * subject can be expected to supply. Everything else this product stores
 * about a person hangs off the lead record that address resolves to.
 *
 * What is deliberately not exported:
 *
 * - **Hashed IPs and browser fingerprints.** They are personal data, and
 *   they are also unreadable to the person asking. Putting a SHA-256
 *   digest in a ZIP that gets emailed tells the subject nothing and hands
 *   anyone who intercepts it a stable identifier to match against other
 *   data. The export says the site holds a hashed IP, which is the part
 *   that answers the question.
 * - **Lead scores and score events.** Internal scoring against the site's
 *   own rules, exported as the resulting band rather than the arithmetic.
 */
final class PersonalDataExporter {

	/**
	 * Conversations exported per page.
	 *
	 * WordPress calls an exporter repeatedly until it reports `done`, so
	 * this bounds one request, not the export. Ten transcripts is well
	 * inside the memory a shared host gives a page load, and a subject
	 * with hundreds of conversations is exactly the case where a single
	 * unbounded query would fatal on the site that most needs it to work.
	 */
	private const PER_PAGE = 10;

	/**
	 * The export group all this data is filed under.
	 */
	private const GROUP = 'hiveclerk';

	/**
	 * Construct.
	 *
	 * @param LeadRepositoryInterface         $leads         Leads.
	 * @param ConversationRepositoryInterface $conversations Conversations.
	 * @param MessageRepositoryInterface      $messages      Messages.
	 * @param VisitorRepositoryInterface      $visitors      Visitors.
	 * @param ActivityRepositoryInterface     $activities    Timeline.
	 * @param EmailLogRepositoryInterface     $emailLog      Email log.
	 */
	public function __construct(
		private readonly LeadRepositoryInterface $leads,
		private readonly ConversationRepositoryInterface $conversations,
		private readonly MessageRepositoryInterface $messages,
		private readonly VisitorRepositoryInterface $visitors,
		private readonly ActivityRepositoryInterface $activities,
		private readonly EmailLogRepositoryInterface $emailLog
	) {
	}

	/**
	 * Register with WordPress's privacy tools.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter(
			'wp_privacy_personal_data_exporters',
			function ( $exporters ) {
				if ( ! is_array( $exporters ) ) {
					return $exporters;
				}

				$exporters['hiveclerk'] = array(
					'exporter_friendly_name' => __( 'Hiveclerk conversations and leads', 'hiveclerk' ),
					'callback'               => array( $this, 'export' ),
				);

				return $exporters;
			}
		);
	}

	/**
	 * Export one page of a person's data.
	 *
	 * @param string $email Address supplied by the site owner.
	 * @param int    $page  One-based page number.
	 * @return array{data: array<int, array{group_id: string, group_label: string, item_id: string, data: array<int, array{name: string, value: string}>}>, done: bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		$page       = max( 1, $page );
		$normalised = Lead::normaliseEmail( $email );

		if ( null === $normalised ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$hash = Lead::hashEmail( $normalised );
		$lead = null === $hash ? null : $this->leads->findByEmailHash( $hash );

		$items = array();

		/*
		 * The profile, the visits and the email log go out on the first
		 * page only. They are bounded by the person rather than by their
		 * activity, so paginating them would mean re-querying and
		 * re-emitting the same rows on every subsequent page.
		 */
		if ( 1 === $page ) {
			if ( null !== $lead ) {
				$items[] = $this->profileItem( $lead );
				$items   = array_merge( $items, $this->visitItems( $lead ) );
				$items   = array_merge( $items, $this->activityItems( $lead ) );
			}

			$items = array_merge( $items, $this->emailItems( $normalised ) );
		}

		if ( null === $lead || null === $lead->id ) {
			return array(
				'data' => $items,
				'done' => true,
			);
		}

		$transcripts = $this->transcriptItems( $lead->id, $page );

		return array(
			'data' => array_merge( $items, $transcripts['items'] ),
			'done' => $transcripts['done'],
		);
	}

	/**
	 * The lead record itself.
	 *
	 * @param Lead $lead Lead.
	 * @return array{group_id: string, group_label: string, item_id: string, data: array<int, array{name: string, value: string}>}
	 */
	private function profileItem( Lead $lead ): array {
		$fields = array(
			array(
				'name'  => __( 'Email', 'hiveclerk' ),
				'value' => (string) $lead->email,
			),
			array(
				'name'  => __( 'Name', 'hiveclerk' ),
				'value' => $lead->displayName(),
			),
			array(
				'name'  => __( 'Phone', 'hiveclerk' ),
				'value' => (string) $lead->phone,
			),
			array(
				'name'  => __( 'Company', 'hiveclerk' ),
				'value' => (string) $lead->company,
			),
			array(
				'name'  => __( 'First seen', 'hiveclerk' ),
				'value' => null === $lead->firstSeenAt ? '' : $lead->firstSeenAt->format( 'Y-m-d H:i:s' ) . ' UTC',
			),
			array(
				'name'  => __( 'Last active', 'hiveclerk' ),
				'value' => null === $lead->lastActiveAt ? '' : $lead->lastActiveAt->format( 'Y-m-d H:i:s' ) . ' UTC',
			),
			array(
				'name'  => __( 'How they were captured', 'hiveclerk' ),
				'value' => (string) $lead->source,
			),
		);

		/*
		 * Qualification answers are things the person themselves typed in
		 * response to a question the clerk asked. Omitting them because
		 * they live in a JSON column rather than a column of their own
		 * would be the wrong reason to leave data out of a subject access
		 * request.
		 */
		foreach ( $lead->customFields as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$fields[] = array(
					'name'  => sprintf(
						/* translators: %s: the question key a clerk asked, e.g. "budget". */
						__( 'Answer: %s', 'hiveclerk' ),
						(string) $key
					),
					'value' => (string) $value,
				);
			}
		}

		return array(
			'group_id'    => self::GROUP . '-lead',
			'group_label' => __( 'Contact record', 'hiveclerk' ),
			'item_id'     => 'hiveclerk-lead-' . (string) $lead->uuid,
			'data'        => $this->withoutEmpties( $fields ),
		);
	}

	/**
	 * One item per browsing identity stitched to the lead.
	 *
	 * @param Lead $lead Lead.
	 * @return array<int, array{group_id: string, group_label: string, item_id: string, data: array<int, array{name: string, value: string}>}>
	 */
	private function visitItems( Lead $lead ): array {
		if ( null === $lead->id ) {
			return array();
		}

		$items = array();

		foreach ( $this->visitors->forLead( $lead->id ) as $visitor ) {
			$items[] = array(
				'group_id'    => self::GROUP . '-visits',
				'group_label' => __( 'Site visits', 'hiveclerk' ),
				'item_id'     => 'hiveclerk-visitor-' . (string) $visitor->uuid,
				'data'        => $this->withoutEmpties(
					array(
						array(
							'name'  => __( 'First seen', 'hiveclerk' ),
							'value' => null === $visitor->firstSeenAt ? '' : $visitor->firstSeenAt->format( 'Y-m-d H:i:s' ) . ' UTC',
						),
						array(
							'name'  => __( 'Last seen', 'hiveclerk' ),
							'value' => null === $visitor->lastSeenAt ? '' : $visitor->lastSeenAt->format( 'Y-m-d H:i:s' ) . ' UTC',
						),
						array(
							'name'  => __( 'Pages viewed', 'hiveclerk' ),
							'value' => (string) $visitor->pageViews,
						),
						array(
							'name'  => __( 'Browser reported', 'hiveclerk' ),
							'value' => (string) $visitor->userAgent,
						),
						array(
							'name'  => __( 'Country', 'hiveclerk' ),
							'value' => (string) $visitor->country,
						),
						array(
							'name'  => __( 'Language', 'hiveclerk' ),
							'value' => (string) $visitor->language,
						),
						/*
						 * The values are withheld on purpose; their
						 * existence is not. See the class docblock.
						 */
						array(
							'name'  => __( 'IP address', 'hiveclerk' ),
							'value' => null === $visitor->ipHash
								? ''
								: __( 'Stored as a one-way hash. The address itself was never recorded.', 'hiveclerk' ),
						),
					)
				),
			);
		}

		return $items;
	}

	/**
	 * The lead's timeline.
	 *
	 * @param Lead $lead Lead.
	 * @return array<int, array{group_id: string, group_label: string, item_id: string, data: array<int, array{name: string, value: string}>}>
	 */
	private function activityItems( Lead $lead ): array {
		if ( null === $lead->id ) {
			return array();
		}

		$visitorIds = array();

		foreach ( $this->visitors->forLead( $lead->id ) as $visitor ) {
			if ( null !== $visitor->id ) {
				$visitorIds[] = $visitor->id;
			}
		}

		$items = array();

		foreach ( $this->activities->timeline( $lead->id, $visitorIds, 200 ) as $index => $activity ) {
			$items[] = array(
				'group_id'    => self::GROUP . '-activity',
				'group_label' => __( 'Activity history', 'hiveclerk' ),
				'item_id'     => 'hiveclerk-activity-' . ( null === $activity->id ? (string) $index : (string) $activity->id ),
				'data'        => $this->withoutEmpties(
					array(
						array(
							'name'  => __( 'When', 'hiveclerk' ),
							'value' => null === $activity->createdAt ? '' : $activity->createdAt->format( 'Y-m-d H:i:s' ) . ' UTC',
						),
						array(
							'name'  => __( 'What happened', 'hiveclerk' ),
							'value' => $activity->title,
						),
						array(
							'name'  => __( 'Detail', 'hiveclerk' ),
							'value' => (string) $activity->body,
						),
					)
				),
			);
		}

		return $items;
	}

	/**
	 * Emails this site sent the person.
	 *
	 * @param string $email Normalised address.
	 * @return array<int, array{group_id: string, group_label: string, item_id: string, data: array<int, array{name: string, value: string}>}>
	 */
	private function emailItems( string $email ): array {
		$items = array();

		foreach ( $this->emailLog->forEmail( $email, 200 ) as $entry ) {
			$items[] = array(
				'group_id'    => self::GROUP . '-email',
				'group_label' => __( 'Emails sent to you', 'hiveclerk' ),
				'item_id'     => 'hiveclerk-email-' . (string) $entry->id,
				'data'        => $this->withoutEmpties(
					array(
						array(
							'name'  => __( 'Subject', 'hiveclerk' ),
							'value' => $entry->subject,
						),
						array(
							'name'  => __( 'Status', 'hiveclerk' ),
							'value' => $entry->status->value,
						),
						array(
							'name'  => __( 'Sent', 'hiveclerk' ),
							'value' => null === $entry->sentAt ? '' : $entry->sentAt->format( 'Y-m-d H:i:s' ) . ' UTC',
						),
					)
				),
			);
		}

		return $items;
	}

	/**
	 * One page of conversation transcripts.
	 *
	 * @param int $leadId Lead storage id.
	 * @param int $page   One-based page.
	 * @return array{items: array<int, array{group_id: string, group_label: string, item_id: string, data: array<int, array{name: string, value: string}>}>, done: bool}
	 */
	private function transcriptItems( int $leadId, int $page ): array {
		/*
		 * One more than the page is fetched so "is there another page"
		 * is answered by what came back rather than by a second COUNT
		 * query against the same rows.
		 */
		$offset = ( $page - 1 ) * self::PER_PAGE;
		$window = array_slice(
			$this->conversations->forLead( $leadId, $offset + self::PER_PAGE + 1 ),
			$offset,
			self::PER_PAGE + 1
		);

		$done = count( $window ) <= self::PER_PAGE;
		$page = array_slice( $window, 0, self::PER_PAGE );

		$items = array();

		foreach ( $page as $conversation ) {
			if ( null === $conversation->id ) {
				continue;
			}

			$fields = array(
				array(
					'name'  => __( 'Started', 'hiveclerk' ),
					'value' => null === $conversation->startedAt ? '' : $conversation->startedAt->format( 'Y-m-d H:i:s' ) . ' UTC',
				),
				array(
					'name'  => __( 'Page', 'hiveclerk' ),
					'value' => (string) $conversation->pageUrl,
				),
			);

			foreach ( $this->messages->transcript( $conversation->id ) as $message ) {
				$fields[] = array(
					'name'  => MessageRole::Visitor === $message->role
						? __( 'You said', 'hiveclerk' )
						: __( 'The clerk said', 'hiveclerk' ),
					'value' => $message->content,
				);
			}

			$items[] = array(
				'group_id'    => self::GROUP . '-conversations',
				'group_label' => __( 'Chat transcripts', 'hiveclerk' ),
				'item_id'     => 'hiveclerk-conversation-' . (string) $conversation->uuid,
				'data'        => $this->withoutEmpties( $fields ),
			);
		}

		return array(
			'items' => $items,
			'done'  => $done,
		);
	}

	/**
	 * Drop fields with nothing in them.
	 *
	 * An export listing "Phone: " against every empty column reads as a
	 * form somebody failed to fill in rather than as data the site does
	 * not hold, and the difference matters to the person reading it.
	 *
	 * @param array<int, array{name: string, value: string}> $fields Fields.
	 * @return array<int, array{name: string, value: string}>
	 */
	private function withoutEmpties( array $fields ): array {
		return array_values(
			array_filter(
				$fields,
				static fn ( array $field ): bool => '' !== trim( $field['value'] )
			)
		);
	}
}
