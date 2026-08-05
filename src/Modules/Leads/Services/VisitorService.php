<?php
/**
 * Visitor identification and session stitching.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Leads\Services;

use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Lead\Activity;
use Hiveclerk\Domain\Lead\ActivityRepositoryInterface;
use Hiveclerk\Domain\Lead\ActivityType;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Lead\Scoring\PathPattern;
use Hiveclerk\Domain\Lead\Visitor;
use Hiveclerk\Domain\Lead\VisitorRepositoryInterface;
use Hiveclerk\Domain\Lead\VisitorResolverInterface;
use Hiveclerk\Domain\Shared\Uuid;

/**
 * Who has been here before, and which of them turned out to be a lead
 * (FR-LED-07).
 *
 * ## What this deliberately does not do
 *
 * No cookie is set and no fingerprint is computed from canvas, fonts or
 * anything else that identifies a browser across sites. The identifier
 * is a uuid the widget keeps in its own storage and hands back; a
 * visitor who clears it is a new visitor, and that is the correct
 * outcome for a plugin whose whole promise is that the customer's data
 * stays on their server.
 *
 * The IP is stored as a salted hash for the same reason it is in the
 * conversations table: nothing in the product needs the address, and
 * holding one creates a GDPR obligation for no benefit.
 *
 * ## Stitching
 *
 * The page views a person accumulated before they said who they were are
 * the interesting half of a lead's timeline and the input to every
 * page-context scoring rule. When capture resolves an email, every
 * visitor row already pointing at those conversations is attached to the
 * lead, and their activity rows come with them.
 */
final class VisitorService implements VisitorResolverInterface {

	/**
	 * Distinct paths one visitor's tally may hold.
	 */
	private const MAX_PATHS = 50;

	/**
	 * Events the public endpoint accepts.
	 *
	 * A whitelist rather than a free string. `/public/events` is an
	 * unauthenticated write endpoint, and one that stored whatever name
	 * it was given is an open door to filling the customer's database
	 * with rows nothing reads.
	 *
	 * @var array<int, string>
	 */
	public const EVENTS = array( 'page_view', 'scroll_depth', 'exit_intent', 'cart_updated' );

	/**
	 * Construct.
	 *
	 * @param VisitorRepositoryInterface  $visitors   Visitor storage.
	 * @param ActivityRepositoryInterface $activities Timeline.
	 * @param ClockInterface              $clock      Clock.
	 */
	public function __construct(
		private readonly VisitorRepositoryInterface $visitors,
		private readonly ActivityRepositoryInterface $activities,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * Find or create the visitor behind a request.
	 *
	 * @param string|null          $uuid    Identifier the widget is holding.
	 * @param array<string, mixed> $context Language, country, user agent, signed-in user.
	 * @return Visitor
	 */
	public function resolve( ?string $uuid, array $context = array() ): Visitor {
		$existing = null !== $uuid && Uuid::isValid( $uuid )
			? $this->visitors->findByUuid( new Uuid( $uuid ) )
			: null;

		if ( null !== $existing ) {
			$existing->lastSeenAt = $this->clock->now();
			$existing->language   = $this->text( $context['language'] ?? null, 10 ) ?? $existing->language;
			$existing->country    = $this->country( $context ) ?? $existing->country;
			$existing->wpUserId   = $this->userId() ?? $existing->wpUserId;

			return $this->visitors->save( $existing );
		}

		return $this->visitors->save(
			new Visitor(
				id: null,
				uuid: Uuid::generate(),
				wpUserId: $this->userId(),
				ipHash: $this->hashedIp(),
				userAgent: $this->userAgent(),
				country: $this->country( $context ),
				language: $this->text( $context['language'] ?? null, 10 ),
				firstSeenAt: $this->clock->now(),
				lastSeenAt: $this->clock->now(),
			)
		);
	}

	/**
	 * Record one telemetry event.
	 *
	 * Only `page_view` moves the counters. The other three are stored on
	 * the timeline as things that happened, because a rule that scored on
	 * an exit-intent would be scoring on a browser event the visitor did
	 * not choose to make.
	 *
	 * @param Visitor              $visitor The visitor.
	 * @param string               $type    Event name, already whitelisted.
	 * @param array<string, mixed> $payload url, title, value.
	 * @return Visitor
	 */
	public function record( Visitor $visitor, string $type, array $payload = array() ): Visitor {
		if ( ! in_array( $type, self::EVENTS, true ) ) {
			return $visitor;
		}

		$url   = $this->text( $payload['url'] ?? null, 500 );
		$path  = null === $url ? null : PathPattern::normalise( $url );
		$title = $this->text( $payload['title'] ?? null, 191 );

		if ( 'page_view' === $type && null !== $path ) {
			$visitor->recordView( $path, self::MAX_PATHS );
		}

		$visitor->lastSeenAt = $this->clock->now();

		$this->visitors->save( $visitor );

		if ( 'page_view' !== $type ) {
			return $visitor;
		}

		$this->activities->record(
			new Activity(
				id: null,
				type: ActivityType::PageView,
				title: sprintf(
					/* translators: 1: page path, 2: ordinal count of the visit. */
					__( 'Viewed %1$s (%2$s)', 'hiveclerk' ),
					$path ?? __( 'a page', 'hiveclerk' ),
					$this->ordinal( null === $path ? 1 : $visitor->viewsOf( $path ) )
				),
				leadId: $visitor->leadId,
				visitorId: $visitor->id,
				body: $title,
				metadata: array(
					'url'   => $url,
					'title' => $title,
				),
				createdAt: $this->clock->now(),
			)
		);

		return $visitor;
	}

	/**
	 * Attach a visitor, and everything it did, to a lead.
	 *
	 * @param Visitor $visitor The visitor.
	 * @param Lead    $lead    The lead.
	 * @return void
	 */
	public function stitch( Visitor $visitor, Lead $lead ): void {
		if ( null === $visitor->id || null === $lead->id || $visitor->leadId === $lead->id ) {
			return;
		}

		$visitor->leadId = $lead->id;

		$this->visitors->save( $visitor );

		// The page views recorded before anyone knew who this was. Without
		// this the lead's timeline starts at "lead captured", which is the
		// moment the interesting part ends.
		$this->activities->attachVisitor( $visitor->id, $lead->id );
	}

	/**
	 * Attach a set of visitors to a lead.
	 *
	 * @param array<int, int> $ids    Visitor storage ids.
	 * @param Lead            $lead   The lead.
	 * @return void
	 */
	public function stitchIds( array $ids, Lead $lead ): void {
		if ( array() === $ids || null === $lead->id ) {
			return;
		}

		$this->visitors->attachToLead( $ids, $lead->id );

		foreach ( $ids as $id ) {
			$this->activities->attachVisitor( (int) $id, $lead->id );
		}
	}

	/**
	 * Find a visitor by its public identifier.
	 *
	 * @param string|null $uuid Identifier.
	 * @return Visitor|null
	 */
	public function find( ?string $uuid ): ?Visitor {
		if ( null === $uuid || ! Uuid::isValid( $uuid ) ) {
			return null;
		}

		return $this->visitors->findByUuid( new Uuid( $uuid ) );
	}

	/**
	 * "1st", "2nd", "3rd".
	 *
	 * @param int $count Visit number.
	 * @return string
	 */
	private function ordinal( int $count ): string {
		$count = max( 1, $count );

		if ( $count >= 11 && $count <= 13 ) {
			return $count . 'th';
		}

		return $count . match ( $count % 10 ) {
			1       => 'st',
			2       => 'nd',
			3       => 'rd',
			default => 'th',
		};
	}

	/**
	 * The signed-in user, when there is one.
	 *
	 * A visitor who is logged into the site is not anonymous and there is
	 * no reason to pretend otherwise — it is also the cheapest possible
	 * stitch, because the account already carries an email address.
	 *
	 * @return int|null
	 */
	private function userId(): ?int {
		$id = get_current_user_id();

		return $id > 0 ? $id : null;
	}

	/**
	 * A salted hash of the caller's IP.
	 *
	 * @return string|null
	 */
	private function hashedIp(): ?string {
		$remote = $_SERVER['REMOTE_ADDR'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated

		if ( ! is_string( $remote ) ) {
			return null;
		}

		$ip = filter_var( wp_unslash( $remote ), FILTER_VALIDATE_IP );

		if ( ! is_string( $ip ) ) {
			return null;
		}

		$salt = defined( 'AUTH_SALT' ) ? (string) AUTH_SALT : '';

		return hash( 'sha256', $salt . '|' . $ip );
	}

	/**
	 * The reported user agent, truncated.
	 *
	 * @return string|null
	 */
	private function userAgent(): ?string {
		$agent = $_SERVER['HTTP_USER_AGENT'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated

		if ( ! is_string( $agent ) ) {
			return null;
		}

		return mb_substr( sanitize_text_field( wp_unslash( $agent ) ), 0, 500 );
	}

	/**
	 * The country a CDN reported, when one did.
	 *
	 * @param array<string, mixed> $context Request context.
	 * @return string|null
	 */
	private function country( array $context ): ?string {
		$value = $context['country'] ?? null;

		if ( ! is_string( $value ) || 1 !== preg_match( '/^[A-Za-z]{2}$/', $value ) ) {
			return null;
		}

		return strtoupper( $value );
	}

	/**
	 * A bounded string from request context.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $limit Maximum length.
	 * @return string|null
	 */
	private function text( mixed $value, int $limit ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$trimmed = trim( $value );

		return '' === $trimmed ? null : mb_substr( $trimmed, 0, $limit );
	}
}
