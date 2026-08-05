<?php
/**
 * Lead export tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Modules\Leads;

use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Modules\Leads\Services\LeadExporter;
use Hiveclerk\Tests\Support\Leads\InMemoryLeads;
use Hiveclerk\Tests\Support\Leads\InMemoryStages;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Leads as CSV (FR-LED-10).
 *
 * The test that matters most here is the formula one. Every string in an
 * export came from a website visitor, and a spreadsheet opens `=…` as a
 * formula — so an unescaped export is a working attack carried out by our
 * own file on the machine of whoever opens it.
 *
 * @internal
 */
#[CoversClass( LeadExporter::class )]
final class LeadExporterTest extends TestCase {

	private InMemoryLeads $leads;

	private LeadExporter $exporter;

	protected function setUp(): void {
		parent::setUp();

		$this->leads    = new InMemoryLeads();
		$this->exporter = new LeadExporter( $this->leads, InMemoryStages::withDefaults() );
	}

	/**
	 * Store one lead.
	 *
	 * @param string      $email   Address.
	 * @param string|null $company Company.
	 * @return Lead
	 */
	private function lead( string $email, ?string $company = null ): Lead {
		return $this->leads->save(
			new Lead(
				id: null,
				uuid: Uuid::generate(),
				email: $email,
				emailHash: Lead::hashEmail( $email ),
				company: $company,
				stageId: 1,
			)
		);
	}

	public function testTheHeaderAndOneRowComeBack(): void {
		$this->lead( 'sarah@nordwind.de', 'Nordwind Outdoor' );

		$file = $this->exporter->export();

		self::assertSame( 1, $file['rows'] );
		self::assertFalse( $file['truncated'] );
		self::assertStringContainsString( '"email"', $file['csv'] );
		self::assertStringContainsString( '"sarah@nordwind.de"', $file['csv'] );
		self::assertStringContainsString( '"New"', $file['csv'] );
	}

	public function testTheFileOpensAsUtf8InExcel(): void {
		$this->lead( 'jörg@münchen.de', 'Münchner Söhne' );

		// Without a byte-order mark Excel on Windows reads UTF-8 as
		// Latin-1 and turns every accented name into mojibake.
		self::assertStringStartsWith( "\u{FEFF}", $this->exporter->export()['csv'] );
	}

	public function testAFormulaIsNeutralised(): void {
		$this->lead( 'evil@example.test', '=HYPERLINK("http://evil.test","Click")' );

		$csv = $this->exporter->export()['csv'];

		// Prefixed with an apostrophe, which every spreadsheet reads as
		// "this is text".
		self::assertStringContainsString( "\"'=HYPERLINK", $csv );
		self::assertStringNotContainsString( '"=HYPERLINK', $csv );
	}

	public function testAQuoteInAValueIsDoubled(): void {
		$this->lead( 'q@example.test', 'The "Big" Company' );

		self::assertStringContainsString( '"The ""Big"" Company"', $this->exporter->export()['csv'] );
	}

	public function testANewlineDoesNotBreakTheRow(): void {
		$this->lead( 'n@example.test', "Line one\nLine two" );

		$file = $this->exporter->export();

		// One header line plus one data line, and the file ends with a
		// terminator — so three pieces when split on the row separator.
		self::assertCount( 3, explode( "\r\n", $file['csv'] ) );
	}

	public function testQualificationAnswersGetColumnsOfTheirOwn(): void {
		$lead = $this->lead( 'sarah@nordwind.de' );

		$lead->customFields = array( 'budget' => '€12,000' );

		$csv = $this->exporter->export( array(), array( 'budget' ) )['csv'];

		self::assertStringContainsString( '"answer_budget"', $csv );
		self::assertStringContainsString( '"€12,000"', $csv );
	}

	public function testTheQuestionKeysAreCollectedFromEveryClerk(): void {
		$keys = LeadExporter::questionKeys(
			array(
				array( 'questions' => array( array( 'key' => 'budget' ) ) ),
				array( 'questions' => array( array( 'key' => 'Budget' ), array( 'key' => 'timeline' ) ) ),
				array( 'enabled' => true ),
			)
		);

		// Normalised and deduplicated: two clerks asking the same thing
		// under different casing share one column.
		self::assertSame( array( 'budget', 'timeline' ), $keys );
	}
}
