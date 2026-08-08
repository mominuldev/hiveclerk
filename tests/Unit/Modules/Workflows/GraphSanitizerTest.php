<?php
/**
 * Graph input tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Modules\Workflows;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Hiveclerk\Domain\Workflow\ActionType;
use Hiveclerk\Domain\Workflow\NodeType;
use Hiveclerk\Domain\Workflow\WorkflowGraph;
use Hiveclerk\Modules\Workflows\Support\GraphSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What survives the boundary.
 *
 * A graph is a JSON column, and a JSON column is read back by code that
 * builds email bodies, HTTP payloads and queries out of it. So the node is
 * rebuilt from an allowlist rather than cleaned in place: the tests here
 * are all versions of "the client sent something nobody would send", and
 * the assertion is always that it did not survive.
 *
 * @internal
 */
#[CoversClass( GraphSanitizer::class )]
final class GraphSanitizerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'sanitize_key' )->alias(
			static fn ( string $value ): string => strtolower(
				(string) preg_replace( '/[^a-zA-Z0-9_\-]/', '', $value )
			)
		);

		Functions\when( 'sanitize_text_field' )->alias(
			static fn ( string $value ): string => trim(
				(string) preg_replace( '/[\r\n\t]+|<[^>]*>/', '', $value )
			)
		);

		Functions\when( 'sanitize_textarea_field' )->alias(
			static fn ( string $value ): string => (string) preg_replace( '/<[^>]*>/', '', $value )
		);

		Functions\when( 'absint' )->alias( static fn ( $value ): int => abs( (int) $value ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function testAGraphThatIsNotAnArrayBecomesAnEmptyOne(): void {
		$graph = GraphSanitizer::clean( 'not a graph' );

		self::assertSame( 1, $graph->size() );
		self::assertNotNull( $graph->trigger() );
	}

	public function testATriggerIsPutBackWhenTheClientOmitsIt(): void {
		$graph = GraphSanitizer::clean(
			array(
				'act' => array(
					'type'   => 'action',
					'config' => array( 'action' => 'add_note' ),
				),
			)
		);

		self::assertNotNull( $graph->trigger() );
	}

	public function testANodeIdThatIsNotASlugIsDropped(): void {
		// Ids are array keys, they are compared, and they are rendered as
		// DOM ids in the builder. Constraining them once at the boundary
		// means none of the three has to defend itself.
		$graph = GraphSanitizer::clean(
			array(
				WorkflowGraph::ENTRY        => array(
					'type' => 'trigger',
					'next' => 'ok',
				),
				'ok'                        => array(
					'type'   => 'delay',
					'config' => array( 'minutes' => 60 ),
				),
				'<script>alert(1)</script>' => array(
					'type'   => 'delay',
					'config' => array( 'minutes' => 60 ),
				),
			)
		);

		self::assertSame( 2, $graph->size() );
	}

	public function testConfigurationKeysTheNodeDoesNotOwnAreDropped(): void {
		$graph = GraphSanitizer::clean(
			array(
				WorkflowGraph::ENTRY => array(
					'type' => 'trigger',
					'next' => 'act',
				),
				'act'                => array(
					'type'   => 'action',
					'config' => array(
						'action'     => ActionType::SetStage->value,
						'stage_id'   => 4,
						// A field belonging to a different action, and one
						// belonging to none. Both would sit in the JSON
						// column waiting for a future version to trust them.
						'recipients' => 'someone@example.test',
						'shell'      => 'rm -rf /',
					),
				),
			)
		);

		$config = $graph->node( 'act' )?->config ?? array();

		self::assertSame(
			array(
				'action'   => ActionType::SetStage->value,
				'stage_id' => 4,
			),
			$config
		);
	}

	public function testAnEdgePointingAtSomethingUnsluggedIsDropped(): void {
		$graph = GraphSanitizer::clean(
			array(
				WorkflowGraph::ENTRY => array(
					'type' => 'trigger',
					'next' => '../../etc/passwd',
				),
			)
		);

		self::assertNull( $graph->entry() );
	}

	public function testAMultiLineNoteKeepsItsLineBreaks(): void {
		// sanitize_text_field() would flatten this silently, and the
		// operator would only find out by reading a timeline weeks later.
		$graph = GraphSanitizer::clean(
			array(
				WorkflowGraph::ENTRY => array(
					'type' => 'trigger',
					'next' => 'act',
				),
				'act'                => array(
					'type'   => 'action',
					'config' => array(
						'action' => ActionType::AddNote->value,
						'note'   => "First line.\n\nSecond line.",
					),
				),
			)
		);

		self::assertStringContainsString( "\n", (string) $graph->node( 'act' )?->string( 'note' ) );
	}

	public function testADelayLongerThanTheCeilingIsClamped(): void {
		$graph = GraphSanitizer::clean(
			array(
				WorkflowGraph::ENTRY => array(
					'type' => 'trigger',
					'next' => 'wait',
				),
				'wait'               => array(
					'type'   => 'delay',
					'config' => array( 'minutes' => 99999999 ),
				),
			)
		);

		self::assertSame( 86400, $graph->node( 'wait' )?->delayMinutes() );
	}

	public function testASequenceThatIsNotAUuidIsNotStored(): void {
		$graph = GraphSanitizer::clean(
			array(
				WorkflowGraph::ENTRY => array(
					'type' => 'trigger',
					'next' => 'act',
				),
				'act'                => array(
					'type'   => 'action',
					'config' => array(
						'action'   => ActionType::EnrolSequence->value,
						'sequence' => '1 OR 1=1',
					),
				),
			)
		);

		self::assertSame( '', $graph->node( 'act' )?->config['sequence'] ?? null );
	}

	public function testMoreNodesThanTheCeilingStopBeingRead(): void {
		$raw = array(
			WorkflowGraph::ENTRY => array(
				'type' => 'trigger',
				'next' => 'n0',
			),
		);

		for ( $i = 0; $i < 500; $i++ ) {
			$raw[ 'n' . $i ] = array(
				'type'   => 'delay',
				'config' => array( 'minutes' => 60 ),
			);
		}

		self::assertLessThanOrEqual( WorkflowGraph::MAX_NODES + 1, GraphSanitizer::clean( $raw )->size() );
	}

	public function testAnUnknownNodeTypeIsDropped(): void {
		$graph = GraphSanitizer::clean(
			array(
				WorkflowGraph::ENTRY => array(
					'type' => 'trigger',
					'next' => 'weird',
				),
				'weird'              => array(
					'type'   => 'exec',
					'config' => array(),
				),
			)
		);

		self::assertNull( $graph->node( 'weird' ) );
	}

	public function testAValidGraphSurvivesIntact(): void {
		$graph = GraphSanitizer::clean(
			array(
				WorkflowGraph::ENTRY => array(
					'type' => 'trigger',
					'next' => 'check',
				),
				'check'              => array(
					'type'   => 'condition',
					'config' => array(
						'field'    => 'score',
						'operator' => 'greater_than',
						'value'    => '60',
					),
					'yes'    => 'act',
					'no'     => null,
				),
				'act'                => array(
					'type'   => 'action',
					'config' => array(
						'action' => 'add_note',
						'note'   => 'Worth a call.',
					),
				),
			)
		);

		self::assertSame( NodeType::Condition, $graph->node( 'check' )?->type );
		self::assertSame( 'act', $graph->node( 'check' )?->successor( true ) );
		self::assertNull( $graph->node( 'check' )?->successor( false ) );
		self::assertSame( 'Worth a call.', $graph->node( 'act' )?->string( 'note' ) );
	}
}
