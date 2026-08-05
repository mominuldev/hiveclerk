<?php
/**
 * Passing a conversation to a person.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Chat\Services;

use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Agent\Agent;
use Hiveclerk\Domain\Conversation\Conversation;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Domain\Conversation\ConversationStatus;
use Hiveclerk\Domain\Conversation\Message;
use Hiveclerk\Domain\Conversation\MessageRepositoryInterface;
use Hiveclerk\Domain\Conversation\MessageRole;
use Hiveclerk\Domain\Shared\Uuid;

/**
 * Human handoff, takeover and reply (FR-WGT-07, FR-CNV-03).
 *
 * One service rather than two because the three actions are one story
 * with three actors: a visitor asks, a colleague is told, a colleague
 * answers. Splitting them across a public service and an admin service
 * would put the status transitions in two files, and a conversation that
 * is `handoff_requested` in one and `handoff_active` in the other is the
 * bug this whole feature exists to prevent.
 *
 * The clerk stops talking the moment a handoff is requested. That is the
 * single most important behaviour here: a visitor who has just asked for
 * a person and gets another AI reply has been told the product is not
 * listening, and no amount of good copy recovers it.
 */
final class HandoffService {

	/**
	 * Construct.
	 *
	 * @param ConversationRepositoryInterface $conversations Conversation storage.
	 * @param MessageRepositoryInterface      $messages      Message storage.
	 * @param ClockInterface                  $clock         Clock.
	 */
	public function __construct(
		private readonly ConversationRepositoryInterface $conversations,
		private readonly MessageRepositoryInterface $messages,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * A visitor asked for a person.
	 *
	 * Idempotent: asking twice does not send two emails and does not reset
	 * the clock on how long they have been waiting, because the waiting
	 * time is what the conversations screen sorts by.
	 *
	 * @param Conversation $conversation The conversation.
	 * @param Agent        $agent        The clerk that was handling it.
	 * @param string|null  $reason       What the visitor typed, if anything.
	 * @return Conversation
	 */
	public function request( Conversation $conversation, Agent $agent, ?string $reason = null ): Conversation {
		if ( ConversationStatus::HandoffRequested === $conversation->status ) {
			return $conversation;
		}

		$alreadyHuman = ConversationStatus::HandoffActive === $conversation->status;

		$conversation->status    = $alreadyHuman ? ConversationStatus::HandoffActive : ConversationStatus::HandoffRequested;
		$conversation->handoffAt = $conversation->handoffAt ?? $this->clock->now();

		// The acknowledgement is written by us, not asked of the model. It
		// is a promise about what happens next, and a promise is not
		// something to generate.
		$this->append(
			$conversation,
			MessageRole::Assistant,
			$this->acknowledgement( $agent ),
			array( 'handoff_requested' )
		);

		$this->conversations->save( $conversation );

		if ( ! $alreadyHuman ) {
			$this->notify( $conversation, $agent, $reason );
		}

		/**
		 * Fires when a visitor asks to speak to a person.
		 *
		 * @param Conversation $conversation The conversation.
		 * @param Agent        $agent        The clerk that was handling it.
		 * @param string|null  $reason       What the visitor typed.
		 */
		do_action( 'hiveclerk/conversation/handoff_requested', $conversation, $agent, $reason );

		return $conversation;
	}

	/**
	 * A staff member takes the conversation over.
	 *
	 * @param Conversation $conversation The conversation.
	 * @param int          $userId       WordPress user id.
	 * @return Conversation
	 */
	public function takeover( Conversation $conversation, int $userId ): Conversation {
		$conversation->status        = ConversationStatus::HandoffActive;
		$conversation->handoffUserId = $userId;
		$conversation->handoffAt     = $conversation->handoffAt ?? $this->clock->now();
		// A conversation a person had to finish was not resolved by the
		// clerk, whatever it looked like up to that point. Leaving the
		// flag set would inflate the one number the dashboard reports as
		// the product working.
		$conversation->resolvedByAi = false;

		$this->conversations->save( $conversation );

		/**
		 * Fires when a staff member takes over a conversation.
		 *
		 * @param Conversation $conversation The conversation.
		 * @param int          $userId       Who took it.
		 */
		do_action( 'hiveclerk/conversation/taken_over', $conversation, $userId );

		return $conversation;
	}

	/**
	 * A staff member replies as themselves.
	 *
	 * @param Conversation $conversation The conversation.
	 * @param int          $userId       WordPress user id.
	 * @param string       $text         The reply, sanitised at the boundary.
	 * @return Message
	 */
	public function reply( Conversation $conversation, int $userId, string $text ): Message {
		if ( ConversationStatus::HandoffActive !== $conversation->status ) {
			// Replying is itself a takeover. An operator who types an
			// answer has taken the conversation whether or not they
			// pressed the button first, and leaving the clerk live
			// afterwards would have the two of them answering together.
			$this->takeover( $conversation, $userId );
		}

		$message = $this->append(
			$conversation,
			MessageRole::HumanAgent,
			$text,
			array(),
			$userId
		);

		$this->conversations->save( $conversation );

		/**
		 * Fires when a human reply is stored.
		 *
		 * @param Message      $message      The reply.
		 * @param Conversation $conversation The conversation.
		 * @param int          $userId       Who wrote it.
		 */
		do_action( 'hiveclerk/conversation/human_replied', $message, $conversation, $userId );

		return $message;
	}

	/**
	 * Close a conversation.
	 *
	 * @param Conversation $conversation The conversation.
	 * @param bool         $byAi         Whether the clerk resolved it unaided.
	 * @return Conversation
	 */
	public function resolve( Conversation $conversation, bool $byAi = false ): Conversation {
		$conversation->status       = ConversationStatus::Resolved;
		$conversation->endedAt      = $this->clock->now();
		$conversation->resolvedByAi = $byAi;

		return $this->conversations->save( $conversation );
	}

	/**
	 * Store one message and advance the conversation's counter.
	 *
	 * @param Conversation       $conversation The conversation.
	 * @param MessageRole        $role         Author.
	 * @param string             $content      Text.
	 * @param array<int, string> $flags        Guardrail flags.
	 * @param int|null           $userId       Staff member, for a human reply.
	 * @return Message
	 */
	private function append(
		Conversation $conversation,
		MessageRole $role,
		string $content,
		array $flags = array(),
		?int $userId = null
	): Message {
		$message = $this->messages->save(
			new Message(
				id: null,
				uuid: Uuid::generate(),
				conversationId: (int) $conversation->id,
				role: $role,
				content: $content,
				wpUserId: $userId,
				guardrailFlags: $flags
			)
		);

		++$conversation->messageCount;
		$conversation->lastMessageAt = $this->clock->now();

		return $message;
	}

	/**
	 * What the visitor is told while they wait.
	 *
	 * @param Agent $agent The clerk.
	 * @return string
	 */
	private function acknowledgement( Agent $agent ): string {
		$configured = $agent->widgetConfig['handoff_message'] ?? null;

		if ( is_string( $configured ) && '' !== trim( $configured ) ) {
			return trim( $configured );
		}

		return __(
			"I've passed this to a colleague. They'll reply here — leave the chat open, or leave your email and they'll write to you.",
			'hiveclerk'
		);
	}

	/**
	 * Tell staff somebody is waiting.
	 *
	 * Sent with `wp_mail()`, which means it inherits whatever the site has
	 * configured for mail — including nothing. The send result is recorded
	 * on the action below rather than swallowed, because "the email did
	 * not arrive" is the failure mode of every handoff feature ever
	 * shipped, and a site with no SMTP has to be able to find that out.
	 *
	 * @param Conversation $conversation The conversation.
	 * @param Agent        $agent        The clerk.
	 * @param string|null  $reason       What the visitor typed.
	 * @return void
	 */
	private function notify( Conversation $conversation, Agent $agent, ?string $reason ): void {
		$recipients = $this->recipients();

		if ( array() === $recipients ) {
			return;
		}

		$link = add_query_arg(
			array(
				'page' => 'hiveclerk',
			),
			admin_url( 'admin.php' )
		) . '#/conversations/' . $conversation->uuid->value;

		$lines = array(
			sprintf(
				/* translators: %s: name of the clerk that was handling the conversation. */
				__( 'A visitor talking to %s has asked for a person.', 'hiveclerk' ),
				$agent->name
			),
			'',
		);

		if ( null !== $reason && '' !== trim( $reason ) ) {
			$lines[] = __( 'What they said:', 'hiveclerk' );
			$lines[] = mb_substr( trim( $reason ), 0, 500 );
			$lines[] = '';
		}

		if ( null !== $conversation->pageUrl ) {
			$lines[] = sprintf(
				/* translators: %s: URL of the page the conversation started on. */
				__( 'Page: %s', 'hiveclerk' ),
				$conversation->pageUrl
			);
		}

		$lines[] = sprintf(
			/* translators: %s: link to the conversation in the admin. */
			__( 'Open the conversation: %s', 'hiveclerk' ),
			$link
		);

		$sent = wp_mail(
			$recipients,
			__( 'Someone is waiting to talk to a person', 'hiveclerk' ),
			implode( "\n", $lines )
		);

		/**
		 * Fires after a handoff notification was attempted.
		 *
		 * @param bool               $sent         Whether wp_mail() accepted it.
		 * @param array<int, string> $recipients   Who it went to.
		 * @param Conversation       $conversation The conversation.
		 */
		do_action( 'hiveclerk/conversation/handoff_notified', $sent, $recipients, $conversation );
	}

	/**
	 * Who hears about a handoff.
	 *
	 * @return array<int, string>
	 */
	private function recipients(): array {
		$default = get_option( 'admin_email' );
		$emails  = is_string( $default ) && is_email( $default ) ? array( $default ) : array();

		/**
		 * Filter who is emailed when a visitor asks for a person.
		 *
		 * @param array<int, string> $emails Recipient addresses.
		 */
		$filtered = apply_filters( 'hiveclerk/handoff/recipients', $emails );

		if ( ! is_array( $filtered ) ) {
			return $emails;
		}

		return array_values(
			array_filter(
				array_map( 'strval', $filtered ),
				static fn ( string $email ): bool => (bool) is_email( $email )
			)
		);
	}
}
