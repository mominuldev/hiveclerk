<?php
/**
 * Crawler safety tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Modules\KnowledgeBase;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Hiveclerk\Modules\KnowledgeBase\Extractors\Crawl\RobotsRules;
use Hiveclerk\Modules\KnowledgeBase\Extractors\Crawl\UrlNormaliser;
use Hiveclerk\Modules\KnowledgeBase\Extractors\FaqExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The crawler is the only part of the product that makes requests to
 * somebody else's server, so the rules it follows are worth testing as
 * carefully as the content it produces. Getting these wrong does not
 * produce a bad answer — it produces a customer whose host has blocked
 * their own site.
 *
 * @internal
 */
#[CoversClass( RobotsRules::class )]
#[CoversClass( UrlNormaliser::class )]
#[CoversClass( FaqExtractor::class )]
final class CrawlSafetyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_parse_url' )->alias(
			static fn ( string $url, int $component = -1 ): mixed => parse_url( $url, $component )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ----------------------------------------------------------- robots.txt

	public function testADisallowedPathIsRefused(): void {
		$rules = RobotsRules::parse( "User-agent: *\nDisallow: /private/", 'HiveclerkBot' );

		$this->assertFalse( $rules->allows( '/private/secrets' ) );
		$this->assertTrue( $rules->allows( '/public/page' ) );
	}

	public function testAnEmptyDisallowPermitsEverything(): void {
		// "Disallow:" with nothing after it means the opposite of
		// "Disallow: /". Read as a zero-length prefix it matches every
		// path, and the crawler indexes nothing at all.
		$rules = RobotsRules::parse( "User-agent: *\nDisallow:", 'HiveclerkBot' );

		$this->assertTrue( $rules->allows( '/anything' ) );
		$this->assertTrue( $rules->allows( '/' ) );
	}

	public function testAMoreSpecificAllowBeatsADisallow(): void {
		$rules = RobotsRules::parse(
			"User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php",
			'HiveclerkBot'
		);

		$this->assertFalse( $rules->allows( '/wp-admin/options.php' ) );
		$this->assertTrue( $rules->allows( '/wp-admin/admin-ajax.php' ) );
	}

	public function testARuleNamingUsReplacesTheWildcardRule(): void {
		$rules = RobotsRules::parse(
			"User-agent: *\nDisallow: /\n\nUser-agent: HiveclerkBot\nDisallow: /admin/",
			'HiveclerkBot'
		);

		// The wildcard block bans everything; the block naming us bans one
		// directory. Merging the two would leave us banned from the site
		// that specifically granted us access.
		$this->assertTrue( $rules->allows( '/products' ) );
		$this->assertFalse( $rules->allows( '/admin/' ) );
	}

	public function testRulesForOtherCrawlersAreIgnored(): void {
		$rules = RobotsRules::parse(
			"User-agent: BadBot\nDisallow: /\n\nUser-agent: *\nDisallow: /tmp/",
			'HiveclerkBot'
		);

		$this->assertTrue( $rules->allows( '/products' ) );
		$this->assertFalse( $rules->allows( '/tmp/x' ) );
	}

	public function testWildcardsAndAnchorsAreHonoured(): void {
		$rules = RobotsRules::parse(
			"User-agent: *\nDisallow: /*.pdf$\nDisallow: /a/*/b",
			'HiveclerkBot'
		);

		$this->assertFalse( $rules->allows( '/files/manual.pdf' ) );
		$this->assertTrue( $rules->allows( '/files/manual.pdf.html' ) );
		$this->assertFalse( $rules->allows( '/a/x/b' ) );
	}

	public function testCrawlDelayAndSitemapsAreRead(): void {
		$rules = RobotsRules::parse(
			"Sitemap: https://example.com/sitemap.xml\nUser-agent: *\nCrawl-delay: 2.5",
			'HiveclerkBot'
		);

		$this->assertSame( 2.5, $rules->crawlDelay );
		$this->assertSame( array( 'https://example.com/sitemap.xml' ), $rules->sitemaps );
	}

	public function testCommentsAreStripped(): void {
		$rules = RobotsRules::parse( "User-agent: *  # everyone\nDisallow: /x/ # not this", 'HiveclerkBot' );

		$this->assertFalse( $rules->allows( '/x/y' ) );
	}

	public function testAMissingFileMeansNoRestrictions(): void {
		$this->assertTrue( RobotsRules::permissive()->allows( '/anything' ) );
	}

	// --------------------------------------------------------------- URLs

	public function testTheSamePageWrittenFourWaysCanonicalisesToOne(): void {
		$urls = new UrlNormaliser();

		$canonical = $urls->canonical( 'https://example.com/about' );

		// Every wasted request in a crawl starts here. Four strings, one
		// page: without this the page is fetched, indexed and cited four
		// times over.
		$this->assertSame( $canonical, $urls->canonical( 'https://example.com/about/' ) );
		$this->assertSame( $canonical, $urls->canonical( 'https://example.com/about#team' ) );
		$this->assertSame( $canonical, $urls->canonical( 'https://example.com/about?utm_source=nav' ) );
		$this->assertSame( $canonical, $urls->canonical( 'https://EXAMPLE.com:443/about' ) );
	}

	public function testMeaningfulQueryParametersAreKept(): void {
		$urls = new UrlNormaliser();

		$this->assertSame(
			'https://example.com/search?page=2&q=socks',
			$urls->canonical( 'https://example.com/search?q=socks&page=2' )
		);
	}

	public function testTheRootPathKeepsItsSlash(): void {
		$this->assertSame( 'https://example.com/', ( new UrlNormaliser() )->canonical( 'https://example.com/' ) );
	}

	public function testNonHttpSchemesAreRefused(): void {
		$urls = new UrlNormaliser();

		$this->assertNull( $urls->canonical( 'ftp://example.com/file' ) );
		$this->assertNull( $urls->canonical( 'javascript:alert(1)' ) );
	}

	public function testHostsThatMerelyStartWithWAreNotConfused(): void {
		$urls = new UrlNormaliser();

		// ltrim( $host, 'www.' ) strips characters rather than a prefix, so
		// it turns "web.example.com" into "eb.example.com" and decides two
		// unrelated hosts are the same site — which lets a crawl walk off
		// onto a domain it was never pointed at.
		$this->assertFalse(
			$urls->sameHost( 'https://web.example.com/a', 'https://www.example.com/a' )
		);

		$this->assertTrue(
			$urls->sameHost( 'https://www.example.com/a', 'https://example.com/b' )
		);
	}

	public function testRelativeLinksResolveAgainstTheirPage(): void {
		$urls = new UrlNormaliser();
		$base = 'https://example.com/docs/guide';

		$this->assertSame( 'https://example.com/docs/intro', $urls->resolve( 'intro', $base ) );
		$this->assertSame( 'https://example.com/top', $urls->resolve( '/top', $base ) );
		$this->assertSame( 'https://other.com/x', $urls->resolve( '//other.com/x', $base ) );
	}

	public function testNonPageLinksAreRefused(): void {
		$urls = new UrlNormaliser();
		$base = 'https://example.com/';

		foreach ( array( 'mailto:a@b.com', 'tel:+441234', 'javascript:void(0)', '#top', '' ) as $link ) {
			$this->assertNull( $urls->resolve( $link, $base ), sprintf( '%s should not be crawled.', $link ) );
		}
	}

	// ----------------------------------------------------------- FAQ import

	public function testCsvImportReadsAHeaderRow(): void {
		$result = FaqExtractor::parseCsv( "question,answer\nDo you ship to Ireland?,\"Yes, £8.\"" );

		$this->assertCount( 1, $result['pairs'] );
		$this->assertSame( 'Do you ship to Ireland?', $result['pairs'][0]['question'] );
		$this->assertSame( 'Yes, £8.', $result['pairs'][0]['answer'] );
	}

	public function testCsvColumnsMayBeInAnyOrder(): void {
		$result = FaqExtractor::parseCsv( "answer,url,question\nBy Tuesday.,https://x.test/a,When?" );

		$this->assertSame( 'When?', $result['pairs'][0]['question'] );
		$this->assertSame( 'By Tuesday.', $result['pairs'][0]['answer'] );
		$this->assertSame( 'https://x.test/a', $result['pairs'][0]['url'] );
	}

	public function testAByteOrderMarkDoesNotBreakHeaderDetection(): void {
		// Every export from Excel carries one. Left in place it becomes
		// part of the first column's name, the header never matches, and
		// the entire file is skipped without an error.
		$result = FaqExtractor::parseCsv( "\xEF\xBB\xBFquestion,answer\nWhen do you open?,At nine." );

		$this->assertCount( 1, $result['pairs'] );
		$this->assertSame( 'When do you open?', $result['pairs'][0]['question'] );
	}

	public function testAFileWithNoHeaderIsReadAsQuestionThenAnswer(): void {
		$result = FaqExtractor::parseCsv( "When do you open?,At nine.\nWhere are you?,In Leeds." );

		$this->assertCount( 2, $result['pairs'] );
		$this->assertSame( 'In Leeds.', $result['pairs'][1]['answer'] );
	}

	public function testRowsMissingHalfThePairAreCountedNotIndexed(): void {
		$result = FaqExtractor::parseCsv( "question,answer\nOrphan question?,\nGood?,Yes." );

		// A question with no answer would index a chunk that matches the
		// query perfectly and says nothing.
		$this->assertCount( 1, $result['pairs'] );
		$this->assertSame( 1, $result['skipped'] );
	}
}
