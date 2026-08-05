<?php
/**
 * Knowledge-gap detection and closure.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Analytics\Services;

use Hiveclerk\Core\Audit\AuditLogger;
use Hiveclerk\Core\Queue\QueueInterface;
use Hiveclerk\Domain\Agent\Agent;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Analytics\GapRepositoryInterface;
use Hiveclerk\Domain\Analytics\GapStatus;
use Hiveclerk\Domain\Analytics\KnowledgeGap;
use Hiveclerk\Domain\Conversation\Conversation;
use Hiveclerk\Domain\Knowledge\KnowledgeSource;
use Hiveclerk\Domain\Knowledge\KnowledgeSourceRepositoryInterface;
use Hiveclerk\Domain\Knowledge\RetrievalResult;
use Hiveclerk\Domain\Knowledge\SourceStatus;
use Hiveclerk\Domain\Knowledge\SourceType;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Modules\KnowledgeBase\Jobs\IngestSourceJob;

/**
 * Records what a clerk could not answer, and closes the loop when
 * somebody answers it (FR-ANL-03).
 *
 * This is the product's compounding-value mechanic. Every gap that gets
 * an answer makes the next visitor's experience better, and the screen is
 * built so that writing the answer never means leaving it.
 *
 * ## Why this module reaches into KnowledgeBase
 *
 * Answering a gap means adding an FAQ pair and re-indexing it, which is
 * KnowledgeBase's work. The alternative — an event that KnowledgeBase
 * listens for — would make "did my answer get indexed?" unanswerable
 * from the request that asked for it, and the composer would have to
 * report success it could not observe. Analytics is registered after
 * KnowledgeBase for exactly this reason, and the dependency is one way.
 */
final class GapService {

	/**
	 * Name given to the FAQ source this service creates on demand.
	 */
	public const ANSWER_SOURCE_NAME = 'Answers to unanswered questions';

	/**
	 * Construct.
	 *
	 * @param GapRepositoryInterface             $gaps    Gap storage.
	 * @param KnowledgeSourceRepositoryInterface $sources Knowledge sources.
	 * @param AgentRepositoryInterface           $agents  Clerk storage.
	 * @param QueueInterface                     $queue   Background work.
	 * @param AuditLogger                        $audit   Audit trail.
	 */
	public function __construct(
		private readonly GapRepositoryInterface $gaps,
		private readonly KnowledgeSourceRepositoryInterface $sources,
		private readonly AgentRepositoryInterface $agents,
		private readonly QueueInterface $queue,
		private readonly AuditLogger $audit
	) {
	}

	/**
	 * Note that a clerk could not answer something.
	 *
	 * Called from the chat path, on a request a visitor is waiting on, so
	 * it does the minimum: one indexed lookup and one write. Anything that
	 * could fail is swallowed — a knowledge-gap report is not worth
	 * costing somebody their reply.
	 *
	 * ## What counts as a gap
	 *
	 * Retrieval ran and nothing it found met the clerk's threshold. Note
	 * that this is not the same as the clerk having refused: a clerk with
	 * "refuse to invent" switched off answers anyway, from a weak match or
	 * from the model's own knowledge, and that answer is exactly the one
	 * the operator most wants to have written themselves. Keying the
	 * report on the refusal would hide every gap on the clerks configured
	 * to be helpful.
	 *
	 * A null result means the clerk has no sources attached at all. That
	 * is a setup problem rather than a knowledge gap, and filling the
	 * worklist with every question ever asked of an unconfigured clerk
	 * would bury the real ones on the day it was configured.
	 *
	 * @param Agent                $agent        The clerk.
	 * @param Conversation         $conversation The conversation.
	 * @param string               $question     What the visitor asked.
	 * @param RetrievalResult|null $result       What retrieval found, if it ran.
	 * @return void
	 */
	public function note(
		Agent $agent,
		Conversation $conversation,
		string $question,
		?RetrievalResult $result
	): void {
		if ( null === $agent->id || null === $result ) {
			return;
		}

		if ( array() !== $result->confident( $agent->confidenceThreshold() ) ) {
			return;
		}

		$gap = KnowledgeGap::record(
			$agent->id,
			$question,
			$result->bestScore(),
			$conversation->id
		);

		// A greeting is not a knowledge gap. Filtered here rather than in
		// the repository so the rule lives beside the reason for it.
		if ( ! $gap->isWorthAnswering() ) {
			return;
		}

		try {
			$stored = $this->gaps->record( $gap );

			/**
			 * Fires when a question goes unanswered.
			 *
			 * @param KnowledgeGap $stored       The gap, as recorded.
			 * @param Agent        $agent        The clerk.
			 * @param Conversation $conversation The conversation.
			 */
			do_action( 'hiveclerk/knowledge/gap', $stored, $agent, $conversation );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Answer a gap: add the FAQ pair, re-index it, close the gap.
	 *
	 * @param int         $gapId      Gap to answer.
	 * @param string      $answer     The answer, as written.
	 * @param string|null $sourceUuid Source to add it to, or null to use the default.
	 * @param int|null    $userId     WordPress user answering.
	 * @return array{gap: KnowledgeGap, source: KnowledgeSource}|null Null when the gap is gone.
	 */
	public function answer(
		int $gapId,
		string $answer,
		?string $sourceUuid = null,
		?int $userId = null
	): ?array {
		$gap = $this->gaps->find( $gapId );

		if ( null === $gap ) {
			return null;
		}

		$source = null === $sourceUuid
			? $this->answerSource( $gap->agentId )
			: $this->sources->findByUuid( new Uuid( $sourceUuid ) );

		if ( null === $source || SourceType::Faq !== $source->type ) {
			return null;
		}

		$pairs   = is_array( $source->config['pairs'] ?? null ) ? $source->config['pairs'] : array();
		$pairs[] = array(
			'question' => $gap->query,
			'answer'   => $answer,
			'url'      => '',
		);

		$source->config['pairs'] = array_values( $pairs );
		$source->status          = SourceStatus::Pending;

		$source = $this->sources->save( $source );

		$this->reindex( $source );
		$this->gaps->setStatus( $gapId, GapStatus::Resolved, $userId );

		$this->audit->record(
			'knowledge.gap_answered',
			array(
				'after' => array(
					'question' => $gap->query,
					'source'   => $source->name,
				),
			),
			'unanswered',
			$gapId
		);

		return array(
			'gap'    => $this->gaps->find( $gapId ) ?? $gap,
			'source' => $source,
		);
	}

	/**
	 * Set a gap's status without answering it.
	 *
	 * @param int       $gapId  Gap.
	 * @param GapStatus $status New status.
	 * @param int|null  $userId WordPress user acting.
	 * @return KnowledgeGap|null
	 */
	public function setStatus( int $gapId, GapStatus $status, ?int $userId = null ): ?KnowledgeGap {
		if ( null === $this->gaps->find( $gapId ) ) {
			return null;
		}

		$this->gaps->setStatus( $gapId, $status, $userId );

		return $this->gaps->find( $gapId );
	}

	/**
	 * The FAQ source answers go into, created on first use.
	 *
	 * One per site rather than one per clerk. A customer with four clerks
	 * answering the same question four times is the failure this screen
	 * exists to prevent, and the source is attached to the clerk that
	 * reported the gap so the answer reaches the visitor who asked.
	 *
	 * @param int $agentId Clerk that could not answer.
	 * @return KnowledgeSource
	 */
	private function answerSource( int $agentId ): KnowledgeSource {
		foreach ( $this->sources->forAgent( $agentId ) as $source ) {
			if ( SourceType::Faq === $source->type && self::ANSWER_SOURCE_NAME === $source->name ) {
				return $source;
			}
		}

		$source = $this->sources->save(
			new KnowledgeSource(
				id: null,
				uuid: Uuid::generate(),
				name: self::ANSWER_SOURCE_NAME,
				type: SourceType::Faq,
				status: SourceStatus::Pending,
				config: array( 'pairs' => array() ),
				syncSchedule: 'manual'
			)
		);

		if ( null !== $source->id ) {
			// Attached rather than left loose: a source no clerk can read
			// is an answer that was written and never delivered, and
			// nothing on the screen would say so.
			$this->agents->attachSource( $agentId, $source->id );
		}

		return $source;
	}

	/**
	 * Queue an index run for a source.
	 *
	 * @param KnowledgeSource $source Source.
	 * @return void
	 */
	private function reindex( KnowledgeSource $source ): void {
		if ( null === $source->id ) {
			return;
		}

		$args = array( 'source_id' => (int) $source->id );

		// Idempotent, as everywhere else this job is queued: answering
		// three gaps in a row should re-index once, not three times, and
		// the third answer is already in the config the first run reads.
		if ( $this->queue->isPending( IngestSourceJob::hook(), $args ) ) {
			return;
		}

		$this->queue->enqueue( IngestSourceJob::hook(), $args );
	}
}
