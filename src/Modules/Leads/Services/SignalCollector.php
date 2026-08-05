<?php
/**
 * Assembling the facts a scoring pass reads.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Leads\Services;

use Hiveclerk\Domain\Conversation\Conversation;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Domain\Conversation\Message;
use Hiveclerk\Domain\Conversation\MessageRepositoryInterface;
use Hiveclerk\Domain\Conversation\MessageRole;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Lead\Scoring\PathPattern;
use Hiveclerk\Domain\Lead\Scoring\ScoreSignals;
use Hiveclerk\Domain\Lead\Visitor;
use Hiveclerk\Domain\Lead\VisitorRepositoryInterface;

/**
 * Reads once, so every rule sees the same lead.
 *
 * The alternative — each rule fetching what it needs — is forty round
 * trips for a forty-rule policy on a path that runs after every visitor
 * message, and it lets two rules disagree about a transcript that grew
 * between them.
 */
final class SignalCollector {

	/**
	 * Characters of transcript a keyword rule may search.
	 *
	 * The transcript is joined into one string and matched with a
	 * word-boundary pattern per term. A conversation at the message cap
	 * is comfortably inside this; the bound exists so that a site which
	 * raises the cap does not quietly turn scoring into the slowest part
	 * of a reply.
	 */
	private const MAX_TRANSCRIPT = 20000;

	/**
	 * Construct.
	 *
	 * @param MessageRepositoryInterface      $messages      Message storage.
	 * @param ConversationRepositoryInterface $conversations Conversation storage.
	 * @param VisitorRepositoryInterface      $visitors      Visitor storage.
	 */
	public function __construct(
		private readonly MessageRepositoryInterface $messages,
		private readonly ConversationRepositoryInterface $conversations,
		private readonly VisitorRepositoryInterface $visitors
	) {
	}

	/**
	 * Everything known about a lead right now.
	 *
	 * @param Lead                    $lead         The lead.
	 * @param Conversation|null       $conversation The conversation being scored, when there is one.
	 * @param array<int, string>|null $said         Visitor messages the caller has already read.
	 * @return ScoreSignals
	 */
	public function collect( Lead $lead, ?Conversation $conversation = null, ?array $said = null ): ScoreSignals {
		$visitorRows = null === $lead->id ? array() : $this->visitors->forLead( $lead->id );
		$earlier     = null === $lead->id ? array() : $this->conversations->forLead( $lead->id );
		// Capture has usually just read the transcript to pull an address
		// out of it. Reading it a second time here would double the query
		// cost of every reply for the same rows.
		$leadTalk = $said ?? $this->visitorMessages( $conversation );
		$pages    = $this->pages( $visitorRows, $earlier, $conversation );

		return new ScoreSignals(
			fields: array(
				'email'      => $lead->email,
				'phone'      => $lead->phone,
				'company'    => $lead->company,
				'job_title'  => $lead->jobTitle,
				'website'    => $lead->website,
				'first_name' => $lead->firstName,
				'last_name'  => $lead->lastName,
			),
			answers: $lead->customFields,
			transcript: $this->transcript( $leadTalk ),
			pages: $pages,
			metrics: array(
				'messages'      => (float) count( $leadTalk ),
				'answers'       => (float) $this->answered( $lead ),
				'page_views'    => (float) $this->totalViews( $visitorRows, $pages ),
				'conversations' => (float) max( count( $earlier ), null === $conversation ? 0 : 1 ),
			),
		);
	}

	/**
	 * The visitor's own words in this conversation.
	 *
	 * Only the visitor's. A keyword rule for "pricing" that also read the
	 * clerk's replies would fire on every conversation where the clerk
	 * mentioned the pricing page — which is most of them, because that is
	 * what a clerk does.
	 *
	 * @param Conversation|null $conversation The conversation.
	 * @return array<int, string>
	 */
	private function visitorMessages( ?Conversation $conversation ): array {
		if ( null === $conversation || null === $conversation->id ) {
			return array();
		}

		$said = array();

		foreach ( $this->messages->transcript( $conversation->id ) as $message ) {
			if ( $message instanceof Message && MessageRole::Visitor === $message->role ) {
				$said[] = $message->content;
			}
		}

		return $said;
	}

	/**
	 * The transcript as one lower-cased haystack.
	 *
	 * @param array<int, string> $messages Visitor messages.
	 * @return string
	 */
	private function transcript( array $messages ): string {
		return mb_strtolower( mb_substr( implode( "\n", $messages ), 0, self::MAX_TRANSCRIPT ) );
	}

	/**
	 * Path visit counts, from the visitor record and the conversations.
	 *
	 * The conversation's own starting page counts as a view even when the
	 * telemetry endpoint never fired. A site with page-view tracking
	 * turned off would otherwise have every page rule permanently at zero
	 * and no way to tell why.
	 *
	 * @param array<int, Visitor>      $visitors      Stitched visitors.
	 * @param array<int, Conversation> $conversations The lead's earlier conversations.
	 * @param Conversation|null        $conversation  Conversation being scored.
	 * @return array<string, int>
	 */
	private function pages( array $visitors, array $conversations, ?Conversation $conversation ): array {
		$pages = array();

		foreach ( $visitors as $visitor ) {
			foreach ( $visitor->pageTally() as $path => $count ) {
				$pages[ $path ] = ( $pages[ $path ] ?? 0 ) + $count;
			}
		}

		$urls = array();

		if ( null !== $conversation && null !== $conversation->pageUrl ) {
			$urls[] = $conversation->pageUrl;
		}

		foreach ( $conversations as $earlier ) {
			if ( null !== $earlier->pageUrl ) {
				$urls[] = $earlier->pageUrl;
			}
		}

		foreach ( array_unique( $urls ) as $url ) {
			$path = PathPattern::normalise( $url );

			if ( ! isset( $pages[ $path ] ) ) {
				$pages[ $path ] = 1;
			}
		}

		return $pages;
	}

	/**
	 * How many qualification questions have an answer.
	 *
	 * @param Lead $lead The lead.
	 * @return int
	 */
	private function answered( Lead $lead ): int {
		$count = 0;

		foreach ( array_keys( $lead->customFields ) as $key ) {
			if ( null !== $lead->answer( (string) $key ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Total page views across every stitched visitor.
	 *
	 * @param array<int, Visitor> $visitors Stitched visitors.
	 * @param array<string, int>  $pages    Merged tally, used when no visitor row exists.
	 * @return int
	 */
	private function totalViews( array $visitors, array $pages ): int {
		if ( array() === $visitors ) {
			return array_sum( $pages );
		}

		$total = 0;

		foreach ( $visitors as $visitor ) {
			$total += $visitor->pageViews;
		}

		return $total;
	}
}
