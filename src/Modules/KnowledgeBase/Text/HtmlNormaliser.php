<?php
/**
 * HTML to structured plain text.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Text;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;

/**
 * Turns markup into blocks the chunker can work with.
 *
 * Not a `strip_tags()` wrapper. Three things have to survive the
 * conversion and `strip_tags()` destroys all of them:
 *
 * - **Heading structure**, which becomes the chunk's heading path and is
 *   what disambiguates "free returns within 30 days" between the retail
 *   and wholesale sections of the same page.
 * - **Block boundaries**, because `strip_tags()` welds the end of one
 *   paragraph to the start of the next and invents sentences that were
 *   never written.
 * - **The absence of chrome.** A crawled page carries a navigation menu,
 *   a cookie banner and a footer on every single URL. Indexed, they
 *   become the most repeated text in the knowledge base and match
 *   everything weakly.
 */
final class HtmlNormaliser {

	/**
	 * Elements removed with their contents.
	 */
	private const DROP = array(
		'script',
		'style',
		'noscript',
		'template',
		'svg',
		'canvas',
		'iframe',
		'object',
		'embed',
		'form',
		'button',
		'select',
		'textarea',
		// Document metadata. Removed by name rather than by skipping
		// <head>, because <head> is not reliably where head content ends
		// up — see the note in parse().
		'title',
		'meta',
		'link',
		'base',
	);

	/**
	 * Elements removed only when stripping page chrome.
	 */
	private const CHROME = array( 'nav', 'header', 'footer', 'aside', 'dialog' );

	/**
	 * Elements that end the current block.
	 */
	private const BLOCKS = array(
		'p',
		'div',
		'section',
		'article',
		'li',
		'tr',
		'blockquote',
		'pre',
		'dt',
		'dd',
		'figcaption',
		'address',
		'details',
		'summary',
	);

	/**
	 * Where the walker is up to.
	 *
	 * @var array<int, TextBlock>
	 */
	private array $blocks = array();

	/**
	 * Text accumulated for the block being built.
	 *
	 * @var string
	 */
	private string $buffer = '';

	/**
	 * Headings currently in scope, outermost first.
	 *
	 * @var array<int, string>
	 */
	private array $path = array();

	/**
	 * Convert markup to blocks.
	 *
	 * @param string             $html        Markup.
	 * @param bool               $stripChrome Whether to drop navigation and
	 *                                        footers. True for crawled
	 *                                        pages, false for post content
	 *                                        which has none.
	 * @param array<int, string> $rootPath    Headings above the fragment.
	 * @return array<int, TextBlock>
	 */
	public function toBlocks( string $html, bool $stripChrome = false, array $rootPath = array() ): array {
		$this->blocks = array();
		$this->buffer = '';
		$this->path   = $rootPath;

		if ( '' === trim( $html ) ) {
			return array();
		}

		$root = $this->parse( $html, $stripChrome );

		if ( null === $root ) {
			// Parsing failed outright. Falling back to tag stripping loses
			// the structure but keeps the words, which beats indexing
			// nothing at all for the page.
			return array( new TextBlock( $this->collapse( wp_strip_all_tags( $html ) ), $rootPath ) );
		}

		$this->walk( $root );
		$this->flush();

		return $this->blocks;
	}

	/**
	 * Convert markup straight to an assembled document.
	 *
	 * @param string             $html        Markup.
	 * @param bool               $stripChrome Whether to drop chrome.
	 * @param array<int, string> $rootPath    Headings above the fragment.
	 * @return NormalisedText
	 */
	public function normalise( string $html, bool $stripChrome = false, array $rootPath = array() ): NormalisedText {
		return NormalisedText::fromBlocks( $this->toBlocks( $html, $stripChrome, $rootPath ) );
	}

	/**
	 * Parse markup and return the node worth reading.
	 *
	 * @param string $html        Markup.
	 * @param bool   $stripChrome Whether to drop chrome.
	 * @return DOMNode|null
	 */
	private function parse( string $html, bool $stripChrome ): ?DOMNode {
		$document = new DOMDocument();

		$previous = libxml_use_internal_errors( true );

		// The meta tag rather than the XML declaration trick: libxml
		// defaults to ISO-8859-1 for a fragment with no encoding, which
		// turns every accented character into mojibake before any of the
		// rest of this runs.
		$loaded = $document->loadHTML(
			'<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html,
			LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return null;
		}

		$xpath = new DOMXPath( $document );

		$this->remove( $xpath, self::DROP );

		if ( $stripChrome ) {
			$this->remove( $xpath, self::CHROME );
			$this->removeByAttribute( $xpath );

			// A page that marks its own main content is telling us where
			// to read. Trusting it removes far more noise than any list of
			// class names could, and costs nothing when it is absent.
			foreach ( array( 'main', 'article' ) as $tag ) {
				$found = $document->getElementsByTagName( $tag );

				if ( $found->length > 0 && null !== $found->item( 0 ) ) {
					return $found->item( 0 );
				}
			}
		}

		// The whole document, not <body>.
		//
		// libxml's HTML parser is HTML 4.01 and has never heard of nav,
		// main, article, section, header, footer or aside. A document
		// beginning with one of them does not open <body> in its view, so
		// the element is parked in <head> instead — and returning <body>
		// silently drops every word of it. Measured on the fragment
		// "<nav>Menu</nav><p>Body</p>": the nav landed inside <head>.
		//
		// Walking from the root keeps document order and picks up anything
		// misfiled. The metadata elements that legitimately live in <head>
		// are in DROP, so nothing else comes with it.
		return $document->documentElement ?? $document;
	}

	/**
	 * Remove elements by tag name.
	 *
	 * @param DOMXPath           $xpath Query engine.
	 * @param array<int, string> $tags  Tag names.
	 * @return void
	 */
	private function remove( DOMXPath $xpath, array $tags ): void {
		$this->removeMatching(
			$xpath,
			implode( '|', array_map( static fn ( string $tag ): string => '//' . $tag, $tags ) )
		);
	}

	/**
	 * Remove elements that describe themselves as chrome.
	 *
	 * Landmark roles are more reliable than class names: a theme may call
	 * its menu anything, but if it has told a screen reader the region is
	 * navigation, it has told us too.
	 *
	 * @param DOMXPath $xpath Query engine.
	 * @return void
	 */
	private function removeByAttribute( DOMXPath $xpath ): void {
		$this->removeMatching(
			$xpath,
			'//*[@role="navigation" or @role="banner" or @role="contentinfo"'
			. ' or @aria-hidden="true" or @hidden]'
		);
	}

	/**
	 * Remove every node an expression matches.
	 *
	 * @param DOMXPath $xpath Query engine.
	 * @param string   $query XPath expression.
	 * @return void
	 */
	private function removeMatching( DOMXPath $xpath, string $query ): void {
		$nodes = $xpath->query( $query );

		if ( false === $nodes ) {
			return;
		}

		// Collected before removing anything. A DOMNodeList is live, so
		// deleting during iteration renumbers the list underneath the
		// cursor and skips every other match.
		$doomed = array();

		foreach ( $nodes as $node ) {
			// A query can also yield namespace nodes, which are not part
			// of the tree and have no parent to be removed from.
			if ( $node instanceof DOMNode ) {
				$doomed[] = $node;
			}
		}

		foreach ( $doomed as $node ) {
			$node->parentNode?->removeChild( $node );
		}
	}

	/**
	 * Walk the tree, accumulating blocks.
	 *
	 * @param DOMNode $node Node.
	 * @return void
	 */
	private function walk( DOMNode $node ): void {
		if ( $node instanceof DOMText ) {
			$this->buffer .= $node->wholeText;

			return;
		}

		if ( ! $node instanceof DOMElement ) {
			$this->children( $node );

			return;
		}

		$tag = strtolower( $node->nodeName );

		if ( 'br' === $tag ) {
			$this->buffer .= "\n";

			return;
		}

		if ( 1 === preg_match( '/^h([1-6])$/', $tag, $matches ) ) {
			$this->heading( (int) $matches[1], $node->textContent );

			return;
		}

		if ( 'td' === $tag || 'th' === $tag ) {
			// Cells are joined rather than separated: a table row read as
			// one line keeps the association between a label and its
			// value, which is the only reason the table was a table.
			$this->buffer .= ' ' . $node->textContent . ' |';

			return;
		}

		if ( in_array( $tag, self::BLOCKS, true ) ) {
			$this->flush();
			$this->children( $node );
			$this->flush();

			return;
		}

		$this->children( $node );
	}

	/**
	 * Walk a node's children.
	 *
	 * @param DOMNode $node Node.
	 * @return void
	 */
	private function children( DOMNode $node ): void {
		foreach ( $node->childNodes as $child ) {
			$this->walk( $child );
		}
	}

	/**
	 * Enter a new heading, adjusting the path.
	 *
	 * @param int    $level Heading level, 1-6.
	 * @param string $text  Heading text.
	 * @return void
	 */
	private function heading( int $level, string $text ): void {
		$this->flush();

		$heading = $this->collapse( $text );

		// Levels are advisory. Sites skip from h1 to h3, repeat h2 inside
		// h2, and start at h4 inside a widget. Truncating to whichever is
		// smaller keeps the path shallow and ordered instead of trying to
		// impose a hierarchy the markup does not have.
		$depth      = min( $level - 1, count( $this->path ) );
		$this->path = array_slice( $this->path, 0, max( 0, $depth ) );

		if ( '' !== $heading ) {
			$this->path[] = $heading;
		}
	}

	/**
	 * Emit whatever has accumulated as a block.
	 *
	 * @return void
	 */
	private function flush(): void {
		$text = $this->collapse( $this->buffer );

		$this->buffer = '';

		if ( '' === $text ) {
			return;
		}

		$this->blocks[] = new TextBlock( $text, $this->path );
	}

	/**
	 * Decode entities and reduce whitespace to something readable.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private function collapse( string $text ): string {
		$decoded = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Non-breaking spaces are the single most common cause of text
		// that looks clean and refuses to match anything: they are not
		// \s, so they survive every whitespace collapse and end up inside
		// words in the index.
		$decoded = str_replace( array( "\u{00A0}", "\u{200B}", "\u{FEFF}" ), ' ', $decoded );

		$decoded = preg_replace( '/[ \t]+/u', ' ', $decoded ) ?? $decoded;
		$decoded = preg_replace( '/ ?\n ?/u', "\n", $decoded ) ?? $decoded;
		$decoded = preg_replace( '/\n{2,}/u', "\n", $decoded ) ?? $decoded;
		$decoded = preg_replace( '/\s*\|\s*$/u', '', $decoded ) ?? $decoded;

		return trim( $decoded );
	}
}
