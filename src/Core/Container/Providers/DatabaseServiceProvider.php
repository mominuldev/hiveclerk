<?php
/**
 * Database service bindings.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Container\Providers;

use Hiveclerk\Core\Container\Container;
use Hiveclerk\Core\Container\ServiceProvider;
use Hiveclerk\Database\Migrations\M0001_Agents;
use Hiveclerk\Database\Migrations\M0002_Knowledge;
use Hiveclerk\Database\Migrations\M0003_Conversations;
use Hiveclerk\Database\Migrations\M0004_Leads;
use Hiveclerk\Database\Migrations\M0005_Email;
use Hiveclerk\Database\Migrations\M0006_Integrations;
use Hiveclerk\Database\Migrations\M0007_Platform;
use Hiveclerk\Database\Migrations\M0008_UsageCostNullable;
use Hiveclerk\Database\Migrations\M0009_ConversationSupervision;
use Hiveclerk\Database\Migrations\M0010_LeadPipeline;
use Hiveclerk\Database\Repositories\ActivityRepository;
use Hiveclerk\Database\Repositories\AuditRepository;
use Hiveclerk\Database\Repositories\EmailLogRepository;
use Hiveclerk\Database\Repositories\EnrollmentRepository;
use Hiveclerk\Database\Repositories\IntegrationLogRepository;
use Hiveclerk\Database\Repositories\IntegrationRepository;
use Hiveclerk\Database\Repositories\LeadRepository;
use Hiveclerk\Database\Repositories\LeadStageRepository;
use Hiveclerk\Database\Repositories\RateLimitRepository;
use Hiveclerk\Database\Repositories\ScoreEventRepository;
use Hiveclerk\Database\Repositories\SequenceRepository;
use Hiveclerk\Database\Repositories\SequenceStepRepository;
use Hiveclerk\Database\Repositories\SuppressionRepository;
use Hiveclerk\Database\Repositories\UsageRepository;
use Hiveclerk\Database\Repositories\VisitorRepository;
use Hiveclerk\Core\Support\RateLimitStoreInterface;
use Hiveclerk\Domain\Audit\AuditRepositoryInterface;
use Hiveclerk\Domain\Email\EmailLogRepositoryInterface;
use Hiveclerk\Domain\Email\EnrollmentRepositoryInterface;
use Hiveclerk\Domain\Email\SequenceRepositoryInterface;
use Hiveclerk\Domain\Email\SequenceStepRepositoryInterface;
use Hiveclerk\Domain\Email\SuppressionRepositoryInterface;
use Hiveclerk\Domain\Integration\IntegrationRepositoryInterface;
use Hiveclerk\Domain\Integration\SyncLogRepositoryInterface;
use Hiveclerk\Domain\Lead\ActivityRepositoryInterface;
use Hiveclerk\Domain\Lead\LeadRepositoryInterface;
use Hiveclerk\Domain\Lead\LeadStageRepositoryInterface;
use Hiveclerk\Domain\Lead\ScoreEventRepositoryInterface;
use Hiveclerk\Domain\Lead\VisitorRepositoryInterface;
use Hiveclerk\Domain\Usage\UsageRepositoryInterface;
use Hiveclerk\Database\Migrator;
use Hiveclerk\Database\Repositories\AgentRepository;
use Hiveclerk\Database\Repositories\CitationRepository;
use Hiveclerk\Database\Repositories\ConversationRepository;
use Hiveclerk\Database\Repositories\KnowledgeSourceRepository;
use Hiveclerk\Database\Repositories\MessageRepository;
use Hiveclerk\Database\Repositories\SessionRepository;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Conversation\CitationRepositoryInterface;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Domain\Conversation\MessageRepositoryInterface;
use Hiveclerk\Domain\Conversation\SessionRepositoryInterface;
use Hiveclerk\Domain\Knowledge\KnowledgeSourceRepositoryInterface;

/**
 * Binds the migrator and the repositories.
 */
final class DatabaseServiceProvider extends ServiceProvider {

	/**
	 * Migrations in registration order. Version numbers, not array order,
	 * determine when each runs.
	 *
	 * @var array<int, class-string<\Hiveclerk\Database\Migration>>
	 */
	private const MIGRATIONS = array(
		M0001_Agents::class,
		M0002_Knowledge::class,
		M0003_Conversations::class,
		M0004_Leads::class,
		M0005_Email::class,
		M0006_Integrations::class,
		M0007_Platform::class,
		M0008_UsageCostNullable::class,
		M0009_ConversationSupervision::class,
		M0010_LeadPipeline::class,
	);

	/**
	 * Register database services.
	 *
	 * @param Container $container Container.
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->singleton(
			Migrator::class,
			static function (): Migrator {
				$migrator = new Migrator();

				foreach ( self::MIGRATIONS as $migration ) {
					$migrator->add( new $migration() );
				}

				return $migrator;
			}
		);

		$container->singleton(
			AgentRepositoryInterface::class,
			static fn (): AgentRepositoryInterface => new AgentRepository()
		);

		$container->singleton(
			KnowledgeSourceRepositoryInterface::class,
			static fn (): KnowledgeSourceRepositoryInterface => new KnowledgeSourceRepository()
		);

		$container->singleton(
			ConversationRepositoryInterface::class,
			static fn (): ConversationRepositoryInterface => new ConversationRepository()
		);

		$container->singleton(
			MessageRepositoryInterface::class,
			static fn (): MessageRepositoryInterface => new MessageRepository()
		);

		$container->singleton(
			CitationRepositoryInterface::class,
			static fn (): CitationRepositoryInterface => new CitationRepository()
		);

		$container->singleton(
			SessionRepositoryInterface::class,
			static fn (): SessionRepositoryInterface => new SessionRepository()
		);

		$container->singleton(
			UsageRepositoryInterface::class,
			static fn (): UsageRepositoryInterface => new UsageRepository()
		);

		$container->singleton(
			AuditRepositoryInterface::class,
			static fn (): AuditRepositoryInterface => new AuditRepository()
		);

		$container->singleton(
			LeadRepositoryInterface::class,
			static fn (): LeadRepositoryInterface => new LeadRepository()
		);

		$container->singleton(
			LeadStageRepositoryInterface::class,
			static fn (): LeadStageRepositoryInterface => new LeadStageRepository()
		);

		$container->singleton(
			ScoreEventRepositoryInterface::class,
			static fn (): ScoreEventRepositoryInterface => new ScoreEventRepository()
		);

		$container->singleton(
			ActivityRepositoryInterface::class,
			static fn (): ActivityRepositoryInterface => new ActivityRepository()
		);

		$container->singleton(
			VisitorRepositoryInterface::class,
			static fn (): VisitorRepositoryInterface => new VisitorRepository()
		);

		$container->singleton(
			IntegrationRepositoryInterface::class,
			static fn (): IntegrationRepositoryInterface => new IntegrationRepository()
		);

		$container->singleton(
			SyncLogRepositoryInterface::class,
			static fn (): SyncLogRepositoryInterface => new IntegrationLogRepository()
		);

		$container->singleton(
			SequenceRepositoryInterface::class,
			static fn (): SequenceRepositoryInterface => new SequenceRepository()
		);

		$container->singleton(
			SequenceStepRepositoryInterface::class,
			static fn (): SequenceStepRepositoryInterface => new SequenceStepRepository()
		);

		$container->singleton(
			EnrollmentRepositoryInterface::class,
			static fn (): EnrollmentRepositoryInterface => new EnrollmentRepository()
		);

		$container->singleton(
			EmailLogRepositoryInterface::class,
			static fn (): EmailLogRepositoryInterface => new EmailLogRepository()
		);

		$container->singleton(
			SuppressionRepositoryInterface::class,
			static fn (): SuppressionRepositoryInterface => new SuppressionRepository()
		);

		$container->singleton(
			RateLimitStoreInterface::class,
			static fn (): RateLimitStoreInterface => new RateLimitRepository()
		);
	}
}
