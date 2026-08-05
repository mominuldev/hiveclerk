<?php
/**
 * Sequence repository.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Repositories;

use Hiveclerk\Database\AbstractRepository;
use Hiveclerk\Database\Schema;
use Hiveclerk\Domain\Email\EmailSequence;
use Hiveclerk\Domain\Email\SequenceRepositoryInterface;
use Hiveclerk\Domain\Email\SequenceStatus;
use Hiveclerk\Domain\Email\TriggerType;
use Hiveclerk\Domain\Shared\Pagination;
use Hiveclerk\Domain\Shared\Uuid;

/**
 * Stores follow-up sequences.
 */
final class SequenceRepository extends AbstractRepository implements SequenceRepositoryInterface {

	protected function table(): string {
		return Schema::EMAIL_SEQUENCES;
	}

	protected function sortableColumns(): array {
		return array( 'id', 'name', 'status', 'created_at', 'updated_at' );
	}

	public function find( int $id ): ?EmailSequence {
		$row = $this->fetchRow( 'id = %d', array( $id ) );

		return null === $row ? null : $this->hydrate( $row );
	}

	public function findByUuid( Uuid $uuid ): ?EmailSequence {
		$row = $this->fetchRow( 'uuid = %s', array( $uuid->value ) );

		return null === $row ? null : $this->hydrate( $row );
	}

	public function paginate( Pagination $pagination ): array {
		return array_map(
			fn ( array $row ): EmailSequence => $this->hydrate( $row ),
			$this->fetchAll(
				'deleted_at IS NULL',
				array(),
				'id',
				'DESC',
				$pagination->perPage,
				$pagination->offset()
			)
		);
	}

	public function countAll(): int {
		return $this->countWhere( 'deleted_at IS NULL' );
	}

	public function activeFor( TriggerType $trigger ): array {
		return array_map(
			fn ( array $row ): EmailSequence => $this->hydrate( $row ),
			$this->fetchAll(
				'deleted_at IS NULL AND status = %s AND trigger_type = %s',
				array( SequenceStatus::Active->value, $trigger->value ),
				'id',
				'ASC'
			)
		);
	}

	public function save( EmailSequence $sequence ): EmailSequence {
		$data = array(
			'name'            => $sequence->name,
			'status'          => $sequence->status->value,
			'trigger_type'    => $sequence->trigger->value,
			'trigger_config'  => $this->encodeJson( $sequence->triggerConfig ),
			'exit_conditions' => $this->encodeJson( $sequence->exitConditions ),
			'from_name'       => $sequence->fromName,
			'from_email'      => $sequence->fromEmail,
			'reply_to'        => $sequence->replyTo,
			'deleted_at'      => $this->stamp( $sequence->deletedAt ),
			'updated_at'      => $this->now(),
		);

		if ( null === $sequence->id ) {
			$data['uuid']           = $sequence->uuid->value;
			$data['enrolled_count'] = $sequence->enrolledCount;
			$data['created_at']     = $this->now();

			$id = $this->insertRow( $data );

			if ( null === $id ) {
				return $sequence;
			}

			$sequence->id = $id;

			return $sequence;
		}

		$this->updateRow( $sequence->id, $data );

		return $sequence;
	}

	public function softDelete( int $id ): bool {
		return $this->updateRow( $id, array( 'deleted_at' => $this->now() ) );
	}

	public function incrementEnrolled( int $id, int $by = 1 ): void {
		$table = $this->tableName();

		// Incremented in SQL rather than read-modify-write. Two enrolments
		// landing in the same second from two requests would otherwise
		// each read the same total and write it back plus one.
		$this->execute(
			"UPDATE `{$table}` SET enrolled_count = enrolled_count + %d WHERE id = %d",
			array( $by, $id )
		);
	}

	/**
	 * Build an EmailSequence from a database row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return EmailSequence
	 */
	private function hydrate( array $row ): EmailSequence {
		$conditions = $this->json( $row['exit_conditions'] ?? null );
		$clean      = array();

		foreach ( $conditions as $condition ) {
			if ( is_array( $condition ) ) {
				$clean[] = $condition;
			}
		}

		return new EmailSequence(
			id: (int) $row['id'],
			uuid: new Uuid( (string) $row['uuid'] ),
			name: (string) $row['name'],
			status: SequenceStatus::fromStorage( $this->text( $row['status'] ?? null ) ),
			trigger: TriggerType::fromStorage( $this->text( $row['trigger_type'] ?? null ) ),
			triggerConfig: $this->json( $row['trigger_config'] ?? null ),
			exitConditions: $clean,
			fromName: $this->text( $row['from_name'] ?? null ),
			fromEmail: $this->text( $row['from_email'] ?? null ),
			replyTo: $this->text( $row['reply_to'] ?? null ),
			enrolledCount: (int) ( $row['enrolled_count'] ?? 0 ),
			createdAt: $this->time( $row['created_at'] ?? null ),
			updatedAt: $this->time( $row['updated_at'] ?? null ),
			deletedAt: $this->time( $row['deleted_at'] ?? null ),
		);
	}
}
