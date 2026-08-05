<?php
/**
 * The needs-attention queue.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Analytics\Services;

use DateTimeImmutable;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Agent\AgentStatus;
use Hiveclerk\Domain\Analytics\Alert;
use Hiveclerk\Domain\Analytics\GapRepositoryInterface;
use Hiveclerk\Domain\Analytics\GapStatus;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Domain\Integration\IntegrationRepositoryInterface;
use Hiveclerk\Domain\Integration\SyncLogRepositoryInterface;
use Hiveclerk\Domain\Shared\DateRange;
use Hiveclerk\Domain\Shared\Pagination;

/**
 * Builds the dashboard's work queue from live state, storing nothing.
 *
 * Every item is derived on read, which is what makes D11 §3's promise
 * — "every item disappears once handled" — structural rather than
 * remembered. A stored notification would need a read flag, the flag
 * would need clearing when the underlying thing was fixed somewhere
 * else, and the first time that failed the dashboard would be telling
 * somebody about a handoff another colleague answered yesterday.
 *
 * The empty state is the point of the design: "Nothing needs you right
 * now" is a real answer, and it is only trustworthy if the queue never
 * carries stale items.
 */
final class AlertService {

	/**
	 * Minutes a handoff can wait before it is called out.
	 *
	 * Ten. A visitor who asked for a person and is still watching a chat
	 * window has already decided the clerk failed them, and the window in
	 * which somebody can recover that is short.
	 */
	private const HANDOFF_MINUTES = 10;

	/**
	 * Failures against one integration before it is called out.
	 */
	private const FAILURE_THRESHOLD = 3;

	/**
	 * Construct.
	 *
	 * @param ConversationRepositoryInterface $conversations Conversation storage.
	 * @param AgentRepositoryInterface        $agents        Clerk storage.
	 * @param GapRepositoryInterface          $gaps          Knowledge gaps.
	 * @param IntegrationRepositoryInterface  $integrations  Integrations.
	 * @param SyncLogRepositoryInterface      $syncLog       Sync history.
	 * @param ClockInterface                  $clock         Clock.
	 */
	public function __construct(
		private readonly ConversationRepositoryInterface $conversations,
		private readonly AgentRepositoryInterface $agents,
		private readonly GapRepositoryInterface $gaps,
		private readonly IntegrationRepositoryInterface $integrations,
		private readonly SyncLogRepositoryInterface $syncLog,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * Everything wanting a person right now, most urgent first.
	 *
	 * @return array<int, Alert>
	 */
	public function pending(): array {
		$alerts = array_merge(
			$this->handoffs(),
			$this->pausedClerks(),
			$this->failingIntegrations(),
			$this->openGaps()
		);

		usort(
			$alerts,
			static fn ( Alert $a, Alert $b ): int => $a->weight() <=> $b->weight()
		);

		return $alerts;
	}

	/**
	 * Visitors who asked for a person and are still waiting.
	 *
	 * @return array<int, Alert>
	 */
	private function handoffs(): array {
		$now    = $this->clock->now();
		$alerts = array();

		foreach ( $this->conversations->awaitingHandoff( 20 ) as $conversation ) {
			$since = $conversation->handoffAt ?? $conversation->lastMessageAt ?? $conversation->startedAt;

			if ( ! $since instanceof DateTimeImmutable ) {
				continue;
			}

			$waited = (int) floor( ( $now->getTimestamp() - $since->getTimestamp() ) / 60 );

			if ( $waited < self::HANDOFF_MINUTES ) {
				continue;
			}

			$alerts[] = new Alert(
				'handoff_waiting',
				sprintf(
					/* translators: %s: how long somebody has been waiting, e.g. "14 minutes". */
					__( 'Handoff waiting %s', 'hiveclerk' ),
					$this->duration( $waited )
				),
				$this->excerpt( $conversation->pageTitle ?? $conversation->summary ),
				'/conversations?uuid=' . $conversation->uuid->value,
				$waited >= 30 ? Alert::SEVERITY_URGENT : Alert::SEVERITY_WARNING
			);
		}

		return $alerts;
	}

	/**
	 * Clerks that are configured but not serving anybody.
	 *
	 * @return array<int, Alert>
	 */
	private function pausedClerks(): array {
		$alerts = array();

		foreach ( $this->agents->paginate( new Pagination( 1, 50 ) ) as $agent ) {
			if ( AgentStatus::Paused !== $agent->status ) {
				continue;
			}

			$alerts[] = new Alert(
				'clerk_paused',
				sprintf(
					/* translators: %s: clerk name. */
					__( '%s is paused', 'hiveclerk' ),
					$agent->name
				),
				__( 'Visitors on the pages this clerk serves see nothing.', 'hiveclerk' ),
				'/clerks/' . $agent->uuid->value,
				Alert::SEVERITY_WARNING
			);
		}

		return $alerts;
	}

	/**
	 * Integrations that have been refusing leads.
	 *
	 * @return array<int, Alert>
	 */
	private function failingIntegrations(): array {
		$alerts = array();

		foreach ( $this->integrations->all() as $integration ) {
			if ( null === $integration->id ) {
				continue;
			}

			$failures = $this->syncLog->recentFailures( $integration->id );

			if ( $failures < self::FAILURE_THRESHOLD ) {
				continue;
			}

			$alerts[] = new Alert(
				'integration_failing',
				sprintf(
					/* translators: 1: connector name, 2: failure count. */
					__( '%1$s sync failing (%2$d)', 'hiveclerk' ),
					$integration->provider,
					$failures
				),
				__( 'Leads captured since the first failure have not reached it.', 'hiveclerk' ),
				'/integrations/log',
				Alert::SEVERITY_URGENT,
				$failures
			);
		}

		return $alerts;
	}

	/**
	 * Questions nobody has answered yet.
	 *
	 * One alert for all of them rather than one each: the gaps screen is
	 * the work queue for these, and reproducing it on the dashboard would
	 * bury the three items that need a person today.
	 *
	 * @return array<int, Alert>
	 */
	private function openGaps(): array {
		$week     = DateRange::lastDays( $this->clock->now(), 7 );
		$counts   = $this->gaps->dailyCounts( $week );
		$thisWeek = array_sum( $counts );

		if ( 0 === $thisWeek ) {
			return array();
		}

		$open = $this->gaps->count( GapStatus::Open );

		if ( 0 === $open ) {
			return array();
		}

		return array(
			new Alert(
				'knowledge_gaps',
				sprintf(
					/* translators: %d: number of questions. */
					_n(
						'%d question went unanswered this week.',
						'%d questions went unanswered this week.',
						$thisWeek,
						'hiveclerk'
					),
					$thisWeek
				),
				__( 'Writing one answer fixes it for everybody who asks next.', 'hiveclerk' ),
				'/knowledge/gaps',
				Alert::SEVERITY_WARNING,
				$open
			),
		);
	}

	/**
	 * A wait in minutes, phrased the way a person would say it.
	 *
	 * @param int $minutes Minutes waited.
	 * @return string
	 */
	private function duration( int $minutes ): string {
		if ( $minutes < 60 ) {
			/* translators: %d: minutes. */
			return sprintf( _n( '%d minute', '%d minutes', $minutes, 'hiveclerk' ), $minutes );
		}

		$hours = (int) floor( $minutes / 60 );

		/* translators: %d: hours. */
		return sprintf( _n( '%d hour', '%d hours', $hours, 'hiveclerk' ), $hours );
	}

	/**
	 * A short, safe excerpt for the alert's second line.
	 *
	 * @param string|null $text Source text.
	 * @return string|null
	 */
	private function excerpt( ?string $text ): ?string {
		if ( null === $text || '' === trim( $text ) ) {
			return null;
		}

		$clean = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		return function_exists( 'mb_strlen' ) && mb_strlen( $clean ) > 70
			? rtrim( mb_substr( $clean, 0, 69 ) ) . '…'
			: $clean;
	}
}
