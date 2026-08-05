<?php
/**
 * Agents module.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Agents;

use Hiveclerk\Ai\AiServiceInterface;
use Hiveclerk\Ai\PricingTable;
use Hiveclerk\Api\RestServer;
use Hiveclerk\Core\Audit\AuditLogger;
use Hiveclerk\Core\Capabilities\Capabilities;
use Hiveclerk\Core\Container\Container;
use Hiveclerk\Core\Module\AbstractModule;
use Hiveclerk\Core\Settings\SettingsRepository;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Core\Support\RateLimiter;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Domain\Knowledge\KnowledgeSourceRepositoryInterface;
use Hiveclerk\Domain\Knowledge\RetrievalServiceInterface;
use Hiveclerk\Modules\Agents\Http\AgentController;
use Hiveclerk\Modules\Agents\Services\AgentService;
use Hiveclerk\Modules\Agents\Services\BudgetGuard;
use Hiveclerk\Modules\Agents\Services\PresetLibrary;
use Hiveclerk\Modules\Agents\Services\PublishPolicy;
use Hiveclerk\Modules\Agents\Services\TestConsoleService;
use Hiveclerk\Modules\Chat\Services\GuardrailService;
use Hiveclerk\Modules\Chat\Services\PromptBuilder;

/**
 * Configuring the staff.
 *
 * Depends on Chat for the prompt builder and the guardrails, and that
 * direction is deliberate: the test console has to assemble the prompt
 * exactly the way a live conversation does, and a second implementation
 * that drifted would make the console lie about the clerk it is testing.
 * A copy of the assembly logic is the one thing worse than the coupling.
 */
final class AgentsModule extends AbstractModule {

	/**
	 * Machine identifier.
	 *
	 * @return string
	 */
	public static function id(): string {
		return 'agents';
	}

	/**
	 * Bind services.
	 *
	 * @param Container $container Container.
	 * @return void
	 */
	public function register( Container $container ): void {
		parent::register( $container );

		$container->singleton( PresetLibrary::class, static fn (): PresetLibrary => new PresetLibrary() );

		$container->singleton(
			PublishPolicy::class,
			static fn ( Container $c ): PublishPolicy => new PublishPolicy(
				$c->get( AgentRepositoryInterface::class ),
				$c->get( SettingsRepository::class )
			)
		);

		$container->singleton(
			BudgetGuard::class,
			static fn ( Container $c ): BudgetGuard => new BudgetGuard(
				$c->get( AgentRepositoryInterface::class ),
				$c->get( PricingTable::class ),
				$c->get( ClockInterface::class )
			)
		);

		$container->singleton(
			AgentService::class,
			static fn ( Container $c ): AgentService => new AgentService(
				$c->get( AgentRepositoryInterface::class ),
				$c->get( PresetLibrary::class ),
				$c->get( PublishPolicy::class ),
				$c->get( BudgetGuard::class ),
				$c->get( AuditLogger::class )
			)
		);

		$container->singleton(
			TestConsoleService::class,
			static fn ( Container $c ): TestConsoleService => new TestConsoleService(
				$c->get( AiServiceInterface::class ),
				$c->get( RetrievalServiceInterface::class ),
				$c->get( PromptBuilder::class ),
				$c->get( GuardrailService::class ),
				$c->get( AgentRepositoryInterface::class ),
				$c->get( PricingTable::class )
			)
		);

		$container->singleton(
			AgentController::class,
			static fn ( Container $c ): AgentController => new AgentController(
				$c->get( AgentService::class ),
				$c->get( AgentRepositoryInterface::class ),
				$c->get( PresetLibrary::class ),
				$c->get( PublishPolicy::class ),
				$c->get( BudgetGuard::class ),
				$c->get( TestConsoleService::class ),
				$c->get( ConversationRepositoryInterface::class ),
				$c->get( KnowledgeSourceRepositoryInterface::class ),
				$c->get( RateLimiter::class ),
				$c->get( ClockInterface::class )
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
				$server->add( $this->container->get( AgentController::class ) );
			}
		);
	}

	/**
	 * Capabilities this module requires.
	 *
	 * @return array<int, string>
	 */
	public function capabilities(): array {
		return array( Capabilities::MANAGE_AGENTS );
	}
}
