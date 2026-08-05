<?php
/**
 * A model provider that does what the test says.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Chat;

use Hiveclerk\Ai\AiServiceInterface;
use Hiveclerk\Ai\Completion;
use Hiveclerk\Ai\CompletionRequest;
use Hiveclerk\Ai\EmbeddingBatch;
use Hiveclerk\Ai\EmbeddingModel;
use Hiveclerk\Ai\ProviderException;
use Hiveclerk\Ai\StreamEvent;
use Hiveclerk\Domain\Usage\UsageKind;

/**
 * Emits a scripted stream, and fails on cue.
 *
 * The failure modes are the reason this exists. A real provider fails
 * after some tokens roughly as often as before any, and the two need
 * opposite handling — one keeps what arrived, the other reports an error —
 * which is not a distinction that can be exercised against a live API on
 * demand.
 */
final class FakeAiService implements AiServiceInterface {

	/**
	 * Text increments to emit, in order.
	 *
	 * @var array<int, string>
	 */
	public array $deltas = array( 'Yes.' );

	/**
	 * Thrown after the deltas have been emitted, if set.
	 *
	 * @var ProviderException|null
	 */
	public ?ProviderException $failWith = null;

	/**
	 * The completion reported on the done event.
	 *
	 * @var Completion|null
	 */
	public ?Completion $completion = null;

	/**
	 * How many times stream() was called.
	 *
	 * @var int
	 */
	public int $calls = 0;

	/**
	 * How many deltas were actually emitted before the sink stopped us.
	 *
	 * @var int
	 */
	public int $emitted = 0;

	/**
	 * The last request assembled, for assertions about the prompt.
	 *
	 * @var CompletionRequest|null
	 */
	public ?CompletionRequest $lastRequest = null;

	public function complete(
		string $providerId,
		CompletionRequest $request,
		UsageKind $kind = UsageKind::Chat,
		?int $agentId = null,
		?int $conversationId = null
	): Completion {
		$this->lastRequest = $request;

		return $this->completion ?? new Completion( implode( '', $this->deltas ), $request->model, $providerId );
	}

	public function stream(
		string $providerId,
		CompletionRequest $request,
		callable $onEvent,
		UsageKind $kind = UsageKind::Chat,
		?int $agentId = null,
		?int $conversationId = null
	): void {
		++$this->calls;

		$this->lastRequest = $request;

		foreach ( $this->deltas as $delta ) {
			++$this->emitted;

			if ( false === $onEvent( StreamEvent::delta( $delta ) ) ) {
				return;
			}
		}

		if ( null !== $this->failWith ) {
			throw $this->failWith;
		}

		$onEvent(
			StreamEvent::done(
				$this->completion ?? new Completion(
					implode( '', $this->deltas ),
					$request->model,
					$providerId,
					100,
					20
				)
			)
		);
	}

	public function embed( EmbeddingModel $pin, array $texts, int $timeout = 60 ): EmbeddingBatch {
		throw new ProviderException( 'The fake does not embed.', $pin->provider );
	}
}
