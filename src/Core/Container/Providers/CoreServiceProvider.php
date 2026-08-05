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
use Hiveclerk\Core\Container\Container;
use Hiveclerk\Core\Container\ServiceProvider;
use Hiveclerk\Core\Events\EventBus;
use Hiveclerk\Core\Module\ModuleRegistry;
use Hiveclerk\Core\Settings\SettingsRepository;
use Hiveclerk\Core\Support\ClockInterface;
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
			AdminPage::class,
			static fn ( Container $c ): AdminPage => new AdminPage(
				$c->get( AssetManifest::class ),
				$c->get( SettingsRepository::class )
			)
		);
	}
}
