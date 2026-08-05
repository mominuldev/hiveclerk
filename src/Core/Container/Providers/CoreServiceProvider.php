<?php
/**
 * Core service bindings.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Container\Providers;

use Hiveclerk\Core\Admin\AdminPage;
use Hiveclerk\Core\Audit\AuditLogger;
use Hiveclerk\Core\Queue\JobRegistry;
use Hiveclerk\Core\Queue\QueueInterface;
use Hiveclerk\Domain\Audit\AuditRepositoryInterface;
use Hiveclerk\Infrastructure\Queue\ActionSchedulerQueue;
use Hiveclerk\Infrastructure\Queue\CronQueue;
use Hiveclerk\Core\Admin\AssetManifest;
use Hiveclerk\Core\Branding\BrandingService;
use Hiveclerk\Core\Container\Container;
use Hiveclerk\Core\Container\ServiceProvider;
use Hiveclerk\Core\Events\EventBus;
use Hiveclerk\Core\Module\ModuleRegistry;
use Hiveclerk\Core\Privacy\IpHasher;
use Hiveclerk\Core\Privacy\PersonalDataEraser;
use Hiveclerk\Core\Privacy\PersonalDataExporter;
use Hiveclerk\Core\Privacy\PrivacySettings;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Domain\Conversation\MessageRepositoryInterface;
use Hiveclerk\Domain\Email\EmailLogRepositoryInterface;
use Hiveclerk\Domain\Email\SuppressionRepositoryInterface;
use Hiveclerk\Domain\Lead\ActivityRepositoryInterface;
use Hiveclerk\Domain\Lead\LeadRepositoryInterface;
use Hiveclerk\Domain\Lead\VisitorRepositoryInterface;
use Hiveclerk\Core\Licence\LicenceService;
use Hiveclerk\Core\Settings\SettingsRepository;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Knowledge\ChunkQuotaInterface;
use Hiveclerk\Domain\Knowledge\UnlimitedChunkQuota;
use Hiveclerk\Domain\Lead\NullVisitorResolver;
use Hiveclerk\Domain\Lead\VisitorResolverInterface;
use Hiveclerk\Core\Support\SystemClock;

/**
 * Binds the services every module can rely on.
 */
final class CoreServiceProvider extends ServiceProvider {

	/**
	 * Register core services.
	 *
	 * @param Container $container Container.
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->instance( Container::class, $container );

		$container->singleton(
			ClockInterface::class,
			static fn (): ClockInterface => new SystemClock()
		);

		$container->singleton(
			EventBus::class,
			static fn (): EventBus => new EventBus()
		);

		// The leads module rebinds this with the real thing. Bound here so
		// that a site which filters that module out still opens widget
		// sessions instead of failing to build one.
		$container->singleton(
			VisitorResolverInterface::class,
			static fn (): VisitorResolverInterface => new NullVisitorResolver()
		);

		$container->singleton(
			SettingsRepository::class,
			static fn (): SettingsRepository => new SettingsRepository()
		);

		// A null object, replaced by the licence-backed quota in
		// ApiServiceProvider. Ingestion always has one, so there is no
		// nullable collaborator anywhere in the path that creates chunks
		// — a null check somebody forgets there is a cap that does not
		// exist.
		$container->singleton(
			ChunkQuotaInterface::class,
			static fn (): ChunkQuotaInterface => new UnlimitedChunkQuota()
		);

		$container->singleton(
			AuditLogger::class,
			static fn ( Container $c ): AuditLogger => new AuditLogger(
				$c->get( AuditRepositoryInterface::class ),
				$c->get( ClockInterface::class )
			)
		);

		/*
		 * Action Scheduler when a host plugin has loaded it, WP-Cron
		 * otherwise. The choice is made once, here, so nothing downstream
		 * has to ask which one it got — and the health endpoint reports
		 * the answer, because the two have genuinely different
		 * reliability.
		 */
		$container->singleton(
			QueueInterface::class,
			static function (): QueueInterface {
				if ( ActionSchedulerQueue::isAvailable() ) {
					return new ActionSchedulerQueue();
				}

				$queue = new CronQueue();
				$queue->boot();

				return $queue;
			}
		);

		$container->singleton(
			JobRegistry::class,
			static fn (): JobRegistry => new JobRegistry()
		);

		$container->singleton(
			ModuleRegistry::class,
			static fn ( Container $c ): ModuleRegistry => new ModuleRegistry( $c )
		);

		$container->singleton(
			AssetManifest::class,
			static fn (): AssetManifest => new AssetManifest(
				HIVECLERK_DIR . 'assets/admin/',
				HIVECLERK_URL . 'assets/admin/'
			)
		);

		$container->singleton(
			PrivacySettings::class,
			static fn ( Container $c ): PrivacySettings => new PrivacySettings(
				$c->get( SettingsRepository::class ),
				$c->get( ClockInterface::class )
			)
		);

		$container->singleton(
			IpHasher::class,
			static fn ( Container $c ): IpHasher => new IpHasher(
				$c->get( PrivacySettings::class )
			)
		);

		/*
		 * The privacy tools resolve nine repositories between them and are
		 * used on exactly two admin requests in a site's lifetime. Bound
		 * lazily like everything else, so the cost is a closure until
		 * WordPress actually runs a subject access request.
		 */
		$container->singleton(
			PersonalDataExporter::class,
			static fn ( Container $c ): PersonalDataExporter => new PersonalDataExporter(
				$c->get( LeadRepositoryInterface::class ),
				$c->get( ConversationRepositoryInterface::class ),
				$c->get( MessageRepositoryInterface::class ),
				$c->get( VisitorRepositoryInterface::class ),
				$c->get( ActivityRepositoryInterface::class ),
				$c->get( EmailLogRepositoryInterface::class )
			)
		);

		$container->singleton(
			PersonalDataEraser::class,
			static fn ( Container $c ): PersonalDataEraser => new PersonalDataEraser(
				$c->get( LeadRepositoryInterface::class ),
				$c->get( ConversationRepositoryInterface::class ),
				$c->get( VisitorRepositoryInterface::class ),
				$c->get( EmailLogRepositoryInterface::class ),
				$c->get( SuppressionRepositoryInterface::class ),
				$c->get( AuditLogger::class )
			)
		);

		$container->singleton(
			AdminPage::class,
			static fn ( Container $c ): AdminPage => new AdminPage(
				$c->get( AssetManifest::class ),
				$c->get( SettingsRepository::class ),
				$c->get( BrandingService::class ),
				$c->get( LicenceService::class ),
				$c->get( ClockInterface::class )
			)
		);
	}
}
