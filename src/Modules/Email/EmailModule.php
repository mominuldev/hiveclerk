<?php
/**
 * Email module.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Email;

use Hiveclerk\Ai\AiServiceInterface;
use Hiveclerk\Api\RestServer;
use Hiveclerk\Core\Audit\AuditLogger;
use Hiveclerk\Core\Capabilities\Capabilities;
use Hiveclerk\Core\Container\Container;
use Hiveclerk\Core\Module\AbstractModule;
use Hiveclerk\Core\Queue\JobRegistry;
use Hiveclerk\Core\Queue\QueueInterface;
use Hiveclerk\Core\Settings\SettingsRepository;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Core\Support\RateLimiter;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Conversation\MessageRepositoryInterface;
use Hiveclerk\Domain\Email\EmailLogRepositoryInterface;
use Hiveclerk\Domain\Email\EnrollmentRepositoryInterface;
use Hiveclerk\Domain\Email\SequenceRepositoryInterface;
use Hiveclerk\Domain\Email\SequenceStepRepositoryInterface;
use Hiveclerk\Domain\Email\SuppressionRepositoryInterface;
use Hiveclerk\Domain\Email\TriggerType;
use Hiveclerk\Domain\Lead\ActivityRepositoryInterface;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Lead\LeadRepositoryInterface;
use Hiveclerk\Modules\Email\Http\SequenceController;
use Hiveclerk\Modules\Email\Http\UnsubscribeController;
use Hiveclerk\Modules\Email\Jobs\SequenceTickJob;
use Hiveclerk\Modules\Email\Services\CopyGenerator;
use Hiveclerk\Modules\Email\Services\EmailRenderer;
use Hiveclerk\Modules\Email\Services\EmailSender;
use Hiveclerk\Modules\Email\Services\EnrolmentService;
use Hiveclerk\Modules\Email\Services\MergeTags;
use Hiveclerk\Modules\Email\Services\SequenceEngine;
use Hiveclerk\Modules\Email\Services\SequenceService;
use Hiveclerk\Modules\Email\Services\SuppressionList;
use Hiveclerk\Modules\Email\Services\UnsubscribeTokens;

/**
 * Follow-up email (FR-EML-01…08).
 *
 * Listens to the same lead events the Integrations module does and for
 * the same reason: whether a lead should be emailed is a question about
 * email, not about leads, and answering it inside `ScoringService` would
 * put a mail decision in the middle of the code that spends the
 * customer's money.
 *
 * ## The tick is scheduled at boot, every request, deliberately
 *
 * `scheduleRecurring()` on both drivers is idempotent — it checks for an
 * existing schedule before adding one. Scheduling on activation instead
 * would mean a site that upgraded from a version without this module
 * never gets a tick, and the symptom is a sequence that enrols people and
 * never sends: the hardest kind of bug to notice, because everything
 * looks configured.
 */
final class EmailModule extends AbstractModule {

	/**
	 * Machine identifier.
	 *
	 * @return string
	 */
	public static function id(): string {
		return 'email';
	}

	/**
	 * Bind services.
	 *
	 * @param Container $container Container.
	 * @return void
	 */
	public function register( Container $container ): void {
		parent::register( $container );

		$container->singleton( MergeTags::class, static fn (): MergeTags => new MergeTags() );
		$container->singleton( UnsubscribeTokens::class, static fn (): UnsubscribeTokens => new UnsubscribeTokens() );

		$container->singleton(
			EmailRenderer::class,
			static fn ( Container $c ): EmailRenderer => new EmailRenderer(
				$c->get( MergeTags::class ),
				$c->get( UnsubscribeTokens::class )
			)
		);

		$container->singleton(
			SuppressionList::class,
			static fn ( Container $c ): SuppressionList => new SuppressionList(
				$c->get( SuppressionRepositoryInterface::class ),
				$c->get( EnrollmentRepositoryInterface::class ),
				$c->get( LeadRepositoryInterface::class ),
				$c->get( ActivityRepositoryInterface::class ),
				$c->get( ClockInterface::class )
			)
		);

		$container->singleton(
			EmailSender::class,
			static fn ( Container $c ): EmailSender => new EmailSender(
				$c->get( EmailLogRepositoryInterface::class ),
				$c->get( SuppressionList::class ),
				$c->get( ActivityRepositoryInterface::class ),
				$c->get( SettingsRepository::class ),
				$c->get( ClockInterface::class )
			)
		);

		$container->singleton(
			EnrolmentService::class,
			static fn ( Container $c ): EnrolmentService => new EnrolmentService(
				$c->get( SequenceRepositoryInterface::class ),
				$c->get( SequenceStepRepositoryInterface::class ),
				$c->get( EnrollmentRepositoryInterface::class ),
				$c->get( SuppressionList::class ),
				$c->get( ClockInterface::class )
			)
		);

		$container->singleton(
			SequenceEngine::class,
			static fn ( Container $c ): SequenceEngine => new SequenceEngine(
				$c->get( EnrollmentRepositoryInterface::class ),
				$c->get( SequenceRepositoryInterface::class ),
				$c->get( SequenceStepRepositoryInterface::class ),
				$c->get( EmailLogRepositoryInterface::class ),
				$c->get( LeadRepositoryInterface::class ),
				$c->get( EmailRenderer::class ),
				$c->get( EmailSender::class ),
				$c->get( EnrolmentService::class ),
				$c->get( ClockInterface::class )
			)
		);

		$container->singleton(
			SequenceService::class,
			static fn ( Container $c ): SequenceService => new SequenceService(
				$c->get( SequenceRepositoryInterface::class ),
				$c->get( SequenceStepRepositoryInterface::class ),
				$c->get( EnrollmentRepositoryInterface::class ),
				$c->get( EmailLogRepositoryInterface::class ),
				$c->get( AuditLogger::class ),
				$c->get( ClockInterface::class )
			)
		);

		$container->singleton(
			CopyGenerator::class,
			static fn ( Container $c ): CopyGenerator => new CopyGenerator(
				$c->get( AiServiceInterface::class ),
				$c->get( MessageRepositoryInterface::class )
			)
		);

		$container->singleton(
			SequenceTickJob::class,
			static fn ( Container $c ): SequenceTickJob => new SequenceTickJob(
				$c->get( SequenceEngine::class ),
				$c->get( QueueInterface::class )
			)
		);

		$container->singleton(
			SequenceController::class,
			static fn ( Container $c ): SequenceController => new SequenceController(
				$c->get( SequenceService::class ),
				$c->get( SequenceRepositoryInterface::class ),
				$c->get( SequenceStepRepositoryInterface::class ),
				$c->get( EmailLogRepositoryInterface::class ),
				$c->get( LeadRepositoryInterface::class ),
				$c->get( AgentRepositoryInterface::class ),
				$c->get( CopyGenerator::class ),
				$c->get( EmailRenderer::class ),
				$c->get( MergeTags::class ),
				$c->get( SuppressionList::class )
			)
		);

		$container->singleton(
			UnsubscribeController::class,
			static fn ( Container $c ): UnsubscribeController => new UnsubscribeController(
				$c->get( UnsubscribeTokens::class ),
				$c->get( SuppressionList::class ),
				$c->get( RateLimiter::class )
			)
		);
	}

	/**
	 * Attach hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action(
			'hiveclerk/rest/register',
			function ( RestServer $server ): void {
				$server->add( $this->container->get( SequenceController::class ) );
				$server->add( $this->container->get( UnsubscribeController::class ) );
			}
		);

		add_action(
			'hiveclerk/jobs/register',
			function ( JobRegistry $jobs ): void {
				$jobs->add( $this->container->get( SequenceTickJob::class ) );

				$this->container->get( QueueInterface::class )->scheduleRecurring(
					SequenceTickJob::INTERVAL,
					SequenceTickJob::hook()
				);
			}
		);

		add_action(
			'hiveclerk/lead/captured',
			function ( Lead $lead ): void {
				$this->enrolment()->onTrigger( $lead, TriggerType::LeadCreated );
			}
		);

		add_action(
			'hiveclerk/lead/qualified',
			function ( Lead $lead ): void {
				$this->enrolment()->onTrigger( $lead, TriggerType::ScoreThreshold );
			}
		);

		add_action(
			'hiveclerk/lead/stage_changed',
			function ( Lead $lead, ?int $stageId ): void {
				$enrolment = $this->enrolment();

				// Exits run before enrolments. A lead moved into "Won"
				// should leave the nurture sequence, not be enrolled in the
				// next one and then removed from it a millisecond later —
				// which would leave a completed enrolment row nobody can
				// explain.
				$enrolment->applyExitConditions( $lead );
				$enrolment->onTrigger( $lead, TriggerType::StageChanged, $stageId );
			},
			10,
			2
		);
	}

	/**
	 * Enrolment, resolved late.
	 *
	 * @return EnrolmentService
	 */
	private function enrolment(): EnrolmentService {
		return $this->container->get( EnrolmentService::class );
	}

	/**
	 * Capabilities this module requires.
	 *
	 * @return array<int, string>
	 */
	public function capabilities(): array {
		return array( Capabilities::MANAGE_LEADS );
	}
}
