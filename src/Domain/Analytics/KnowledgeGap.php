<?php
/**
 * A question the knowledge base could not answer.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Analytics;

use DateTimeImmutable;

/**
 * One unanswered question, counted rather than logged.
 *
 * The row is keyed on `(agent_id, query_hash)` and carries an occurrence
 * count, which is the difference between a worklist and a firehose.
 * Eighteen people asking about trade accounts is one thing to write, and
 * the fact that eighteen asked is exactly what tells the operator to
 * write it first. Eighteen separate rows would bury the signal in its own
 * evidence.
 *
 * `bestScore` is kept because "we found nothing" and "we found something
 * that scored 0.34 against a 0.62 threshold" are different problems with
 * different fixes: the first wants new content, the second usually wants
 * the existing page to say the word the visitor used.
 */
final class KnowledgeGap {

	/**
	 * Construct.
	 *
	 * @param int|null               $id             Row id.
	 * @param int                    $agentId        Clerk that could not answer.
	 * @param string                 $query          The question, as asked.
	 * @param string                 $queryHash      Hash of the normalised question.
	 * @param float|null             $bestScore      Best similarity found, null when nothing was.
	 * @param int                    $occurrences    How many times it has been asked.
	 * @param GapStatus              $status         What has been done about it.
	 * @param int|null               $conversationId Where it was last asked.
	 * @param int|null               $resolvedBy     WordPress user who answered it.
	 * @param DateTimeImmutable|null $firstSeenAt    First time it was asked.
	 * @param DateTimeImmutable|null $lastSeenAt     Most recent time.
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly int $agentId,
		public readonly string $query,
		public readonly string $queryHash,
		public readonly ?float $bestScore = null,
		public readonly int $occurrences = 1,
		public readonly GapStatus $status = GapStatus::Open,
		public readonly ?int $conversationId = null,
		public readonly ?int $resolvedBy = null,
		public readonly ?DateTimeImmutable $firstSeenAt = null,
		public readonly ?DateTimeImmutable $lastSeenAt = null
	) {
	}

	/**
	 * Normalise a question into the form the hash is taken over.
	 *
	 * Case, punctuation and runs of whitespace are removed so that "Do you
	 * offer trade accounts?" and "do you offer trade accounts" are one row
	 * rather than two. Nothing more aggressive than that: stemming or
	 * stop-word removal would collapse "returns for damaged goods" and
	 * "returns for unwanted goods" into one gap, and they are two
	 * different pages to write.
	 *
	 * @param string $query Raw question.
	 * @return string
	 */
	public static function normalise( string $query ): string {
		$lowered = function_exists( 'mb_strtolower' )
			? mb_strtolower( $query, 'UTF-8' )
			: strtolower( $query );

		$stripped = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $lowered );
		$squashed = preg_replace( '/\s+/u', ' ', (string) $stripped );

		return trim( (string) $squashed );
	}

	/**
	 * The hash a gap is deduplicated by.
	 *
	 * @param string $query Raw question.
	 * @return string
	 */
	public static function hash( string $query ): string {
		return hash( 'sha256', self::normalise( $query ) );
	}

	/**
	 * A new gap, ready to be recorded.
	 *
	 * @param int        $agentId        Clerk.
	 * @param string     $query          The question.
	 * @param float|null $bestScore      Best similarity found.
	 * @param int|null   $conversationId Conversation.
	 * @return self
	 */
	public static function record(
		int $agentId,
		string $query,
		?float $bestScore,
		?int $conversationId = null
	): self {
		return new self(
			null,
			$agentId,
			$query,
			self::hash( $query ),
			$bestScore,
			1,
			GapStatus::Open,
			$conversationId
		);
	}

	/**
	 * Whether the clerk found nothing at all, rather than something weak.
	 *
	 * @return bool
	 */
	public function foundNothing(): bool {
		return null === $this->bestScore || $this->bestScore <= 0.0;
	}

	/**
	 * Whether the question is long enough to be worth writing an answer to.
	 *
	 * A visitor typing "hi" produces a retrieval that matches nothing, and
	 * a worklist full of greetings is a worklist nobody opens. The floor
	 * is on words rather than characters so that a short real question —
	 * "trade accounts?" — still counts.
	 *
	 * @return bool
	 */
	public function isWorthAnswering(): bool {
		$normalised = self::normalise( $this->query );

		if ( '' === $normalised ) {
			return false;
		}

		return count( explode( ' ', $normalised ) ) >= 2 && strlen( $normalised ) >= 8;
	}
}
