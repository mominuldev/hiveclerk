<?php
/**
 * Lead export.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Leads\Services;

use Hiveclerk\Core\Support\Csv;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Lead\LeadRepositoryInterface;
use Hiveclerk\Domain\Lead\LeadStage;
use Hiveclerk\Domain\Lead\LeadStageRepositoryInterface;
use Hiveclerk\Domain\Lead\QualificationQuestion;

/**
 * Leads as CSV (FR-LED-10).
 *
 * ## Why the CSV comes back inside JSON
 *
 * The admin authenticates with a cookie plus an `X-WP-Nonce` header, and
 * a plain download link carries neither. The alternatives are a
 * short-lived signed URL — a second auth mechanism to design, review and
 * get wrong — or handing the text to the browser and letting it make the
 * file. The SPA builds a Blob, which costs nothing and keeps one way in.
 *
 * ## The cap is real and it is reported
 *
 * An export is one request, and one request that hydrates every lead on
 * a large site is the one that hits the memory budget. Rows are read in
 * batches and stop at {@see self::MAX_ROWS}; when they stop early the
 * response says so, because a spreadsheet that silently ends at five
 * thousand rows is worse than an error.
 */
final class LeadExporter {

	/**
	 * Rows one export may contain.
	 */
	public const MAX_ROWS = 5000;

	/**
	 * Rows read per query.
	 */
	private const BATCH = 250;

	/**
	 * Construct.
	 *
	 * @param LeadRepositoryInterface      $leads  Lead storage.
	 * @param LeadStageRepositoryInterface $stages Stage storage.
	 */
	public function __construct(
		private readonly LeadRepositoryInterface $leads,
		private readonly LeadStageRepositoryInterface $stages
	) {
	}

	/**
	 * Build the file.
	 *
	 * @param array<string, mixed> $filters   Same filters the list screen uses.
	 * @param array<int, string>   $questions Qualification keys to give columns of their own.
	 * @return array{filename: string, csv: string, rows: int, total: int, truncated: bool}
	 */
	public function export( array $filters = array(), array $questions = array() ): array {
		$total   = $this->leads->count( $filters );
		$stages  = $this->stageNames();
		$columns = $this->columns( $questions );
		$lines   = array( Csv::line( $columns ) );
		$offset  = 0;
		$rows    = 0;

		while ( $rows < self::MAX_ROWS ) {
			$batch = $this->leads->batch( $filters, min( self::BATCH, self::MAX_ROWS - $rows ), $offset );

			if ( array() === $batch ) {
				break;
			}

			foreach ( $batch as $lead ) {
				$lines[] = Csv::line( $this->row( $lead, $stages, $questions ) );

				++$rows;
			}

			$offset += count( $batch );
		}

		return array(
			'filename'  => sprintf( 'hiveclerk-leads-%s.csv', gmdate( 'Y-m-d' ) ),
			// A BOM, because the single most common destination for this
			// file is Excel on Windows, which reads UTF-8 as Latin-1
			// without one and turns every accented name into mojibake.
			'csv'       => "\u{FEFF}" . implode( "\r\n", $lines ) . "\r\n",
			'rows'      => $rows,
			'total'     => $total,
			'truncated' => $rows < $total,
		);
	}

	/**
	 * The header row.
	 *
	 * @param array<int, string> $questions Qualification keys.
	 * @return array<int, string>
	 */
	private function columns( array $questions ): array {
		$columns = array(
			'id',
			'email',
			'first_name',
			'last_name',
			'phone',
			'company',
			'job_title',
			'website',
			'score',
			'band',
			'status',
			'stage',
			'source',
			'owner_user_id',
			'first_seen_at',
			'last_active_at',
			'created_at',
		);

		foreach ( $questions as $key ) {
			$columns[] = 'answer_' . $key;
		}

		return $columns;
	}

	/**
	 * One lead as a row.
	 *
	 * @param Lead               $lead      The lead.
	 * @param array<int, string> $stages    Stage names, keyed by id.
	 * @param array<int, string> $questions Qualification keys.
	 * @return array<int, string>
	 */
	private function row( Lead $lead, array $stages, array $questions ): array {
		$row = array(
			$lead->uuid->value,
			(string) $lead->email,
			(string) $lead->firstName,
			(string) $lead->lastName,
			(string) $lead->phone,
			(string) $lead->company,
			(string) $lead->jobTitle,
			(string) $lead->website,
			(string) $lead->score,
			$lead->band->value,
			$lead->status->value,
			null === $lead->stageId ? '' : ( $stages[ $lead->stageId ] ?? '' ),
			(string) $lead->source,
			null === $lead->ownerUserId ? '' : (string) $lead->ownerUserId,
			(string) $lead->firstSeenAt?->format( 'Y-m-d H:i:s' ),
			(string) $lead->lastActiveAt?->format( 'Y-m-d H:i:s' ),
			(string) $lead->createdAt?->format( 'Y-m-d H:i:s' ),
		);

		foreach ( $questions as $key ) {
			$row[] = (string) $lead->answer( $key );
		}

		return $row;
	}

	/**
	 * Stage names by id.
	 *
	 * @return array<int, string>
	 */
	private function stageNames(): array {
		$names = array();

		foreach ( $this->stages->all() as $stage ) {
			if ( $stage instanceof LeadStage && null !== $stage->id ) {
				$names[ $stage->id ] = $stage->name;
			}
		}

		return $names;
	}

	/**
	 * Every qualification key configured on any clerk.
	 *
	 * @param array<int, array<string, mixed>> $leadConfigs Clerk lead configurations.
	 * @return array<int, string>
	 */
	public static function questionKeys( array $leadConfigs ): array {
		$keys = array();

		foreach ( $leadConfigs as $config ) {
			$questions = $config['questions'] ?? null;

			if ( ! is_array( $questions ) ) {
				continue;
			}

			foreach ( $questions as $question ) {
				if ( ! is_array( $question ) ) {
					continue;
				}

				$key = QualificationQuestion::key( $question['key'] ?? null );

				if ( '' !== $key && ! in_array( $key, $keys, true ) ) {
					$keys[] = $key;
				}
			}
		}

		return $keys;
	}
}
