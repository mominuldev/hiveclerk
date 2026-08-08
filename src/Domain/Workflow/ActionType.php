<?php
/**
 * Things a workflow can do.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Workflow;

/**
 * The closed set of actions (FR-WFL-03).
 *
 * Every case delegates to a module that already owns the behaviour, and
 * none of them reimplements it. `EnrolSequence` hands the lead to the
 * email module rather than sending a message itself, which is what makes
 * the suppression list, the unsubscribe link and the hourly send ceiling
 * apply to workflow email without a line of code saying so. An action
 * that sent its own mail would be an action that quietly emails people
 * who have asked not to be emailed.
 *
 * `Webhook` posts through the Integrations module's dispatcher for the
 * same kind of reason: outbound URLs there are already validated against
 * private address ranges and signed. A node that took a URL of its own
 * would be a new SSRF surface with none of that behind it.
 */
enum ActionType: string {

	case EnrolSequence = 'enrol_sequence';
	case SetStage      = 'set_stage';
	case AdjustScore   = 'adjust_score';
	case AddNote       = 'add_note';
	case SyncCrm       = 'sync_crm';
	case Webhook       = 'webhook';
	case NotifyAdmin   = 'notify_admin';

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::EnrolSequence => 'Start an email sequence',
			self::SetStage      => 'Move to a pipeline stage',
			self::AdjustScore   => 'Adjust the score',
			self::AddNote       => 'Add a note to the timeline',
			self::SyncCrm       => 'Push to the CRM',
			self::Webhook       => 'Send a webhook',
			self::NotifyAdmin   => 'Email the team',
		};
	}

	/**
	 * One line saying what it does and what it costs.
	 *
	 * @return string
	 */
	public function description(): string {
		return match ( $this ) {
			self::EnrolSequence => 'Enrols the lead in a follow-up sequence. Suppressed and unsubscribed addresses are skipped.',
			self::SetStage      => 'Moves the lead into a stage, exactly as dragging the card would.',
			self::AdjustScore   => 'Adds or subtracts points, recorded on the score breakdown with your reason.',
			self::AddNote       => 'Writes a line on the lead timeline. Changes nothing else.',
			self::SyncCrm       => 'Pushes the lead to every connected CRM that accepts it.',
			self::Webhook       => 'Posts the lead to your configured webhook endpoints, signed.',
			self::NotifyAdmin   => 'Sends one email to the addresses you name here.',
		};
	}

	/**
	 * Which subjects this action can run against.
	 *
	 * A conversation-triggered run has no lead behind it until one is
	 * captured, so most of these are unavailable there rather than
	 * silently doing nothing.
	 *
	 * @return array<int, SubjectType>
	 */
	public function subjects(): array {
		return match ( $this ) {
			self::Webhook, self::NotifyAdmin => array( SubjectType::Lead, SubjectType::Conversation ),
			default                          => array( SubjectType::Lead ),
		};
	}

	/**
	 * Whether this action supports the given subject.
	 *
	 * @param SubjectType $subject Subject kind.
	 * @return bool
	 */
	public function supports( SubjectType $subject ): bool {
		return in_array( $subject, $this->subjects(), true );
	}

	/**
	 * Whether running this action can cost the customer money.
	 *
	 * Used by the builder to mark the nodes worth reading twice before
	 * activating, and by the dry run to say what it deliberately did not do.
	 *
	 * @return bool
	 */
	public function reachesOutside(): bool {
		return match ( $this ) {
			self::EnrolSequence, self::SyncCrm, self::Webhook, self::NotifyAdmin => true,
			default => false,
		};
	}

	/**
	 * Read a stored value.
	 *
	 * @param string|null $value Stored value.
	 * @return self|null Null when the value names no known action.
	 */
	public static function tryFromStorage( ?string $value ): ?self {
		return null === $value ? null : self::tryFrom( $value );
	}
}
