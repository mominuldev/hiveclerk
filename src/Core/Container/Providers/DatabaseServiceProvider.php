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
use Hiveclerk\Database\Repositories\AuditRepository;
use Hiveclerk\Database\Repositories\UsageRepository;
use Hiveclerk\Domain\Audit\AuditRepositoryInterface;
use Hiveclerk\Domain\Usage\UsageRepositoryInterface;
use Hiveclerk\Database\Migrator;
use Hiveclerk\Database\Repositories\AgentRepository;
use Hiveclerk\Database\Repositories\ConversationRepository;
use Hiveclerk\Database\Repositories\KnowledgeSourceRepository;
use Hiveclerk\Database\Repositories\MessageRepository;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Domain\Conversation\MessageRepositoryInterface;
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
			UsageRepositoryInterface::class,
			static fn (): UsageRepositoryInterface => new UsageRepository()
		);

		$container->singleton(
			AuditRepositoryInterface::class,
			static fn (): AuditRepositoryInterface => new AuditRepository()
		);
	}
}
