<?php
/**
 * API service bindings.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Container\Providers;

use Hiveclerk\Ai\AiService;
use Hiveclerk\Ai\KeyResolver;
use Hiveclerk\Api\Controllers\AuditController;
use Hiveclerk\Api\Controllers\ProvidersController;
use Hiveclerk\Api\Controllers\StreamController;
use Hiveclerk\Api\Controllers\SystemController;
use Hiveclerk\Api\Controllers\UsageController;
use Hiveclerk\Api\Streaming\SseStream;
use Hiveclerk\Api\Streaming\StreamEnvironment;
use Hiveclerk\Core\Audit\AuditLogger;
use Hiveclerk\Core\Queue\QueueInterface;
use Hiveclerk\Domain\Audit\AuditRepositoryInterface;
use Hiveclerk\Domain\Usage\UsageRepositoryInterface;
use Hiveclerk\Api\RestServer;
use Hiveclerk\Core\Container\Container;
use Hiveclerk\Core\Container\ServiceProvider;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Core\Support\RateLimitStoreInterface;
use Hiveclerk\Core\Support\Encryptor;
use Hiveclerk\Core\Support\RateLimiter;
use Hiveclerk\Database\Migrator;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Domain\Knowledge\KnowledgeSourceRepositoryInterface;

/**
 * Binds the REST server, its controllers and the security primitives.
 */
final class ApiServiceProvider extends ServiceProvider {

	/**
	 * Register API services.
	 *
	 * @param Container $container Container.
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->singleton(
			Encryptor::class,
			static fn (): Encryptor => new Encryptor()
		);

		$container->singleton(
			RateLimiter::class,
			static fn ( Container $c ): RateLimiter => new RateLimiter(
				$c->get( ClockInterface::class ),
				$c->get( RateLimitStoreInterface::class )
			)
		);

		$container->singleton(
			SystemController::class,
			static fn ( Container $c ): SystemController => new SystemController(
				$c->get( Migrator::class ),
				$c->get( ClockInterface::class ),
				$c->get( RateLimiter::class ),
				$c->get( AgentRepositoryInterface::class ),
				$c->get( ConversationRepositoryInterface::class ),
				$c->get( KnowledgeSourceRepositoryInterface::class ),
				$c->get( QueueInterface::class )
			)
		);

		$container->singleton(
			ProvidersController::class,
			static fn ( Container $c ): ProvidersController => new ProvidersController(
				$c->get( AiService::class ),
				$c->get( KeyResolver::class ),
				$c->get( AuditLogger::class ),
				$c->get( RateLimiter::class )
			)
		);

		$container->singleton(
			AuditController::class,
			static fn ( Container $c ): AuditController => new AuditController(
				$c->get( AuditRepositoryInterface::class )
			)
		);

		$container->singleton(
			UsageController::class,
			static fn ( Container $c ): UsageController => new UsageController(
				$c->get( UsageRepositoryInterface::class ),
				$c->get( ClockInterface::class )
			)
		);

		$container->singleton(
			StreamEnvironment::class,
			static fn (): StreamEnvironment => new StreamEnvironment()
		);

		// Not a singleton. A stream is one connection with its own open
		// and abort state; sharing an instance across two of them would
		// let the first one's teardown decide the second one's headers.
		$container->bind(
			SseStream::class,
			static fn (): SseStream => new SseStream()
		);

		$container->singleton(
			StreamController::class,
			static fn ( Container $c ): StreamController => new StreamController(
				$c->get( SseStream::class ),
				$c->get( StreamEnvironment::class ),
				$c->get( RateLimiter::class )
			)
		);

		$container->singleton(
			RestServer::class,
			static function ( Container $c ): RestServer {
				$server = new RestServer();
				$server->add( $c->get( SystemController::class ) );
				$server->add( $c->get( StreamController::class ) );
				$server->add( $c->get( ProvidersController::class ) );
				$server->add( $c->get( AuditController::class ) );
				$server->add( $c->get( UsageController::class ) );

				return $server;
			}
		);
	}
}
