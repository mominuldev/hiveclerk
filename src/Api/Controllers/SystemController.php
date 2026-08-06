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
use Hiveclerk\Core\Audit\AuditLogger;
use Hiveclerk\Core\Licence\LicenceSignature;
use Hiveclerk\Core\Security\SecretRotator;
use Hiveclerk\Core\Queue\JobHeartbeat;
use Hiveclerk\Core\Queue\QueueInterface;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Ai\KeyResolver;
use Hiveclerk\Core\Activation\Footprint;
use Hiveclerk\Core\Support\RateLimiter;
use Hiveclerk\Database\Migrator;
use Hiveclerk\Database\ServerInfo;
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
	 * @param ServerInfo                         $server        Database server.
	 * @param KeyResolver                        $keys          Provider credentials.
	 * @param SecretRotator                      $rotator       Encryption key rotation.
	 * @param AuditLogger                        $audit         Audit log.
	 */
	public function __construct(
		private readonly Migrator $migrator,
		private readonly ClockInterface $clock,
		private readonly RateLimiter $limiter,
		private readonly AgentRepositoryInterface $agents,
		private readonly ConversationRepositoryInterface $conversations,
		private readonly KnowledgeSourceRepositoryInterface $sources,
		private readonly QueueInterface $queue,
		private readonly ServerInfo $server,
		private readonly KeyResolver $keys,
		private readonly SecretRotator $rotator,
		private readonly AuditLogger $audit
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

		/*
		 * Three steps rather than one button.
		 *
		 * Rotation cannot be atomic: the sweep is bounded so it cannot time
		 * out half-way through an install with many integrations, and the
		 * window has to stay open until the operator's own screen confirms
		 * everything moved. Collapsing this into one call would mean either
		 * an unbounded request or a rotation that closes on a guess.
		 */
		register_rest_route(
			self::NAMESPACE,
			'/system/encryption/rotation',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'beginRotation' ),
				'permission_callback' => $this->requires( Capabilities::MANAGE_SETTINGS ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/system/encryption/rotation/sweep',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'sweepRotation' ),
				'permission_callback' => $this->requires( Capabilities::MANAGE_SETTINGS ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/system/encryption/rotation/finish',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'finishRotation' ),
				'permission_callback' => $this->requires( Capabilities::MANAGE_SETTINGS ),
			)
		);
	}

	/**
	 * Open the dual-key window and mint a new key.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function beginRotation( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		unset( $request );

		if ( ! $this->rotator->begin() ) {
			return new WP_Error(
				'hiveclerk_rotation_in_progress',
				__( 'A key rotation is already under way. Finish it before starting another.', 'hiveclerk' ),
				array( 'status' => 409 )
			);
		}

		$this->audit->record( AuditLogger::KEY_ROTATION_STARTED );

		return ApiResponse::ok( $this->rotationState() );
	}

	/**
	 * Rewrite one bounded batch of secrets under the new key.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function sweepRotation( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		unset( $request );

		if ( ! $this->rotator->isRotating() ) {
			return new WP_Error(
				'hiveclerk_no_rotation',
				__( 'No key rotation is in progress.', 'hiveclerk' ),
				array( 'status' => 409 )
			);
		}

		$result = $this->rotator->sweep();

		$this->audit->record( AuditLogger::KEY_ROTATION_SWEPT, $result );

		return ApiResponse::ok( $this->rotationState() + $result );
	}

	/**
	 * Close the window, retiring the old key for good.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function finishRotation( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		unset( $request );

		if ( ! $this->rotator->isRotating() ) {
			return new WP_Error(
				'hiveclerk_no_rotation',
				__( 'No key rotation is in progress.', 'hiveclerk' ),
				array( 'status' => 409 )
			);
		}

		/*
		 * The refusal that matters. Closing with readable secrets still on
		 * the old key destroys them, and nothing would report it until a
		 * sync failed weeks later.
		 */
		if ( ! $this->rotator->finish() ) {
			return new WP_Error(
				'hiveclerk_rotation_incomplete',
				__( 'Some secrets have not been moved to the new key yet. Run the sweep until none remain.', 'hiveclerk' ),
				array(
					'status'      => 409,
					'outstanding' => $this->rotator->outstanding(),
				)
			);
		}

		$this->audit->record( AuditLogger::KEY_ROTATION_FINISHED );

		return ApiResponse::ok( $this->rotationState() );
	}

	/**
	 * What a screen needs to render the rotation.
	 *
	 * @return array{rotating: bool, outstanding: list<string>}
	 */
	private function rotationState(): array {
		return array(
			'rotating'    => $this->rotator->isRotating(),
			/*
			 * Labels, never ciphertext or plaintext. "Provider key: openai"
			 * is what an operator needs to act; the value is the thing this
			 * whole subsystem exists to keep off the wire.
			 */
			'outstanding' => $this->rotator->outstanding(),
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
				'mysql'        => array(
					'version'   => $this->server->version(),
					'mariadb'   => $this->server->isMariaDb(),
					'charset'   => $this->server->charset(),
					'collation' => $this->server->collation(),
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
				'cron'         => $this->cron(),
				'providers'    => $this->providers(),
				/*
				 * Whether licence answers are actually being checked, and
				 * if not, whose problem it is.
				 *
				 * A signature that cannot be verified is accepted rather
				 * than rejected — failing closed on it would turn one bad
				 * release of our key material into every customer's licence
				 * breaking at once. The cost is that such an install trusts
				 * TLS alone, and until this block existed it did so with
				 * nothing anywhere saying so.
				 */
				'encryption'   => array(
					'rotating'    => $this->rotator->isRotating(),
					'outstanding' => count( $this->rotator->outstanding() ),
				),
				'licence'      => array(
					'sodium'         => LicenceSignature::isSupported(),
					'key_configured' => LicenceSignature::isConfigured(),
					'verifying'      => LicenceSignature::isVerifying(),
				),
				'object_cache' => array(
					/*
					 * Cast, because the global this reads is null until
					 * something sets it and the raw value serialises to
					 * JSON null — which a screen would render as neither
					 * yes nor no.
					 */
					'persistent' => (bool) wp_using_ext_object_cache(),
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
	 * Every recurring job, and whether it is running when it should.
	 *
	 * A queue depth answers "is there work waiting". It does not answer
	 * the question operators actually arrive with, which is "why has
	 * nothing happened since Tuesday" — and the usual cause is a cron
	 * that stopped firing, which shows up as a scheduled time in the past
	 * rather than as anything erroring.
	 *
	 * Hooks are read from the schedule rather than from a list, so this
	 * cannot describe a job the product no longer has or miss one it
	 * gained. Nothing scheduled at all is reported as exactly that, and
	 * it is the most serious state here: it means either that no module
	 * has booted or that something unscheduled our work.
	 *
	 * @return array<string, mixed>
	 */
	private function cron(): array {
		$now       = $this->clock->now()->getTimestamp();
		$events    = array();
		$overdue   = 0;
		$stalled   = 0;
		$installed = (int) strtotime( (string) get_option( 'hiveclerk_installed_at', '' ) );
		$intervals = $this->intervals();

		foreach ( Footprint::scheduledHooks() as $hook => $next ) {
			/*
			 * An hour's grace. WP-Cron fires on traffic, so a quiet site
			 * legitimately runs a five-minute job late, and a screen that
			 * called that "overdue" would cry wolf on every low-traffic
			 * install — which is most of them.
			 */
			$late = $next < ( $now - HOUR_IN_SECONDS );

			if ( $late ) {
				++$overdue;
			}

			/*
			 * The important one, and the reason this method was rewritten.
			 * A scheduled event reschedules itself whether or not anything
			 * answered it, so `next_run` advancing proves only that
			 * WordPress can do arithmetic. On a host whose cron runs a PHP
			 * this plugin refuses to boot on — Hostinger ships different
			 * versions for web and CLI — all three jobs fire into nothing
			 * for ever while every row here looked healthy.
			 */
			$interval = $intervals[ $hook ] ?? 0;
			$last     = JobHeartbeat::lastRun( $hook );
			$stale    = JobHeartbeat::isStale( $hook, $interval, $now, $installed > 0 ? $installed : null );

			if ( $stale ) {
				++$stalled;
			}

			$events[] = array(
				'hook'       => $hook,
				'next_run'   => gmdate( 'Y-m-d H:i:s', $next ),
				'is_late'    => $late,
				'last_run'   => null !== $last ? gmdate( 'Y-m-d H:i:s', $last ) : null,
				'is_stalled' => $stale,
			);
		}

		return array(
			'scheduled' => count( $events ),
			'overdue'   => $overdue,
			// Counted separately from `overdue` because they are different
			// faults with different fixes. Overdue means cron is not firing;
			// stalled means it is firing and we are not there to answer.
			'stalled'   => $stalled,
			'events'    => $events,
		);
	}

	/**
	 * How often each scheduled hook is supposed to run.
	 *
	 * Read from the cron array rather than from a list of our own, for the
	 * same reason the hooks are: a list can describe a cadence the product
	 * no longer has. A one-off event has no interval and is returned as
	 * zero, which {@see JobHeartbeat::isStale()} treats as "nothing to
	 * judge".
	 *
	 * @return array<string, int>
	 */
	private function intervals(): array {
		$cron = _get_cron_array();

		if ( ! is_array( $cron ) ) {
			return array();
		}

		$intervals = array();

		foreach ( $cron as $events ) {
			if ( ! is_array( $events ) ) {
				continue;
			}

			foreach ( $events as $hook => $instances ) {
				if ( ! is_string( $hook ) || ! is_array( $instances ) ) {
					continue;
				}

				foreach ( $instances as $instance ) {
					if ( is_array( $instance ) && is_numeric( $instance['interval'] ?? null ) ) {
						$intervals[ $hook ] = (int) $instance['interval'];
					}
				}
			}
		}

		return $intervals;
	}

	/**
	 * Which model providers are configured, and when each last answered.
	 *
	 * Reported from what was stored at the last verification rather than
	 * probed live. A status screen that opened a connection to every
	 * configured provider on every load would put three third-party
	 * latencies inside a page an operator refreshes while debugging, and
	 * bill them for the privilege on any provider that charges for a
	 * model list. Re-checking is a button on the providers screen, where
	 * the person pressing it knows they are making a request.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function providers(): array {
		$providers = array();

		foreach ( $this->keys->configured() as $provider ) {
			$described = $this->keys->describe( $provider );

			$providers[] = array(
				'provider'    => $provider,
				'from_config' => $described['from_config'],
				'model'       => $described['model'],
				// Empty means a key is stored that has never successfully
				// listed a model, which is a different and more urgent
				// state than "verified a while ago".
				'verified_at' => $described['verified_at'],
			);
		}

		return $providers;
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
