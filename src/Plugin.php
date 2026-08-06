<?php
/**
 * Plugin entry point.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk;

use Hiveclerk\Api\RestServer;
use Hiveclerk\Core\Container\Container;
use Hiveclerk\Core\Container\Providers\AiServiceProvider;
use Hiveclerk\Core\Container\Providers\ApiServiceProvider;
use Hiveclerk\Core\Container\Providers\CoreServiceProvider;
use Hiveclerk\Core\Container\Providers\DatabaseServiceProvider;
use Hiveclerk\Core\Module\ModuleRegistry;
use Hiveclerk\Core\Queue\JobRegistry;
use Hiveclerk\Database\Migrator;

/**
 * Wires the container, registers modules and boots the plugin.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Whether boot() has run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Private: use instance().
	 */
	private function __construct() {
		$this->container = new Container();
	}

	/**
	 * Shared instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Container, for tests and third-party extensions.
	 *
	 * @return Container
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Boot the plugin.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->container->register( new CoreServiceProvider() );
		$this->container->register( new DatabaseServiceProvider() );
		$this->container->register( new AiServiceProvider() );
		$this->container->register( new ApiServiceProvider() );

		$this->registerMigrationHook();
		$this->registerLicenceRefresh();

		$this->container->get( RestServer::class )->boot();

		$registry = $this->container->get( ModuleRegistry::class );

		$registry->add( new Modules\KnowledgeBase\KnowledgeModule() );
		// Chat after Knowledge: it resolves RetrievalService and
		// TokenEstimator out of the container, and both are bound there.
		$registry->add( new Modules\Chat\ChatModule() );
		// Leads after Chat: its public routes extend Chat's PublicController
		// and are constructed with Chat's SessionService. The dependency the
		// other way is one domain interface, which Leads rebinds here over
		// the null object the core provider left in place.
		$registry->add( new Modules\Leads\LeadsModule() );
		// Integrations and Email after Leads: both listen to lead events
		// and neither is ever called by the module that fires them, which
		// is what lets a site filter either out and keep a working
		// pipeline.
		$registry->add( new Modules\Integrations\IntegrationsModule() );
		$registry->add( new Modules\Email\EmailModule() );
		// Agents before Analytics: the test console runs a clerk through
		// Chat's prompt builder and guardrails, so both have to be bound
		// before it asks the container for them.
		$registry->add( new Modules\Agents\AgentsModule() );
		// Analytics last, because it reads from everything and is called
		// by nothing. Its gap composer writes an FAQ pair and queues
		// KnowledgeBase's index job; its needs-attention queue reads
		// Integrations' sync log. Both dependencies are one way.
		$registry->add( new Modules\Analytics\AnalyticsModule() );
		// Onboarding after Analytics only because it is the last thing
		// added; it depends on Knowledge and Agents, both long since
		// bound.
		$registry->add( new Modules\Onboarding\OnboardingModule() );

		/**
		 * Register feature modules.
		 *
		 * Third-party code adds modules here. A module that reports itself
		 * unavailable is skipped rather than fataling.
		 *
		 * @param ModuleRegistry $registry Module registry.
		 * @param Container      $container Container.
		 */
		do_action( 'hiveclerk/modules/register', $registry, $this->container );

		$registry->boot();

		/*
		 * Jobs bind after modules so a module can contribute one. Binding
		 * happens on every request, including cron and REST: a job whose
		 * hook has no listener is silently dropped by both queue drivers,
		 * which looks exactly like work that never got scheduled.
		 */
		$jobs = $this->container->get( JobRegistry::class );

		/**
		 * Register background jobs.
		 *
		 * @param JobRegistry $jobs      Job registry.
		 * @param Container   $container Container.
		 */
		do_action( 'hiveclerk/jobs/register', $jobs, $this->container );

		$jobs->boot();

		$this->container->get( Core\Admin\AdminPage::class )->boot();

		/*
		 * Registered on every request, not just admin ones. WordPress runs
		 * a privacy request from an admin-ajax callback and confirms it
		 * from a front-end link in an email, and an exporter that only
		 * existed inside wp-admin would be absent from half of that.
		 */
		$this->container->get( Core\Privacy\PersonalDataExporter::class )->register();
		$this->container->get( Core\Privacy\PersonalDataEraser::class )->register();

		$this->booted = true;

		/**
		 * Fires once the plugin is fully booted.
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'hiveclerk/booted', $this );
	}

	/**
	 * Run pending migrations at the first opportunity of any kind.
	 *
	 * Deliberately not run during activation: activation has a short
	 * execution budget, and a failure there leaves the plugin half-installed
	 * with no way to report why. On a request there is somewhere to report
	 * into and a next request to retry from.
	 *
	 * ## Why `admin_init` alone was not enough
	 *
	 * Nothing guarantees an administrator visits. A background auto-update, a
	 * `wp plugin update` in a deploy script, or a site whose owner only ever
	 * looks at the front end all leave new code running against the previous
	 * schema — and the parts that keep running are the ones with nobody
	 * watching: the widget answering visitors over REST, and every cron job.
	 * A migration that adds a column is then a fatal on the first query that
	 * selects it, on a visitor's request, with the admin screen that would
	 * have fixed it never opened.
	 *
	 * So the check runs on `admin_init`, on `rest_api_init` and at the top of
	 * the job runner. It is a comparison of two integers when there is
	 * nothing to do, which is almost always, and the lock means the three
	 * cannot race each other into running the same migration twice.
	 *
	 * @return void
	 */
	private function registerMigrationHook(): void {
		$run = function (): void {
			$migrator = $this->container->get( Migrator::class );

			if ( $migrator->needsMigration() ) {
				$migrator->migrate();
			}
		};

		add_action( 'admin_init', $run, 5 );

		// Before any route handler, so a widget request on a site nobody
		// administers is not answered by code the schema does not match.
		add_action( 'rest_api_init', $run, 1 );

		// And before background work, which is the other path that runs for
		// months without an administrator ever being present.
		add_action( 'hiveclerk/jobs/register', $run, 1 );
	}

	/**
	 * Re-check a stale licence on the next admin request.
	 *
	 * On `admin_init` rather than inside an entitlement check. A gate that
	 * made a network call would put our licence server's latency inside
	 * every request that saved a connector or hired a clerk, and a slow
	 * server at our end would present as a slow admin at theirs.
	 *
	 * Priority 20, after the migration hook: a licence check is never the
	 * reason a schema change waits.
	 *
	 * @return void
	 */
	private function registerLicenceRefresh(): void {
		add_action(
			'admin_init',
			function (): void {
				$this->container->get( Core\Licence\LicenceService::class )->refreshIfStale();
			},
			20
		);
	}

	/**
	 * Prevent cloning.
	 *
	 * @return void
	 */
	private function __clone() {
	}
}
