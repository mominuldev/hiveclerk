<?php
/**
 * Starting points.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Workflows\Services;

use Hiveclerk\Domain\Workflow\ActionType;
use Hiveclerk\Domain\Workflow\ConditionField;
use Hiveclerk\Domain\Workflow\ConditionOperator;
use Hiveclerk\Domain\Workflow\NodeType;
use Hiveclerk\Domain\Workflow\TriggerEvent;
use Hiveclerk\Domain\Workflow\WorkflowGraph;
use Hiveclerk\Domain\Workflow\WorkflowNode;

/**
 * Four workflows worth having, ready to edit.
 *
 * ## Every template is a draft, and every one is incomplete on purpose
 *
 * Templates that arrive fully configured are templates that get activated
 * without being read. Each of these leaves exactly the decisions that
 * depend on the site — which sequence, which stage, whose address — for
 * the operator, so activation refuses until somebody has looked at each
 * one. The blocker list is then the tour of the workflow.
 *
 * ## Why these four
 *
 * They are the shapes people actually build: chase a good lead, rescue a
 * conversation that asked for help, notice a lead going cold, and tell
 * somebody when a big one lands. Between them they use every node type
 * and every trigger the engine has, which makes them the fastest way to
 * learn what the builder can do without reading anything.
 */
final class WorkflowTemplates {

	/**
	 * Every template, as the API returns them.
	 *
	 * @return array<int, array{id: string, name: string, description: string, trigger: string}>
	 */
	public static function all(): array {
		return array(
			array(
				'id'          => 'qualified-follow-up',
				'name'        => __( 'Chase a qualified lead', 'hiveclerk' ),
				'description' => __( 'When a lead qualifies, wait an hour, then start a sequence and move them into the pipeline.', 'hiveclerk' ),
				'trigger'     => TriggerEvent::LeadQualified->value,
			),
			array(
				'id'          => 'handoff-alert',
				'name'        => __( 'Alert on a handoff request', 'hiveclerk' ),
				'description' => __( 'When a visitor asks for a person, email the team straight away.', 'hiveclerk' ),
				'trigger'     => TriggerEvent::HandoffRequested->value,
			),
			array(
				'id'          => 'cold-revival',
				'name'        => __( 'Revive quiet leads', 'hiveclerk' ),
				'description' => __( 'Every day, take leads with an address who have gone quiet, and try one more time.', 'hiveclerk' ),
				'trigger'     => TriggerEvent::Schedule->value,
			),
			array(
				'id'          => 'high-value-capture',
				'name'        => __( 'Flag a high-value capture', 'hiveclerk' ),
				'description' => __( 'On capture, split on the score: strong leads go to the team, the rest get a note.', 'hiveclerk' ),
				'trigger'     => TriggerEvent::LeadCaptured->value,
			),
		);
	}

	/**
	 * The trigger a template starts from.
	 *
	 * @param string $id Template id.
	 * @return TriggerEvent|null
	 */
	public static function triggerFor( string $id ): ?TriggerEvent {
		foreach ( self::all() as $template ) {
			if ( $template['id'] === $id ) {
				return TriggerEvent::tryFrom( $template['trigger'] );
			}
		}

		return null;
	}

	/**
	 * The trigger configuration a template starts with.
	 *
	 * @param string $id Template id.
	 * @return array<string, mixed>
	 */
	public static function configFor( string $id ): array {
		if ( 'cold-revival' !== $id ) {
			return array();
		}

		return array(
			'interval' => 1440,
			'segment'  => array(
				'has_email' => true,
				'status'    => 'new',
			),
		);
	}

	/**
	 * The graph a template starts with.
	 *
	 * @param string $id Template id.
	 * @return WorkflowGraph|null
	 */
	public static function graphFor( string $id ): ?WorkflowGraph {
		return match ( $id ) {
			'qualified-follow-up' => self::qualifiedFollowUp(),
			'handoff-alert'       => self::handoffAlert(),
			'cold-revival'        => self::coldRevival(),
			'high-value-capture'  => self::highValueCapture(),
			default               => null,
		};
	}

	/**
	 * Wait, enrol, then move the card.
	 *
	 * @return WorkflowGraph
	 */
	private static function qualifiedFollowUp(): WorkflowGraph {
		return new WorkflowGraph(
			array(
				WorkflowGraph::ENTRY => new WorkflowNode( WorkflowGraph::ENTRY, NodeType::Trigger, array(), 'wait' ),
				// An hour, not a minute. A follow-up that arrives while the
				// visitor is still on the page reads as surveillance.
				'wait'               => new WorkflowNode( 'wait', NodeType::Delay, array( 'minutes' => 60 ), 'enrol' ),
				'enrol'              => new WorkflowNode(
					'enrol',
					NodeType::Action,
					array(
						'action'   => ActionType::EnrolSequence->value,
						'sequence' => '',
					),
					'stage'
				),
				'stage'              => new WorkflowNode(
					'stage',
					NodeType::Action,
					array( 'action' => ActionType::SetStage->value ),
				),
			)
		);
	}

	/**
	 * Tell somebody, now.
	 *
	 * @return WorkflowGraph
	 */
	private static function handoffAlert(): WorkflowGraph {
		return new WorkflowGraph(
			array(
				WorkflowGraph::ENTRY => new WorkflowNode( WorkflowGraph::ENTRY, NodeType::Trigger, array(), 'notify' ),
				'notify'             => new WorkflowNode(
					'notify',
					NodeType::Action,
					array(
						'action'  => ActionType::NotifyAdmin->value,
						'subject' => __( 'A visitor is asking for a person', 'hiveclerk' ),
						'message' => __( "A conversation has asked to be handed to a human.\n\nOpen Conversations in Hiveclerk to pick it up.", 'hiveclerk' ),
					),
				),
			)
		);
	}

	/**
	 * Only the ones still worth reviving.
	 *
	 * @return WorkflowGraph
	 */
	private static function coldRevival(): WorkflowGraph {
		return new WorkflowGraph(
			array(
				WorkflowGraph::ENTRY => new WorkflowNode( WorkflowGraph::ENTRY, NodeType::Trigger, array(), 'quiet' ),
				// Seven days rather than two: a lead captured on Friday
				// should not be "gone quiet" on Sunday.
				'quiet'              => new WorkflowNode(
					'quiet',
					NodeType::Condition,
					array(
						'field'    => ConditionField::DaysSinceCreated->value,
						'operator' => ConditionOperator::GreaterThan->value,
						'value'    => '7',
					),
					null,
					'enrol'
				),
				'enrol'              => new WorkflowNode(
					'enrol',
					NodeType::Action,
					array(
						'action'   => ActionType::EnrolSequence->value,
						'sequence' => '',
					),
				),
			)
		);
	}

	/**
	 * Split on the score.
	 *
	 * @return WorkflowGraph
	 */
	private static function highValueCapture(): WorkflowGraph {
		return new WorkflowGraph(
			array(
				WorkflowGraph::ENTRY => new WorkflowNode( WorkflowGraph::ENTRY, NodeType::Trigger, array(), 'strong' ),
				'strong'             => new WorkflowNode(
					'strong',
					NodeType::Condition,
					array(
						'field'    => ConditionField::Score->value,
						'operator' => ConditionOperator::GreaterThan->value,
						'value'    => '70',
					),
					null,
					'notify',
					'note'
				),
				'notify'             => new WorkflowNode(
					'notify',
					NodeType::Action,
					array(
						'action'  => ActionType::NotifyAdmin->value,
						'subject' => __( 'High-scoring lead: {lead.name}', 'hiveclerk' ),
						'message' => __( "{lead.name} scored {lead.score} on capture.\n\nCompany: {lead.company}\nEmail: {lead.email}", 'hiveclerk' ),
					),
				),
				'note'               => new WorkflowNode(
					'note',
					NodeType::Action,
					array(
						'action' => ActionType::AddNote->value,
						'note'   => __( 'Captured at {lead.score} — below the alert threshold.', 'hiveclerk' ),
					),
				),
			)
		);
	}
}
