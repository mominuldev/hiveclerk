<?php
/**
 * HTML normaliser tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Modules\KnowledgeBase;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Hiveclerk\Modules\KnowledgeBase\Text\HtmlNormaliser;
use Hiveclerk\Modules\KnowledgeBase\Text\TextBlock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass( HtmlNormaliser::class )]
#[CoversClass( TextBlock::class )]
final class HtmlNormaliserTest extends TestCase {

	private HtmlNormaliser $normaliser;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn ( string $text ): string => strip_tags( $text )
		);

		$this->normaliser = new HtmlNormaliser();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function testHeadingsBecomeThePathAndNotContent(): void {
		$blocks = $this->normaliser->toBlocks(
			'<h1>Help</h1><h2>Shipping</h2><p>Orders ship in two days.</p>'
		);

		$this->assertCount( 1, $blocks );
		$this->assertSame( 'Orders ship in two days.', $blocks[0]->text );

		// The heading is not repeated as a block: Chunk::contextualised()
		// prefixes the path when the text goes to a model, so emitting it
		// here as well would embed it twice and skew the vector.
		$this->assertSame( array( 'Help', 'Shipping' ), $blocks[0]->headingPath );
	}

	public function testASiblingHeadingReplacesRatherThanNests(): void {
		$blocks = $this->normaliser->toBlocks(
			'<h1>Help</h1><h2>Shipping</h2><p>A</p><h2>Returns</h2><p>B</p>'
		);

		$this->assertSame( array( 'Help', 'Shipping' ), $blocks[0]->headingPath );
		$this->assertSame( array( 'Help', 'Returns' ), $blocks[1]->headingPath );
	}

	public function testSkippedHeadingLevelsDoNotCreateGaps(): void {
		// Real sites jump from h1 to h3 constantly. The path must stay
		// ordered and shallow rather than acquiring an empty level.
		$blocks = $this->normaliser->toBlocks( '<h1>Top</h1><h3>Deep</h3><p>Body</p>' );

		$this->assertSame( array( 'Top', 'Deep' ), $blocks[0]->headingPath );
	}

	public function testBlockBoundariesAreNotWelded(): void {
		$blocks = $this->normaliser->toBlocks( '<p>First sentence.</p><p>Second sentence.</p>' );

		// strip_tags() would produce "First sentence.Second sentence." —
		// one sentence that nobody wrote.
		$this->assertCount( 2, $blocks );
		$this->assertSame( 'First sentence.', $blocks[0]->text );
		$this->assertSame( 'Second sentence.', $blocks[1]->text );
	}

	public function testScriptsAndStylesAreRemovedWithTheirContents(): void {
		$blocks = $this->normaliser->toBlocks(
			'<p>Real text.</p><script>var secret = "leak";</script><style>.a{color:red}</style>'
		);

		$text = implode( ' ', array_map( static fn ( TextBlock $b ): string => $b->text, $blocks ) );

		$this->assertStringNotContainsString( 'secret', $text );
		$this->assertStringNotContainsString( 'color:red', $text );
		$this->assertStringContainsString( 'Real text.', $text );
	}

	public function testChromeIsKeptWhenNotAskedToStripIt(): void {
		// Post content has no navigation, so stripping would only risk
		// removing something the author wrote.
		$blocks = $this->normaliser->toBlocks( '<nav>Menu</nav><p>Body</p>', false );

		$text = implode( ' ', array_map( static fn ( TextBlock $b ): string => $b->text, $blocks ) );

		$this->assertStringContainsString( 'Menu', $text );
	}

	public function testContentInHtml5ElementsBeforeTheBodyIsNotLost(): void {
		// libxml parses as HTML 4.01 and does not recognise main, nav,
		// article, section, header, footer or aside. A document opening
		// with one of them never opens <body> in its view, and the element
		// is parked inside <head> instead. Reading only <body> dropped
		// every word of it, silently, on exactly the markup modern themes
		// emit.
		$blocks = $this->normaliser->toBlocks(
			'<main><p>The only paragraph on the page.</p></main>',
			false
		);

		$this->assertCount( 1, $blocks );
		$this->assertSame( 'The only paragraph on the page.', $blocks[0]->text );
	}

	public function testDocumentMetadataIsNeverTreatedAsContent(): void {
		$blocks = $this->normaliser->toBlocks(
			'<html><head><title>Page title</title></head><body><p>Body text.</p></body></html>'
		);

		$text = implode( ' ', array_map( static fn ( TextBlock $b ): string => $b->text, $blocks ) );

		// Reading from the document root rather than <body> means head
		// content is in scope, so metadata has to be excluded by name.
		$this->assertStringNotContainsString( 'Page title', $text );
		$this->assertSame( 'Body text.', $text );
	}

	public function testChromeIsRemovedFromCrawledPages(): void {
		$html = '<nav>Home Shop Contact</nav><main><p>The actual answer.</p></main>'
			. '<footer>Copyright 2026</footer>';

		$blocks = $this->normaliser->toBlocks( $html, true );

		$text = implode( ' ', array_map( static fn ( TextBlock $b ): string => $b->text, $blocks ) );

		// Indexed on every URL of a site, a menu becomes the most repeated
		// text in the knowledge base and matches every query weakly.
		$this->assertStringNotContainsString( 'Home Shop', $text );
		$this->assertStringNotContainsString( 'Copyright', $text );
		$this->assertSame( 'The actual answer.', $text );
	}

	public function testMainContentIsPreferredWhenThePageMarksIt(): void {
		$html = '<div class="sidebar">Related posts</div>'
			. '<article><p>Article body.</p></article>';

		$blocks = $this->normaliser->toBlocks( $html, true );

		$this->assertCount( 1, $blocks );
		$this->assertSame( 'Article body.', $blocks[0]->text );
	}

	public function testListItemsBecomeSeparateBlocks(): void {
		$blocks = $this->normaliser->toBlocks( '<ul><li>Alpha</li><li>Beta</li></ul>' );

		$this->assertSame( array( 'Alpha', 'Beta' ), array_map( static fn ( TextBlock $b ): string => $b->text, $blocks ) );
	}

	public function testTableRowsKeepLabelsWithTheirValues(): void {
		$html = '<table><tr><td>Material</td><td>Merino wool</td></tr>'
			. '<tr><td>Weight</td><td>320g</td></tr></table>';

		$blocks = $this->normaliser->toBlocks( $html );

		// Split cell by cell, "320g" would be a chunk on its own and could
		// never be retrieved by a question about weight.
		$this->assertSame( 'Material | Merino wool', $blocks[0]->text );
		$this->assertSame( 'Weight | 320g', $blocks[1]->text );
	}

	public function testEntitiesAreDecoded(): void {
		$blocks = $this->normaliser->toBlocks( '<p>Tom &amp; Jerry &mdash; 5&nbsp;kg</p>' );

		$this->assertSame( 'Tom & Jerry — 5 kg', $blocks[0]->text );
	}

	public function testNonBreakingSpacesDoNotSurviveInsideWords(): void {
		$blocks = $this->normaliser->toBlocks( "<p>free\u{00A0}returns</p>" );

		// A non-breaking space is not \s, so it survives every ordinary
		// whitespace collapse and ends up glued inside a word in the index.
		$this->assertSame( 'free returns', $blocks[0]->text );
	}

	public function testAccentedCharactersSurviveParsing(): void {
		$blocks = $this->normaliser->toBlocks( '<p>Grüße aus München — 日本語</p>' );

		// libxml assumes ISO-8859-1 for a fragment with no declared
		// encoding, which turns this into mojibake before anything else
		// runs.
		$this->assertSame( 'Grüße aus München — 日本語', $blocks[0]->text );
	}

	public function testLineBreaksAreKeptInsideABlock(): void {
		$blocks = $this->normaliser->toBlocks( '<p>Line one<br>Line two</p>' );

		$this->assertSame( "Line one\nLine two", $blocks[0]->text );
	}

	public function testEmptyMarkupProducesNothing(): void {
		$this->assertSame( array(), $this->normaliser->toBlocks( '   ' ) );
		$this->assertSame( array(), $this->normaliser->toBlocks( '<div><span> </span></div>' ) );
	}

	public function testTheAssembledDocumentIsAddressableBySpan(): void {
		$document = $this->normaliser->normalise(
			'<h2>Shipping</h2><p>Orders ship in two days.</p><p>Returns are free.</p>'
		);

		foreach ( $document->spans as $span ) {
			$this->assertNotSame( '', trim( $document->slice( $span->start, $span->end ) ) );
		}

		$this->assertSame( "Orders ship in two days.\n\nReturns are free.", $document->text );
	}
}
