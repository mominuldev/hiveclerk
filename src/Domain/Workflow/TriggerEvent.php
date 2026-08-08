<?php
/**
 * What starts a workflow.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Workflow;

/**
 * The closed set of things that can open a run (FR-WFL-01).
 *
 * Every case here maps to a domain event this product already fires, and
 * that constraint is the point: a trigger the platform cannot observe is
 * a promise the builder screen makes and the engine never keeps. When a
 * new event is added to the product, it becomes available here — not
 * before.
 *
 * `Schedule` is the exception and it observes the clock instead. It is
 * the only trigger that opens runs for subjects nothing just happened to,
 * which is why it carries a segment filter and a batch ceiling: "every
 * lead" on a site with forty thousand of them is a trigger that must not
 * be expressible by accident.
 */
enum TriggerEvent: string {

	case LeadCaptured     = 'lead_captured';
	case LeadQualified    = 'lead_qualified';
	case LeadStageChanged = 'lead_stage_changed';
	case HandoffRequested = 'handoff_requested';
	case Schedule         = 'schedule';

	/**
	 * Human label, as the builder shows it.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::LeadCaptured     => 'A lead is captured',
			self::LeadQualified    => 'A lead becomes qualified',
			self::LeadStageChanged => 'A lead moves stage',
			self::HandoffRequested => 'A visitor asks for a human',
			self::Schedule         => 'On a schedule',
		};
	}

	/**
	 * One line explaining when this actually fires.
	 *
	 * @return string
	 */
	public function description(): string {
		return match ( $this ) {
			self::LeadCaptured     => 'The moment a clerk captures contact details, once per lead.',
			self::LeadQualified    => 'When scoring first takes a lead over the qualification threshold.',
			self::LeadStageChanged => 'Whenever a lead lands in a pipeline stage you choose.',
			self::HandoffRequested => 'When a conversation asks to be handed to a person.',
			self::Schedule         => 'Every few hours, over the leads matching a filter you set.',
		};
	}

	/**
	 * What kind of record the run will be about.
	 *
	 * @return SubjectType
	 */
	public function subject(): SubjectType {
		return match ( $this ) {
			self::HandoffRequested => SubjectType::Conversation,
			default                => SubjectType::Lead,
		};
	}

	/**
	 * Whether this trigger is driven by the clock rather than an event.
	 *
	 * @return bool
	 */
	public function isScheduled(): bool {
		return self::Schedule === $this;
	}

	/**
	 * Whether the trigger takes a stage to watch.
	 *
	 * @return bool
	 */
	public function needsStage(): bool {
		return self::LeadStageChanged === $this;
	}

	/**
	 * Read a stored value.
	 *
	 * @param string|null $value Stored value.
	 * @return self
	 */
	public static function fromStorage( ?string $value ): self {
		if ( null === $value ) {
			return self::LeadCaptured;
		}

		return self::tryFrom( $value ) ?? self::LeadCaptured;
	}
}
