<?php
/**
 * Onboarding module.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Onboarding;

use Hiveclerk\Ai\PricingTable;
use Hiveclerk\Api\RestServer;
use Hiveclerk\Core\Capabilities\Capabilities;
use Hiveclerk\Core\Container\Container;
use Hiveclerk\Core\Module\AbstractModule;
use Hiveclerk\Core\Settings\SettingsRepository;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Core\Support\RateLimiter;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Knowledge\KnowledgeSourceRepositoryInterface;
use Hiveclerk\Modules\Onboarding\Http\OnboardingController;
use Hiveclerk\Modules\Onboarding\Services\OnboardingState;
use Hiveclerk\Modules\Onboarding\Services\SourceDetector;

/**
 * The five-step wizard that carries the activation metric (FR-ONB-01,
 * 04, 05; PG-1).
 *
 * Small, because it should be: the wizard drives the endpoints the rest
 * of the product already exposes and records what they produced. See
 * {@see OnboardingState} for why that is not laziness.
 */
final class OnboardingModule extends AbstractModule {

	/**
	 * Machine identifier.
	 *
	 * @return string
	 */
	public static function id(): string {
		return 'onboarding';
	}

	/**
	 * Bind services.
	 *
	 * @param Container $container Container.
	 * @return void
	 */
	public function register( Container $container ): void {
		parent::register( $container );

		$container->singleton(
			OnboardingState::class,
			static fn ( Container $c ): OnboardingState => new OnboardingState(
				$c->get( AgentRepositoryInterface::class ),
				$c->get( KnowledgeSourceRepositoryInterface::class ),
				$c->get( ClockInterface::class )
			)
		);

		$container->singleton(
			SourceDetector::class,
			static fn ( Container $c ): SourceDetector => new SourceDetector(
				$c->get( SettingsRepository::class ),
				$c->get( PricingTable::class )
			)
		);

		$container->singleton(
			OnboardingController::class,
			static fn ( Container $c ): OnboardingController => new OnboardingController(
				$c->get( OnboardingState::class ),
				$c->get( SourceDetector::class ),
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
				$server->add( $this->container->get( OnboardingController::class ) );
			}
		);
	}

	/**
	 * Capabilities this module requires.
	 *
	 * @return array<int, string>
	 */
	public function capabilities(): array {
		return array( Capabilities::MANAGE_SETTINGS );
	}
}
