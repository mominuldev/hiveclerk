<?php
/**
 * Merge tag tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Modules\Email;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Modules\Email\Services\MergeTags;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What a recipient actually reads.
 *
 * Every failure here is visible to the wrong person: a literal
 * `{{fist_name}}` in front of a prospect, a greeting that reads "Hi ,",
 * or a company name that arrives as `Smith &amp; Sons`. None of them is
 * catchable after the fact.
 *
 * @internal
 */
#[CoversClass( MergeTags::class )]
final class MergeTagsTest extends TestCase {

	private MergeTags $tags;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'esc_html' )->alias(
			static fn ( string $text ): string => htmlspecialchars( $text, ENT_QUOTES )
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'Example Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );

		$this->tags = new MergeTags();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_it_fills_in_what_is_known(): void {
		$this->assertSame(
			'Hi Sam,',
			$this->tags->render( 'Hi {{first_name}},', $this->lead( 'Sam' ) )
		);
	}

	public function test_a_fallback_covers_an_unknown_value(): void {
		// Without this, every template either greets somebody by name or
		// greets nobody — and a lead captured from an address alone gets
		// "Hi ,".
		$this->assertSame(
			'Hi there,',
			$this->tags->render( 'Hi {{first_name|there}},', $this->lead( null ) )
		);
	}

	public function test_an_unknown_tag_renders_as_nothing(): void {
		// Not as itself. A typo must not put {{fist_name}} in front of a
		// customer's prospect.
		$this->assertSame(
			'Hi ,',
			$this->tags->render( 'Hi {{fist_name}},', $this->lead( 'Sam' ) )
		);
	}

	public function test_it_tolerates_whitespace_inside_the_braces(): void {
		$this->assertSame(
			'Hi Sam',
			$this->tags->render( 'Hi {{ first_name }}', $this->lead( 'Sam' ) )
		);
	}

	public function test_values_are_escaped_in_html(): void {
		$lead = $this->lead( 'Sam' );

		$lead->company = 'Smith & <b>Sons</b>';

		$this->assertSame(
			'Smith &amp; &lt;b&gt;Sons&lt;/b&gt;',
			$this->tags->render( '{{company}}', $lead )
		);
	}

	public function test_a_subject_line_is_not_escaped(): void {
		// A subject is not HTML. Escaping it would deliver "Smith &amp;
		// Sons" to every inbox that received it.
		$lead = $this->lead( 'Sam' );

		$lead->company = 'Smith & Sons';

		$this->assertSame(
			'Smith & Sons',
			$this->tags->render( '{{company}}', $lead, null, false )
		);
	}

	public function test_the_full_name_falls_back_to_the_address(): void {
		$this->assertSame(
			'sam@example.com',
			$this->tags->render( '{{full_name}}', $this->lead( null ) )
		);
	}

	public function test_the_unsubscribe_tag_renders_the_link(): void {
		$this->assertSame(
			'https://example.test/unsub',
			$this->tags->render( '{{unsubscribe}}', $this->lead( 'Sam' ), 'https://example.test/unsub', false )
		);
	}

	public function test_the_vocabulary_covers_every_tag_it_renders(): void {
		// The editor's tag list and the renderer come from the same
		// constant; two lists that drift apart offer a tag that renders
		// empty.
		$lead = $this->lead( 'Sam' );

		foreach ( $this->tags->vocabulary() as $tag ) {
			$this->assertNotSame(
				'',
				$this->tags->render( $tag['tag'] . 'x', $lead, 'https://example.test/unsub' ),
				sprintf( 'The %s tag rendered nothing at all.', $tag['tag'] )
			);
		}
	}

	/**
	 * A lead with an address and optionally a first name.
	 *
	 * @param string|null $firstName Given name.
	 * @return Lead
	 */
	private function lead( ?string $firstName ): Lead {
		return new Lead(
			id: 1,
			uuid: Uuid::generate(),
			email: 'sam@example.com',
			firstName: $firstName,
		);
	}
}
