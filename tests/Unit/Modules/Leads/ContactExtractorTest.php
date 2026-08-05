<?php
/**
 * Contact extraction tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Modules\Leads;

use Hiveclerk\Modules\Leads\Support\ContactExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What a visitor's own words give up (FR-LED-01).
 *
 * Half of these assert that nothing is extracted. That is the point: the
 * expensive failure here is not a missed name, it is a lead an operator
 * greets by somebody else's — so the sentences below that look like
 * details and are not have to keep producing nothing.
 *
 * @internal
 */
#[CoversClass( ContactExtractor::class )]
final class ContactExtractorTest extends TestCase {

	private ContactExtractor $extractor;

	protected function setUp(): void {
		parent::setUp();

		$this->extractor = new ContactExtractor();
	}

	public function testAnAddressIsFoundInTheMiddleOfASentence(): void {
		$found = $this->extractor->fromMessage(
			'Sure — send it to Sarah.Klein@Nordwind.de and I will look tonight.'
		);

		self::assertSame( 'sarah.klein@nordwind.de', $found->email );
	}

	public function testATrailingFullStopIsNotPartOfTheAddress(): void {
		$found = $this->extractor->fromMessage( 'My email is s.klein@nordwind.de.' );

		self::assertSame( 's.klein@nordwind.de', $found->email );
	}

	public function testSomethingThatIsNotAnAddressIsNotOne(): void {
		// Matched by the pattern, rejected by validation. An invalid
		// address becomes a hash that dedups against nothing and a
		// follow-up that bounces.
		self::assertNull( $this->extractor->fromMessage( 'try me @ home.' )->email );
	}

	public function testAStatedNameIsTaken(): void {
		$found = $this->extractor->fromMessage( 'Hi, my name is Sarah Klein.' );

		self::assertSame( 'Sarah', $found->firstName );
		self::assertSame( 'Klein', $found->lastName );
	}

	public function testALowerCaseNameIsTakenAndCapitalised(): void {
		// Plenty of people type in lower case, and "my name is" leaves no
		// room for doubt about what follows it.
		$found = $this->extractor->fromMessage( 'my name is sarah klein' );

		self::assertSame( 'Sarah', $found->firstName );
		self::assertSame( 'Klein', $found->lastName );
	}

	public function testAnIntentionSentenceIsNotAName(): void {
		// "I'm looking for a quote" matches a naive "I'm <word>" rule and
		// produces a lead called Looking.
		self::assertNull(
			$this->extractor->fromMessage( "I'm looking for a quote on 40 jackets" )->firstName
		);
		self::assertNull(
			$this->extractor->fromMessage( "i'm interested in the blue one" )->firstName
		);
		self::assertNull(
			$this->extractor->fromMessage( "I'm after a trade account" )->firstName
		);
	}

	public function testAStatedCompanyIsTaken(): void {
		$found = $this->extractor->fromMessage( 'I work at Nordwind Outdoor GmbH, we buy in bulk.' );

		self::assertSame( 'Nordwind Outdoor GmbH', $found->company );
	}

	public function testWeAreInterestedIsNotACompany(): void {
		self::assertNull(
			$this->extractor->fromMessage( "We're interested in a trade account" )->company
		);
	}

	public function testAPhoneNumberIsTakenAndAnOrderNumberIsNot(): void {
		self::assertSame(
			'+49 30 12345678',
			$this->extractor->fromMessage( 'Call me on +49 30 12345678' )->phone
		);

		// Short digit runs are order numbers, postcodes, prices and years.
		// A lead whose phone field holds "2024" is worse than a blank one.
		self::assertNull( $this->extractor->fromMessage( 'My order is 48122' )->phone );
	}

	public function testTheEarliestStatementWins(): void {
		$found = $this->extractor->fromMessages(
			array(
				'Hi, my name is Sarah Klein',
				'My colleague is called Dave Marsh',
			)
		);

		// A later message naming somebody else must not rewrite who this
		// conversation is with.
		self::assertSame( 'Sarah', $found->firstName );
	}

	public function testAnEmptyMessageGivesUpNothing(): void {
		self::assertTrue( $this->extractor->fromMessage( '   ' )->isEmpty() );
	}
}
