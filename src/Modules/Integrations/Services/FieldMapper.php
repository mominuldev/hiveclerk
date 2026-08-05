<?php
/**
 * Lead to payload mapping.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Integrations\Services;

use Hiveclerk\Domain\Conversation\MessageRepositoryInterface;
use Hiveclerk\Domain\Conversation\Message;
use Hiveclerk\Domain\Conversation\MessageRole;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Domain\Integration\FieldMap;
use Hiveclerk\Domain\Integration\Integration;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Lead\LeadStageRepositoryInterface;

/**
 * Turns a lead into the payload one connector expects (FR-CRM-07).
 *
 * ## Blank fields are omitted, not sent empty
 *
 * A push carrying `company => ""` overwrites a company name a
 * salesperson typed into the CRM by hand this morning. Every connector
 * here upserts, so every push is also an update, and "we know nothing
 * about this field" must not be expressed as "this field is now nothing".
 * A missing key leaves the far side alone; an empty string does not.
 *
 * ## The transcript is opt-in and truncated
 *
 * It is the most sensitive thing this plugin holds and the largest.
 * Sending it needs the operator to have said so on the integration, and
 * what gets sent is capped — a two-hundred-turn conversation is rejected
 * by some destinations outright and silently truncated mid-sentence by
 * others, which is worse.
 */
final class FieldMapper {

	/**
	 * Characters of transcript that may be sent.
	 */
	private const MAX_TRANSCRIPT = 8000;

	/**
	 * Conversations read when building a transcript.
	 */
	private const MAX_CONVERSATIONS = 3;

	/**
	 * Construct.
	 *
	 * @param LeadStageRepositoryInterface    $stages        Stage lookup.
	 * @param ConversationRepositoryInterface $conversations Conversation lookup.
	 * @param MessageRepositoryInterface      $messages      Transcript source.
	 */
	public function __construct(
		private readonly LeadStageRepositoryInterface $stages,
		private readonly ConversationRepositoryInterface $conversations,
		private readonly MessageRepositoryInterface $messages
	) {
	}

	/**
	 * Build the payload for one push.
	 *
	 * @param Lead        $lead        The lead.
	 * @param Integration $integration The connection, for its map and options.
	 * @return array<string, mixed>
	 */
	public function build( Lead $lead, Integration $integration ): array {
		$payload = array();

		foreach ( $integration->fieldMap->toArray() as $source => $target ) {
			if ( 'transcript' === $source && ! $integration->sendsTranscript() ) {
				continue;
			}

			$value = $this->value( $lead, $source );

			if ( null === $value || '' === $value ) {
				continue;
			}

			$payload[ $target ] = $value;
		}

		/**
		 * The payload about to be sent to one connector.
		 *
		 * Fires after mapping and before the request, so a site can add a
		 * field no mapping screen offers — a UTM parameter it stores
		 * itself, an account manager it assigns by rule.
		 *
		 * @param array<string, mixed> $payload     Mapped values.
		 * @param Lead                 $lead        The lead.
		 * @param Integration          $integration The connection.
		 */
		$filtered = apply_filters( 'hiveclerk/crm/payload', $payload, $lead, $integration );

		return is_array( $filtered ) ? $filtered : $payload;
	}

	/**
	 * One source field's value, as a string.
	 *
	 * @param Lead   $lead   The lead.
	 * @param string $source Source name.
	 * @return string|null
	 */
	public function value( Lead $lead, string $source ): ?string {
		if ( str_starts_with( $source, FieldMap::ANSWER_PREFIX ) ) {
			return $lead->answer( substr( $source, strlen( FieldMap::ANSWER_PREFIX ) ) );
		}

		return match ( $source ) {
			'email'      => $lead->email,
			'first_name' => $lead->firstName,
			'last_name'  => $lead->lastName,
			'phone'      => $lead->phone,
			'company'    => $lead->company,
			'job_title'  => $lead->jobTitle,
			'website'    => $lead->website,
			'score'      => (string) $lead->score,
			'band'       => $lead->band->label(),
			'status'     => $lead->status->value,
			'stage'      => $this->stageName( $lead ),
			'source'     => $lead->source,
			'transcript' => $this->transcript( $lead ),
			default      => null,
		};
	}

	/**
	 * Which sources a lead can actually fill, for the mapping screen.
	 *
	 * Includes the qualification answers this site has collected, because
	 * those are whatever the customer configured on their clerk and there
	 * is no other list of them anywhere.
	 *
	 * @param array<int, string> $answerKeys Qualification question keys in use.
	 * @return array<int, array<string, mixed>>
	 */
	public function sources( array $answerKeys = array() ): array {
		$labels = array(
			'email'      => __( 'Email', 'hiveclerk' ),
			'first_name' => __( 'First name', 'hiveclerk' ),
			'last_name'  => __( 'Last name', 'hiveclerk' ),
			'phone'      => __( 'Phone', 'hiveclerk' ),
			'company'    => __( 'Company', 'hiveclerk' ),
			'job_title'  => __( 'Job title', 'hiveclerk' ),
			'website'    => __( 'Website', 'hiveclerk' ),
			'score'      => __( 'Score', 'hiveclerk' ),
			'band'       => __( 'Band', 'hiveclerk' ),
			'status'     => __( 'Status', 'hiveclerk' ),
			'stage'      => __( 'Pipeline stage', 'hiveclerk' ),
			'source'     => __( 'Captured by', 'hiveclerk' ),
			'transcript' => __( 'Transcript', 'hiveclerk' ),
		);

		$sources = array();

		foreach ( FieldMap::SOURCES as $source ) {
			$sources[] = array(
				'key'       => $source,
				'label'     => $labels[ $source ] ?? $source,
				'locked'    => 'email' === $source,
				'sensitive' => 'transcript' === $source,
			);
		}

		foreach ( $answerKeys as $key ) {
			$sources[] = array(
				'key'       => FieldMap::ANSWER_PREFIX . $key,
				'label'     => sprintf(
					/* translators: %s: qualification question key. */
					__( 'Qualification: %s', 'hiveclerk' ),
					$key
				),
				'locked'    => false,
				'sensitive' => false,
			);
		}

		return $sources;
	}

	/**
	 * The lead's pipeline column, by name.
	 *
	 * @param Lead $lead The lead.
	 * @return string|null
	 */
	private function stageName( Lead $lead ): ?string {
		if ( null === $lead->stageId ) {
			return null;
		}

		$stage = $this->stages->find( $lead->stageId );

		return null === $stage ? null : $stage->name;
	}

	/**
	 * The lead's conversations, flattened into text.
	 *
	 * @param Lead $lead The lead.
	 * @return string|null
	 */
	private function transcript( Lead $lead ): ?string {
		if ( null === $lead->id ) {
			return null;
		}

		$lines = array();

		foreach ( $this->conversations->forLead( $lead->id, self::MAX_CONVERSATIONS ) as $conversation ) {
			if ( null === $conversation->id ) {
				continue;
			}

			foreach ( $this->messages->transcript( $conversation->id ) as $message ) {
				$line = $this->line( $message );

				if ( null !== $line ) {
					$lines[] = $line;
				}
			}
		}

		if ( array() === $lines ) {
			return null;
		}

		$text = implode( "\n", $lines );

		if ( strlen( $text ) <= self::MAX_TRANSCRIPT ) {
			return $text;
		}

		// Truncated from the front. The end of a conversation is where the
		// commitment is — a phone number, a date, "yes, send it over" —
		// and the greeting is where nothing is.
		return '…' . substr( $text, -self::MAX_TRANSCRIPT );
	}

	/**
	 * One transcript line, or null for a message with nothing in it.
	 *
	 * @param Message $message Message.
	 * @return string|null
	 */
	private function line( Message $message ): ?string {
		$content = trim( $message->content );

		if ( '' === $content ) {
			return null;
		}

		$speaker = match ( $message->role ) {
			MessageRole::Visitor    => __( 'Visitor', 'hiveclerk' ),
			MessageRole::Assistant  => __( 'Clerk', 'hiveclerk' ),
			MessageRole::HumanAgent => __( 'Staff', 'hiveclerk' ),
			default                 => null,
		};

		return null === $speaker ? null : $speaker . ': ' . $content;
	}
}
