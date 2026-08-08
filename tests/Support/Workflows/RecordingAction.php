<?php
/**
 * An action that records rather than acts.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Workflows;

use Hiveclerk\Domain\Workflow\ActionHandlerInterface;
use Hiveclerk\Domain\Workflow\ActionResult;
use Hiveclerk\Domain\Workflow\ActionType;
use Hiveclerk\Domain\Workflow\WorkflowContext;

/**
 * An action that records what it was asked to do and returns what it was told.
 *
 * @internal
 */
final class RecordingAction implements ActionHandlerInterface {

	/**
	 * Every context it was called with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $calls = array();

	/**
	 * Construct.
	 *
	 * @param ActionType        $type   Action this handles.
	 * @param ActionResult|null $result What to return; done by default.
	 * @param string|null       $reason Validation refusal, when there is one.
	 */
	public function __construct(
		private readonly ActionType $type,
		private ?ActionResult $result = null,
		private readonly ?string $reason = null
	) {
	}

	public function type(): ActionType {
		return $this->type;
	}

	public function execute( WorkflowContext $context, array $config ): ActionResult {
		$this->calls[] = $context->all();

		return $this->result ?? ActionResult::done( 'Did the thing.' );
	}

	public function validate( array $config ): ?string {
		unset( $config );

		return $this->reason;
	}

	public function describe( WorkflowContext $context, array $config ): string {
		unset( $context, $config );

		return 'Would do the thing';
	}

	/**
	 * Change what the next call returns.
	 *
	 * @param ActionResult $result Result.
	 * @return void
	 */
	public function willReturn( ActionResult $result ): void {
		$this->result = $result;
	}

	/**
	 * How many times it ran.
	 *
	 * @return int
	 */
	public function callCount(): int {
		return count( $this->calls );
	}
}
