<?php
/**
 * Activity repository.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Repositories;

use DateTimeImmutable;
use DateTimeZone;
use Hiveclerk\Database\AbstractRepository;
use Hiveclerk\Database\Schema;
use Hiveclerk\Domain\Lead\Activity;
use Hiveclerk\Domain\Lead\ActivityRepositoryInterface;
use Hiveclerk\Domain\Lead\ActivityType;

/**
 * Stores the lead timeline.
 */
final class ActivityRepository extends AbstractRepository implements ActivityRepositoryInterface {

	protected function table(): string {
		return Schema::ACTIVITIES;
	}

	protected function sortableColumns(): array {
		return array( 'id', 'created_at' );
	}

	public function record( Activity $activity ): Activity {
		$created = $activity->createdAt ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

		$id = $this->insertRow(
			array(
				'lead_id'      => $activity->leadId,
				'visitor_id'   => $activity->visitorId,
				'type'         => $activity->type->value,
				'subject_type' => $activity->subjectType,
				'subject_id'   => $activity->subjectId,
				'wp_user_id'   => $activity->wpUserId,
				'title'        => mb_substr( $activity->title, 0, 255 ),
				'body'         => $activity->body,
				'metadata'     => $this->encodeJson( $activity->metadata ),
				'created_at'   => $created->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		$activity->createdAt = $created;

		if ( null !== $id ) {
			$activity->id = $id;
		}

		return $activity;
	}

	public function timeline( int $leadId, array $visitorIds = array(), int $limit = 100 ): array {
		$visitorIds = array_values( array_unique( array_map( 'intval', $visitorIds ) ) );

		$where  = 'lead_id = %d';
		$params = array( $leadId );

		if ( array() !== $visitorIds ) {
			// The page views a visitor accumulated before they said who they
			// were are the top half of this screen. Reading only lead_id
			// would show a timeline that starts at "lead captured", which is
			// the moment the interesting part ends.
			$placeholders = implode( ', ', array_fill( 0, count( $visitorIds ), '%d' ) );
			$where        = "( lead_id = %d OR visitor_id IN ({$placeholders}) )";
			$params       = array_merge( $params, $visitorIds );
		}

		$rows = $this->fetchAll( $where, $params, 'created_at', 'DESC', $limit );

		return array_map( fn ( array $row ): Activity => $this->hydrate( $row ), $rows );
	}

	public function hasType( int $leadId, ActivityType $type ): bool {
		return $this->countWhere( 'lead_id = %d AND type = %s', array( $leadId, $type->value ) ) > 0;
	}

	public function reassign( int $from, int $to ): int {
		$table = $this->tableName();

		$done = $this->execute(
			"UPDATE `{$table}` SET lead_id = %d WHERE lead_id = %d",
			array( $to, $from )
		);

		return $done ? (int) $this->db->rows_affected : 0;
	}

	public function attachVisitor( int $visitorId, int $leadId ): int {
		$table = $this->tableName();

		$done = $this->execute(
			"UPDATE `{$table}` SET lead_id = %d WHERE visitor_id = %d AND lead_id IS NULL",
			array( $leadId, $visitorId )
		);

		return $done ? (int) $this->db->rows_affected : 0;
	}

	public function deleteForLead( int $leadId ): int {
		$table = $this->tableName();

		$done = $this->execute( "DELETE FROM `{$table}` WHERE lead_id = %d", array( $leadId ) );

		return $done ? (int) $this->db->rows_affected : 0;
	}

	/**
	 * Build an Activity from a database row.
	 *
	 * A row whose type is not one this release renders is dropped rather
	 * than shown as a blank line. Types arrive from integrations written
	 * against later versions, and a timeline with an unlabelled entry in
	 * the middle of it is worse than one entry short.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return Activity
	 */
	private function hydrate( array $row ): Activity {
		$created = $row['created_at'] ?? null;
		$type    = ActivityType::fromStorage( $this->text( $row['type'] ?? null ) );

		return new Activity(
			id: (int) $row['id'],
			type: $type ?? ActivityType::NoteAdded,
			title: (string) ( $row['title'] ?? '' ),
			leadId: $this->intOrNull( $row['lead_id'] ?? null ),
			visitorId: $this->intOrNull( $row['visitor_id'] ?? null ),
			subjectType: $this->text( $row['subject_type'] ?? null ),
			subjectId: $this->intOrNull( $row['subject_id'] ?? null ),
			wpUserId: $this->intOrNull( $row['wp_user_id'] ?? null ),
			body: $this->text( $row['body'] ?? null ),
			metadata: $this->json( $row['metadata'] ?? null ),
			createdAt: is_string( $created ) && '' !== $created
				? new DateTimeImmutable( $created, new DateTimeZone( 'UTC' ) )
				: null,
		);
	}
}
