<?php
/**
 * AI service bindings.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Container\Providers;

use Hiveclerk\Ai\AiService;
use Hiveclerk\Ai\Http\HttpClientInterface;
use Hiveclerk\Ai\KeyResolver;
use Hiveclerk\Ai\PricingTable;
use Hiveclerk\Ai\ProviderRegistry;
use Hiveclerk\Ai\Providers\AnthropicProvider;
use Hiveclerk\Ai\Providers\AzureOpenAiProvider;
use Hiveclerk\Ai\Providers\GoogleProvider;
use Hiveclerk\Ai\Providers\OpenAiProvider;
use Hiveclerk\Ai\Providers\OpenRouterProvider;
use Hiveclerk\Core\Container\Container;
use Hiveclerk\Core\Container\ServiceProvider;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Core\Support\Encryptor;
use Hiveclerk\Domain\Usage\UsageRepositoryInterface;
use Hiveclerk\Infrastructure\Http\WpHttpClient;

/**
 * Binds the model providers and the service in front of them.
 */
final class AiServiceProvider extends ServiceProvider {

	/**
	 * The five first-party adapters.
	 *
	 * @var array<int, class-string<\Hiveclerk\Ai\Providers\AbstractProvider>>
	 */
	private const PROVIDERS = array(
		AnthropicProvider::class,
		OpenAiProvider::class,
		GoogleProvider::class,
		AzureOpenAiProvider::class,
		OpenRouterProvider::class,
	);

	/**
	 * Register AI services.
	 *
	 * @param Container $container Container.
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->singleton(
			HttpClientInterface::class,
			static fn (): HttpClientInterface => new WpHttpClient()
		);

		$container->singleton(
			PricingTable::class,
			static fn (): PricingTable => new PricingTable()
		);

		$container->singleton(
			KeyResolver::class,
			static fn ( Container $c ): KeyResolver => new KeyResolver(
				$c->get( Encryptor::class ),
				$c->get( ClockInterface::class )
			)
		);

		$container->singleton(
			ProviderRegistry::class,
			static function ( Container $c ): ProviderRegistry {
				$registry = new ProviderRegistry();
				$http     = $c->get( HttpClientInterface::class );
				$pricing  = $c->get( PricingTable::class );

				foreach ( self::PROVIDERS as $provider ) {
					$registry->add( new $provider( $http, $pricing ) );
				}

				return $registry;
			}
		);

		$container->singleton(
			AiService::class,
			static fn ( Container $c ): AiService => new AiService(
				$c->get( ProviderRegistry::class ),
				$c->get( KeyResolver::class ),
				$c->get( UsageRepositoryInterface::class ),
				$c->get( PricingTable::class )
			)
		);
	}
}
