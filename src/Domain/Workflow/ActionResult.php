<?php
/**
 * What an action reports back.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Workflow;

/**
 * Done, skipped, retry or failed — and a line for the log.
 *
 * Four outcomes rather than a boolean, because the engine does something
 * different with each. Skipped continues; retry comes back to the same
 * node; failed stops the run. A boolean would collapse "this lead has
 * unsubscribed" into the same answer as "the CRM returned a 500", and an
 * operator reading the log would have no way to tell a working workflow
 * from a broken integration.
 */
final readonly class ActionResult {

	/**
	 * Construct.
	 *
	 * @param NodeOutcome          $outcome What happened.
	 * @param string|null          $detail  One line for the run log.
	 * @param array<string, mixed> $context Values to merge into the run context.
	 */
	private function __construct(
		public NodeOutcome $outcome,
		public ?string $detail = null,
		public array $context = array(),
	) {
	}

	/**
	 * The action did its work.
	 *
	 * @param string|null          $detail  What it did.
	 * @param array<string, mixed> $context Values later nodes can read.
	 * @return self
	 */
	public static function done( ?string $detail = null, array $context = array() ): self {
		return new self( NodeOutcome::Acted, $detail, $context );
	}

	/**
	 * The action deliberately did nothing, and the run carries on.
	 *
	 * @param string $detail Why.
	 * @return self
	 */
	public static function skipped( string $detail ): self {
		return new self( NodeOutcome::Skipped, $detail );
	}

	/**
	 * The action could not run now but might in a few minutes.
	 *
	 * @param string $detail Why.
	 * @return self
	 */
	public static function retry( string $detail ): self {
		return new self( NodeOutcome::Waited, $detail );
	}

	/**
	 * The action failed and the run stops.
	 *
	 * @param string $detail Why.
	 * @return self
	 */
	public static function failed( string $detail ): self {
		return new self( NodeOutcome::Failed, $detail );
	}

	/**
	 * Whether the engine should move on to the next node.
	 *
	 * @return bool
	 */
	public function continues(): bool {
		return match ( $this->outcome ) {
			NodeOutcome::Acted, NodeOutcome::Skipped => true,
			default                                  => false,
		};
	}

	/**
	 * Whether the engine should come back to this node.
	 *
	 * @return bool
	 */
	public function isRetry(): bool {
		return NodeOutcome::Waited === $this->outcome;
	}
}
