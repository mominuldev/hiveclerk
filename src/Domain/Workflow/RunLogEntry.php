<?php
/**
 * One line of a run's history.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Workflow;

use DateTimeImmutable;

/**
 * What the engine did at one node, and why.
 *
 * Written for the operator reading it a week later, not for a debugger.
 * A condition entry records the value it actually compared, because "Score
 * is more than 60 → No" is not an explanation and "Score was 45 → No" is.
 */
final readonly class RunLogEntry {

	/**
	 * Construct.
	 *
	 * @param int|null               $id        Storage id, null before first save.
	 * @param int                    $runId     Which run.
	 * @param string                 $nodeId    Which node.
	 * @param NodeType               $nodeType  What kind of node.
	 * @param NodeOutcome            $outcome   What happened.
	 * @param string|null            $detail    One line of explanation.
	 * @param DateTimeImmutable|null $createdAt When, UTC.
	 */
	public function __construct(
		public ?int $id,
		public int $runId,
		public string $nodeId,
		public NodeType $nodeType,
		public NodeOutcome $outcome,
		public ?string $detail = null,
		public ?DateTimeImmutable $createdAt = null,
	) {
	}
}
