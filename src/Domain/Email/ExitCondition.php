<?php
/**
 * Exit condition.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Email;

/**
 * What takes a lead back out of a sequence (FR-EML-04).
 *
 * ## Coming back to the clerk always exits, and it is not configurable
 *
 * A follow-up sequence that keeps sending after the person answered is
 * the single most damaging thing an email feature can do — it is visible
 * to the recipient, it is obviously automated, and it undoes the
 * conversation that just started. That case is therefore not in this list
 * as an option to switch on: `EnrolmentService::exitOnEngagement()` runs
 * for every sequence, on every visitor message whose conversation carries
 * a lead, and this enum covers the conditions a customer chooses on top.
 *
 * ## What "answered" can and cannot mean here
 *
 * It means the lead talked to a clerk again. It does **not** mean they
 * replied to the email, because nothing in this product receives mail:
 * there is no inbound address, no mailbox poller and no webhook, so an
 * emailed reply is not an event that can be observed at all. Detecting one
 * needs an inbound channel and the bounce and threading handling that
 * comes with it, which is a feature rather than a condition.
 *
 * This docblock previously asserted that replies were enforced for every
 * sequence. They were not, and nothing in the engine ever had a signal
 * that could have done it — the claim described an intention, and reading
 * as a statement of fact is why it went four sprints without being built.
 */
enum ExitCondition: string {

	case StageReached = 'stage_reached';
	case StatusIs     = 'status_is';
	case ScoreAbove   = 'score_above';
	case Unsubscribed = 'unsubscribed';

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::StageReached => 'Reaches a pipeline stage',
			self::StatusIs     => 'Status becomes',
			self::ScoreAbove   => 'Score goes above',
			self::Unsubscribed => 'Unsubscribes',
		};
	}

	/**
	 * Whether the condition carries a value.
	 *
	 * @return bool
	 */
	public function needsValue(): bool {
		return self::Unsubscribed !== $this;
	}

	/**
	 * Parse a stored value.
	 *
	 * @param string|null $value Stored value.
	 * @return self|null
	 */
	public static function fromStorage( ?string $value ): ?self {
		return self::tryFrom( (string) $value );
	}
}
