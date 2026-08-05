<?php
/**
 * Knowledge base module.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase;

use Hiveclerk\Core\Capabilities\Capabilities;
use Hiveclerk\Core\Container\Container;
use Hiveclerk\Core\Module\AbstractModule;
use Hiveclerk\Api\RestServer;
use Hiveclerk\Core\Queue\JobRegistry;
use Hiveclerk\Core\Queue\QueueInterface;
use Hiveclerk\Core\Support\RateLimiter;
use Hiveclerk\Core\Audit\AuditLogger;
use Hiveclerk\Modules\KnowledgeBase\Http\SourceController;
use Hiveclerk\Database\Repositories\ChunkRepository;
use Hiveclerk\Database\Repositories\DocumentRepository;
use Hiveclerk\Domain\Knowledge\ChunkRepositoryInterface;
use Hiveclerk\Domain\Knowledge\DocumentRepositoryInterface;
use Hiveclerk\Domain\Knowledge\KnowledgeSourceRepositoryInterface;
use Hiveclerk\Modules\KnowledgeBase\Extractors\Crawl\PageFetcher;
use Hiveclerk\Modules\KnowledgeBase\Extractors\Crawl\UrlNormaliser;
use Hiveclerk\Modules\KnowledgeBase\Extractors\DocxExtractor;
use Hiveclerk\Modules\KnowledgeBase\Extractors\ExtractorRegistry;
use Hiveclerk\Modules\KnowledgeBase\Extractors\FaqExtractor;
use Hiveclerk\Modules\KnowledgeBase\Extractors\PdfExtractor;
use Hiveclerk\Modules\KnowledgeBase\Extractors\TextExtractor;
use Hiveclerk\Modules\KnowledgeBase\Extractors\WebCrawlExtractor;
use Hiveclerk\Modules\KnowledgeBase\Extractors\WooProductExtractor;
use Hiveclerk\Modules\KnowledgeBase\Extractors\WpContentExtractor;
use Hiveclerk\Modules\KnowledgeBase\Jobs\IngestSourceJob;
use Hiveclerk\Modules\KnowledgeBase\Services\ChunkerService;
use Hiveclerk\Modules\KnowledgeBase\Services\IngestionService;
use Hiveclerk\Modules\KnowledgeBase\Text\HtmlNormaliser;
use Hiveclerk\Modules\KnowledgeBase\Text\TokenEstimator;

/**
 * Everything that turns content into retrievable chunks.
 */
final class KnowledgeModule extends AbstractModule {

	/**
	 * Machine identifier.
	 *
	 * @return string
	 */
	public static function id(): string {
		return 'knowledge';
	}

	/**
	 * Bind services.
	 *
	 * @param Container $container Container.
	 * @return void
	 */
	public function register( Container $container ): void {
		parent::register( $container );

		$container->singleton( TokenEstimator::class, static fn (): TokenEstimator => new TokenEstimator() );
		$container->singleton( HtmlNormaliser::class, static fn (): HtmlNormaliser => new HtmlNormaliser() );
		$container->singleton( UrlNormaliser::class, static fn (): UrlNormaliser => new UrlNormaliser() );
		$container->singleton( PageFetcher::class, static fn (): PageFetcher => new PageFetcher() );

		$container->singleton(
			ChunkerService::class,
			static fn ( Container $c ): ChunkerService => new ChunkerService( $c->get( TokenEstimator::class ) )
		);

		$container->singleton(
			DocumentRepositoryInterface::class,
			static fn (): DocumentRepositoryInterface => new DocumentRepository()
		);

		$container->singleton(
			ChunkRepositoryInterface::class,
			static fn (): ChunkRepositoryInterface => new ChunkRepository()
		);

		$container->singleton(
			ExtractorRegistry::class,
			static function ( Container $c ): ExtractorRegistry {
				$registry = new ExtractorRegistry();

				$registry->add( new WpContentExtractor( $c->get( HtmlNormaliser::class ) ) );
				$registry->add( new WooProductExtractor( $c->get( HtmlNormaliser::class ) ) );
				$registry->add(
					new WebCrawlExtractor(
						$c->get( PageFetcher::class ),
						$c->get( HtmlNormaliser::class ),
						$c->get( UrlNormaliser::class )
					)
				);
				$registry->add( new PdfExtractor() );
				$registry->add( new DocxExtractor() );
				$registry->add( new FaqExtractor() );
				$registry->add( new TextExtractor() );

				/**
				 * Register additional knowledge extractors.
				 *
				 * The extension point for a plugin that wants to index a
				 * source we do not support — a helpdesk, a wiki, a
				 * proprietary catalogue — without editing this file.
				 *
				 * @param ExtractorRegistry $registry  The registry.
				 * @param Container         $container The container.
				 */
				do_action( 'hiveclerk/knowledge/extractors', $registry, $c );

				return $registry;
			}
		);

		$container->singleton(
			IngestionService::class,
			static fn ( Container $c ): IngestionService => new IngestionService(
				$c->get( ExtractorRegistry::class ),
				$c->get( ChunkerService::class ),
				$c->get( KnowledgeSourceRepositoryInterface::class ),
				$c->get( DocumentRepositoryInterface::class ),
				$c->get( ChunkRepositoryInterface::class )
			)
		);

		$container->singleton(
			IngestSourceJob::class,
			static fn ( Container $c ): IngestSourceJob => new IngestSourceJob(
				$c->get( IngestionService::class ),
				$c->get( KnowledgeSourceRepositoryInterface::class )
			)
		);

		$container->singleton(
			SourceController::class,
			static fn ( Container $c ): SourceController => new SourceController(
				$c->get( KnowledgeSourceRepositoryInterface::class ),
				$c->get( DocumentRepositoryInterface::class ),
				$c->get( ChunkRepositoryInterface::class ),
				$c->get( IngestionService::class ),
				$c->get( ExtractorRegistry::class ),
				$c->get( QueueInterface::class ),
				$c->get( AuditLogger::class ),
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
			'hiveclerk/jobs/register',
			function ( JobRegistry $jobs ): void {
				$jobs->add( $this->container->get( IngestSourceJob::class ) );
			}
		);

		add_action(
			'hiveclerk/rest/register',
			function ( RestServer $server ): void {
				$server->add( $this->container->get( SourceController::class ) );
			}
		);
	}

	/**
	 * Capabilities this module requires.
	 *
	 * @return array<int, string>
	 */
	public function capabilities(): array {
		return array( Capabilities::MANAGE_KNOWLEDGE );
	}
}
