<?php
/**
 * System status endpoints.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Api\Controllers;

use Hiveclerk\Api\AbstractController;
use Hiveclerk\Api\Response\ApiResponse;
use Hiveclerk\Core\Capabilities\Capabilities;
use Hiveclerk\Core\Queue\QueueInterface;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Core\Support\RateLimiter;
use Hiveclerk\Database\Migrator;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Domain\Knowledge\KnowledgeSourceRepositoryInterface;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Reports whether the installation is healthy.
 *
 * This is the endpoint the admin app calls first, so it doubles as the
 * proof that routing, authentication and the repository layer all work.
 */
final class SystemController extends AbstractController {

	/**
	 * Construct.
	 *
	 * @param Migrator                           $migrator      Schema migrator.
	 * @param ClockInterface                     $clock         Clock.
	 * @param RateLimiter                        $limiter       Rate limiter.
	 * @param AgentRepositoryInterface           $agents        Clerks.
	 * @param ConversationRepositoryInterface    $conversations Conversations.
	 * @param KnowledgeSourceRepositoryInterface $sources       Knowledge sources.
	 * @param QueueInterface                     $queue         Background queue.
	 */
	public function __construct(
		private readonly Migrator $migrator,
		private readonly ClockInterface $clock,
		private readonly RateLimiter $limiter,
		private readonly AgentRepositoryInterface $agents,
		private readonly ConversationRepositoryInterface $conversations,
		private readonly KnowledgeSourceRepositoryInterface $sources,
		private readonly QueueInterface $queue
	) {
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/system/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => $this->requires( Capabilities::VIEW_CONVERSATIONS ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/system/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'health' ),
				'permission_callback' => $this->requires( Capabilities::MANAGE_SETTINGS ),
			)
		);
	}

	/**
	 * Summary counts for the dashboard.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		unset( $request );

		$throttle = $this->throttle(
			$this->limiter,
			'system_status:' . get_current_user_id(),
			600
		);

		if ( $throttle instanceof WP_Error ) {
			return $throttle;
		}

		return ApiResponse::ok(
			array(
				'version'  => HIVECLERK_VERSION,
				'time'     => $this->clock->nowSql(),
				'database' => array(
					'version'         => $this->migrator->currentVersion(),
					'latest'          => $this->migrator->latestVersion(),
					'needs_migration' => $this->migrator->needsMigration(),
				),
				'counts'   => array(
					'agents'        => $this->agents->count(),
					'published'     => count( $this->agents->published() ),
					'conversations' => $this->conversations->count(),
					'sources'       => $this->sources->count(),
					'chunks'        => $this->sources->totalChunks(),
				),
				'ready'    => $this->isReady(),
			),
			array(),
			200,
			$throttle
		);
	}

	/**
	 * Environment diagnostics.
	 *
	 * Surfaces the things that actually break on shared hosting: a missing
	 * persistent object cache, a stalled cron, and pending migrations.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function health( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$tables  = $this->migrator->tableStatus();
		$missing = array_keys( array_filter( $tables, static fn ( bool $ok ): bool => ! $ok ) );

		return ApiResponse::ok(
			array(
				'php'          => array(
					'version'            => PHP_VERSION,
					'memory_limit'       => ini_get( 'memory_limit' ),
					'max_execution_time' => ini_get( 'max_execution_time' ),
					'openssl'            => extension_loaded( 'openssl' ),
				),
				'wordpress'    => array(
					'version'       => get_bloginfo( 'version' ),
					'multisite'     => is_multisite(),
					'cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				),
				'database'     => array(
					'version'        => $this->migrator->currentVersion(),
					'latest'         => $this->migrator->latestVersion(),
					'tables_present' => count( array_filter( $tables ) ),
					'tables_total'   => count( $tables ),
					'missing'        => $missing,
				),
				/*
				 * Which queue driver is in play, and how deep it is. Both
				 * are invisible until something has already gone wrong:
				 * a site running WP-Cron with no traffic processes work
				 * slowly, and a depth that only grows is the clearest
				 * symptom that cron is not firing at all.
				 */
				'queue'        => array(
					'driver' => $this->queue->driver(),
					'depth'  => $this->queue->depth(),
				),
				'object_cache' => array(
					'persistent' => wp_using_ext_object_cache(),
					// Without a persistent cache the retrieval matrix falls back
					// to transients, which is slower but still correct.
					'note'       => wp_using_ext_object_cache()
						? 'Redis or Memcached detected.'
						: 'No persistent object cache. Retrieval will use transients.',
				),
			)
		);
	}

	/**
	 * Whether the install can serve a conversation.
	 *
	 * @return bool
	 */
	private function isReady(): bool {
		return ! $this->migrator->needsMigration()
			&& count( $this->agents->published() ) > 0;
	}
}
