<?php
/**
 * Chat module.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Chat;

use Hiveclerk\Ai\AiServiceInterface;
use Hiveclerk\Api\RestServer;
use Hiveclerk\Core\Capabilities\Capabilities;
use Hiveclerk\Core\Queue\JobRegistry;
use Hiveclerk\Core\Queue\QueueInterface;
use Hiveclerk\Core\Settings\SettingsRepository;
use Hiveclerk\Core\Container\Container;
use Hiveclerk\Core\Module\AbstractModule;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Core\Support\RateLimiter;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Conversation\CitationRepositoryInterface;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Domain\Conversation\MessageRepositoryInterface;
use Hiveclerk\Domain\Conversation\SessionRepositoryInterface;
use Hiveclerk\Infrastructure\WordPress\PageContextFactory;
use Hiveclerk\Modules\Chat\Http\BootstrapController;
use Hiveclerk\Modules\Chat\Http\ConversationController;
use Hiveclerk\Modules\Chat\Http\HandoffController;
use Hiveclerk\Modules\Chat\Jobs\PurgeConversationsJob;
use Hiveclerk\Modules\Chat\Http\HistoryController;
use Hiveclerk\Modules\Chat\Http\MessageController;
use Hiveclerk\Modules\Chat\Http\StreamController;
use Hiveclerk\Modules\Chat\Services\ChatService;
use Hiveclerk\Modules\Chat\Services\GuardrailService;
use Hiveclerk\Modules\Chat\Services\HandoffService;
use Hiveclerk\Modules\Chat\Services\RetentionService;
use Hiveclerk\Modules\Chat\Services\PromptBuilder;
use Hiveclerk\Modules\Chat\Services\SessionService;
use Hiveclerk\Modules\Chat\Services\WidgetConfig;
use Hiveclerk\Modules\Chat\Streaming\StreamBuffer;
use Hiveclerk\Modules\Chat\Widget\WidgetLoader;
use Hiveclerk\Domain\Knowledge\RetrievalServiceInterface;
use Hiveclerk\Modules\KnowledgeBase\Text\TokenEstimator;

/**
 * Everything between a visitor typing and a stored, delivered reply.
 *
 * This module depends on KnowledgeBase for retrieval and for token
 * estimation, and that dependency is one-directional and deliberate: a
 * site can index content without ever running a clerk, but a clerk that
 * cannot read the index is not a product. Removing KnowledgeBase would
 * take Chat with it, which is the correct coupling for these two.
 */
final class ChatModule extends AbstractModule {

	/**
	 * Machine identifier.
	 *
	 * @return string
	 */
	public static function id(): string {
		return 'chat';
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
			PromptBuilder::class,
			static fn ( Container $c ): PromptBuilder => new PromptBuilder(
				$c->get( TokenEstimator::class )
			)
		);

		$container->singleton(
			GuardrailService::class,
			static fn (): GuardrailService => new GuardrailService()
		);

		$container->singleton(
			SessionService::class,
			static fn ( Container $c ): SessionService => new SessionService(
				$c->get( SessionRepositoryInterface::class ),
				$c->get( ConversationRepositoryInterface::class ),
				$c->get( ClockInterface::class )
			)
		);

		$container->singleton( PageContextFactory::class, static fn (): PageContextFactory => new PageContextFactory() );

		$container->singleton(
			WidgetConfig::class,
			static fn ( Container $c ): WidgetConfig => new WidgetConfig(
				$c->get( AgentRepositoryInterface::class ),
				$c->get( PageContextFactory::class )
			)
		);

		$container->singleton(
			HandoffService::class,
			static fn ( Container $c ): HandoffService => new HandoffService(
				$c->get( ConversationRepositoryInterface::class ),
				$c->get( MessageRepositoryInterface::class ),
				$c->get( ClockInterface::class )
			)
		);

		$container->singleton(
			RetentionService::class,
			static fn ( Container $c ): RetentionService => new RetentionService(
				$c->get( ConversationRepositoryInterface::class ),
				$c->get( SessionRepositoryInterface::class ),
				$c->get( SettingsRepository::class ),
				$c->get( ClockInterface::class )
			)
		);

		$container->singleton(
			PurgeConversationsJob::class,
			static fn ( Container $c ): PurgeConversationsJob => new PurgeConversationsJob(
				$c->get( RetentionService::class ),
				$c->get( QueueInterface::class )
			)
		);

		$container->singleton( StreamBuffer::class, static fn (): StreamBuffer => new StreamBuffer() );

		$container->singleton(
			ChatService::class,
			static fn ( Container $c ): ChatService => new ChatService(
				$c->get( AiServiceInterface::class ),
				$c->get( RetrievalServiceInterface::class ),
				$c->get( PromptBuilder::class ),
				$c->get( GuardrailService::class ),
				$c->get( AgentRepositoryInterface::class ),
				$c->get( ConversationRepositoryInterface::class ),
				$c->get( MessageRepositoryInterface::class ),
				$c->get( CitationRepositoryInterface::class )
			)
		);

		$container->singleton(
			WidgetLoader::class,
			static fn ( Container $c ): WidgetLoader => new WidgetLoader(
				$c->get( WidgetConfig::class )
			)
		);

		$container->singleton(
			BootstrapController::class,
			static fn ( Container $c ): BootstrapController => new BootstrapController(
				$c->get( SessionService::class ),
				$c->get( RateLimiter::class ),
				$c->get( WidgetConfig::class )
			)
		);

		$container->singleton(
			StreamController::class,
			static fn ( Container $c ): StreamController => new StreamController(
				$c->get( SessionService::class ),
				$c->get( RateLimiter::class ),
				$c->get( ChatService::class ),
				$c->get( AgentRepositoryInterface::class ),
				$c->get( ConversationRepositoryInterface::class ),
				$c
			)
		);

		$container->singleton(
			MessageController::class,
			static fn ( Container $c ): MessageController => new MessageController(
				$c->get( SessionService::class ),
				$c->get( RateLimiter::class ),
				$c->get( ChatService::class ),
				$c->get( AgentRepositoryInterface::class ),
				$c->get( ConversationRepositoryInterface::class ),
				$c->get( StreamBuffer::class )
			)
		);

		$container->singleton(
			HandoffController::class,
			static fn ( Container $c ): HandoffController => new HandoffController(
				$c->get( SessionService::class ),
				$c->get( RateLimiter::class ),
				$c->get( HandoffService::class ),
				$c->get( AgentRepositoryInterface::class ),
				$c->get( ConversationRepositoryInterface::class )
			)
		);

		$container->singleton(
			ConversationController::class,
			static fn ( Container $c ): ConversationController => new ConversationController(
				$c->get( ConversationRepositoryInterface::class ),
				$c->get( MessageRepositoryInterface::class ),
				$c->get( CitationRepositoryInterface::class ),
				$c->get( AgentRepositoryInterface::class ),
				$c->get( HandoffService::class ),
				$c->get( RetentionService::class ),
				$c->get( ClockInterface::class )
			)
		);

		$container->singleton(
			HistoryController::class,
			static fn ( Container $c ): HistoryController => new HistoryController(
				$c->get( SessionService::class ),
				$c->get( RateLimiter::class ),
				$c->get( MessageRepositoryInterface::class ),
				$c->get( CitationRepositoryInterface::class ),
				$c->get( ConversationRepositoryInterface::class )
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
			'hiveclerk/jobs/register',
			function ( JobRegistry $jobs ): void {
				$jobs->add( $this->container->get( PurgeConversationsJob::class ) );
			}
		);

		add_action(
			'hiveclerk/rest/register',
			function ( RestServer $server ): void {
				$server->add( $this->container->get( BootstrapController::class ) );
				$server->add( $this->container->get( StreamController::class ) );
				$server->add( $this->container->get( MessageController::class ) );
				$server->add( $this->container->get( HistoryController::class ) );
				$server->add( $this->container->get( HandoffController::class ) );
				$server->add( $this->container->get( ConversationController::class ) );
			}
		);

		$this->scheduleRetention();

		$this->container->get( WidgetLoader::class )->boot();
	}

	/**
	 * Make sure the nightly purge is on the schedule.
	 *
	 * Checked on every admin load rather than only on activation. A site
	 * that was activated before this job existed — every site upgrading
	 * into this release — would otherwise never schedule it, and the
	 * retention policy would be a setting that quietly did nothing.
	 *
	 * @return void
	 */
	private function scheduleRetention(): void {
		add_action(
			'admin_init',
			function (): void {
				$queue = $this->container->get( QueueInterface::class );

				if ( $queue->isPending( PurgeConversationsJob::hook() ) ) {
					return;
				}

				$queue->scheduleRecurring( PurgeConversationsJob::INTERVAL, PurgeConversationsJob::hook() );
			}
		);
	}

	/**
	 * Capabilities this module requires.
	 *
	 * @return array<int, string>
	 */
	public function capabilities(): array {
		return array( Capabilities::VIEW_CONVERSATIONS, Capabilities::MANAGE_CONVERSATIONS );
	}
}
