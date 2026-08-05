<?php
/**
 * Knowledge source entity.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Knowledge;

use Hiveclerk\Domain\Shared\Uuid;

/**
 * A body of content a clerk can answer from.
 */
final class KnowledgeSource {

	/**
	 * Construct.
	 *
	 * @param int|null             $id             Storage id.
	 * @param Uuid                 $uuid           Public identifier.
	 * @param string               $name           Display name.
	 * @param SourceType           $type           What kind of source.
	 * @param SourceStatus         $status         Indexing state.
	 * @param array<string, mixed> $config         Type-specific settings.
	 * @param string|null          $embedProvider  Provider pinned at index time.
	 * @param string|null          $embedModel     Model pinned at index time.
	 * @param int|null             $embedDimensions Vector width.
	 * @param int                  $documentCount  Documents held.
	 * @param int                  $chunkCount     Chunks held.
	 * @param int                  $tokenCount     Tokens held.
	 * @param string               $syncSchedule   manual|on_save|daily|weekly.
	 * @param string|null          $lastError      Last failure, if any.
	 * @param array<string, mixed> $progress       Live progress of the current run.
	 * @param string|null          $lastSyncedAt   When indexing last completed.
	 */
	public function __construct(
		public ?int $id,
		public Uuid $uuid,
		public string $name,
		public SourceType $type,
		public SourceStatus $status = SourceStatus::Pending,
		public array $config = array(),
		public ?string $embedProvider = null,
		public ?string $embedModel = null,
		public ?int $embedDimensions = null,
		public int $documentCount = 0,
		public int $chunkCount = 0,
		public int $tokenCount = 0,
		public string $syncSchedule = 'manual',
		public ?string $lastError = null,
		// Progress is its own column rather than a key inside config.
		// config holds what the operator chose and is rewritten whenever
		// they save the settings form; progress is written by a background
		// job fifteen documents at a time. Sharing a column means one of
		// them overwrites the other, and which one depends on timing.
		public array $progress = array(),
		public ?string $lastSyncedAt = null,
	) {
	}

	/**
	 * Whether this source can be retrieved from right now.
	 *
	 * @return bool
	 */
	public function isUsable(): bool {
		return SourceStatus::Ready === $this->status && $this->chunkCount > 0;
	}

	/**
	 * Whether the embedding provider changed since indexing.
	 *
	 * Switching providers does not silently corrupt retrieval: the source is
	 * marked for re-embedding instead, because vectors from two different
	 * models are not comparable.
	 *
	 * @param string $provider   Currently configured provider.
	 * @param string $model      Currently configured model.
	 * @return bool
	 */
	public function needsReembedding( string $provider, string $model ): bool {
		if ( null === $this->embedProvider || null === $this->embedModel ) {
			return false;
		}

		return $this->embedProvider !== $provider || $this->embedModel !== $model;
	}
}
