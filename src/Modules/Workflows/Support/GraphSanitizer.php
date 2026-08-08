<?php
/**
 * Cleans a graph arriving from the client.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Workflows\Support;

use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Domain\Workflow\ActionType;
use Hiveclerk\Domain\Workflow\ConditionField;
use Hiveclerk\Domain\Workflow\ConditionOperator;
use Hiveclerk\Domain\Workflow\NodeType;
use Hiveclerk\Domain\Workflow\WorkflowGraph;
use Hiveclerk\Domain\Workflow\WorkflowNode;

/**
 * Rebuilds a graph key by key, keeping only what belongs (FR-WFL-05).
 *
 * ## Why an allowlist rather than a recursive sanitize
 *
 * This structure ends up in a JSON column, and a JSON column is read back
 * by code that builds queries, HTTP requests and email bodies out of it.
 * Running `sanitize_text_field()` over whatever arrived would keep the
 * shape the client sent — including keys nothing reads, values of types
 * nothing expects, and a `config` for a stage action carrying a `note`
 * that some future version starts trusting.
 *
 * So the node is rebuilt: known type, known edges, and for the config,
 * only the keys that node type has, each cleaned by the function that
 * fits it. Anything else is dropped silently, because there is no such
 * thing as a legitimate client sending it.
 *
 * ## Node ids are not free strings
 *
 * They are compared, used as array keys, and rendered as DOM ids in the
 * builder. Constrained to a short slug here so none of those three has to
 * defend itself separately.
 */
final class GraphSanitizer {

	/**
	 * What a node id may look like.
	 */
	private const ID_PATTERN = '/^[a-z0-9_-]{1,64}$/';

	/**
	 * Longest free-text config value kept.
	 */
	private const MAX_TEXT = 2000;

	/**
	 * Clean a whole graph.
	 *
	 * @param mixed $raw Whatever arrived.
	 * @return WorkflowGraph
	 */
	public static function clean( mixed $raw ): WorkflowGraph {
		if ( ! is_array( $raw ) ) {
			return WorkflowGraph::seed();
		}

		$nodes = array();

		foreach ( $raw as $id => $node ) {
			if ( ! is_string( $id ) || 1 !== preg_match( self::ID_PATTERN, $id ) || ! is_array( $node ) ) {
				continue;
			}

			$clean = self::node( $id, $node );

			if ( null !== $clean ) {
				$nodes[ $id ] = $clean;
			}

			if ( count( $nodes ) >= WorkflowGraph::MAX_NODES ) {
				// The validator reports the ceiling as an error the operator
				// can read; stopping here as well means a client sending ten
				// thousand nodes cannot make this loop the slow part of the
				// request.
				break;
			}
		}

		if ( ! isset( $nodes[ WorkflowGraph::ENTRY ] ) ) {
			// A graph without its trigger is refused by validation anyway.
			// Putting one back means the operator gets "add a step" rather
			// than "this workflow has lost its trigger", which is true and
			// unhelpful.
			$nodes[ WorkflowGraph::ENTRY ] = new WorkflowNode( WorkflowGraph::ENTRY, NodeType::Trigger );
		}

		return new WorkflowGraph( $nodes );
	}

	/**
	 * Clean one node.
	 *
	 * @param string              $id  Node id.
	 * @param array<string, mixed> $raw Node as sent.
	 * @return WorkflowNode|null
	 */
	private static function node( string $id, array $raw ): ?WorkflowNode {
		$type = NodeType::tryFromStorage( is_string( $raw['type'] ?? null ) ? $raw['type'] : null );

		if ( null === $type ) {
			return null;
		}

		$config = $raw['config'] ?? array();

		return new WorkflowNode(
			id: $id,
			type: $type,
			config: self::config( $type, is_array( $config ) ? $config : array() ),
			next: self::edge( $raw, 'next' ),
			yes: self::edge( $raw, 'yes' ),
			no: self::edge( $raw, 'no' ),
		);
	}

	/**
	 * Clean an edge.
	 *
	 * @param array<string, mixed> $raw Node as sent.
	 * @param string               $key Edge name.
	 * @return string|null
	 */
	private static function edge( array $raw, string $key ): ?string {
		$value = $raw[ $key ] ?? null;

		if ( ! is_string( $value ) || 1 !== preg_match( self::ID_PATTERN, $value ) ) {
			return null;
		}

		return $value;
	}

	/**
	 * Clean a node's configuration.
	 *
	 * @param NodeType             $type Node type.
	 * @param array<string, mixed> $raw  Configuration as sent.
	 * @return array<string, mixed>
	 */
	private static function config( NodeType $type, array $raw ): array {
		return match ( $type ) {
			NodeType::Trigger   => array(),
			NodeType::Delay     => array( 'minutes' => min( WorkflowNode::MAX_DELAY_MINUTES, absint( $raw['minutes'] ?? 0 ) ) ),
			NodeType::Condition => self::conditionConfig( $raw ),
			NodeType::Action    => self::actionConfig( $raw ),
		};
	}

	/**
	 * Clean a condition's configuration.
	 *
	 * @param array<string, mixed> $raw Configuration as sent.
	 * @return array<string, mixed>
	 */
	private static function conditionConfig( array $raw ): array {
		$field    = ConditionField::tryFromStorage( self::key( $raw, 'field' ) );
		$operator = ConditionOperator::tryFromStorage( self::key( $raw, 'operator' ) );

		$config = array(
			'field'    => $field->value ?? '',
			'operator' => $operator->value ?? '',
			'value'    => self::text( $raw, 'value' ),
		);

		if ( null !== $field && $field->needsKey() ) {
			// The qualification question key. Not an enum — questions are
			// defined per clerk — so it is cleaned as an option key, which
			// is what the answers array is keyed by.
			$config['key'] = self::key( $raw, 'key' ) ?? '';
		}

		return $config;
	}

	/**
	 * Clean an action's configuration.
	 *
	 * @param array<string, mixed> $raw Configuration as sent.
	 * @return array<string, mixed>
	 */
	private static function actionConfig( array $raw ): array {
		$action = ActionType::tryFromStorage( self::key( $raw, 'action' ) );

		if ( null === $action ) {
			return array( 'action' => '' );
		}

		$config = array( 'action' => $action->value );

		return array_merge( $config, self::actionFields( $action, $raw ) );
	}

	/**
	 * The fields one action understands, cleaned.
	 *
	 * @param ActionType           $action Action.
	 * @param array<string, mixed> $raw    Configuration as sent.
	 * @return array<string, mixed>
	 */
	private static function actionFields( ActionType $action, array $raw ): array {
		return match ( $action ) {
			ActionType::EnrolSequence => array( 'sequence' => self::uuid( $raw, 'sequence' ) ),
			ActionType::SetStage      => array( 'stage_id' => absint( $raw['stage_id'] ?? 0 ) ),
			ActionType::AdjustScore   => array(
				'points' => is_numeric( $raw['points'] ?? null ) ? (int) $raw['points'] : 0,
				'reason' => self::text( $raw, 'reason' ),
			),
			// The one multi-line field in the set. `sanitize_text_field()`
			// would flatten a note the operator wrote as three paragraphs
			// into one line, silently, and they would only find out by
			// reading a timeline weeks later.
			ActionType::AddNote       => array( 'note' => self::multiline( $raw, 'note' ) ),
			ActionType::SyncCrm       => array(),
			ActionType::Webhook       => array( 'event' => self::key( $raw, 'event' ) ?? '' ),
			ActionType::NotifyAdmin   => array(
				'recipients' => self::text( $raw, 'recipients' ),
				'subject'    => self::text( $raw, 'subject' ),
				'message'    => self::multiline( $raw, 'message' ),
			),
		};
	}

	/**
	 * A single-line string value.
	 *
	 * @param array<string, mixed> $raw Configuration as sent.
	 * @param string               $key Key.
	 * @return string
	 */
	private static function text( array $raw, string $key ): string {
		$value = $raw[ $key ] ?? null;

		if ( is_numeric( $value ) ) {
			$value = (string) $value;
		}

		if ( ! is_string( $value ) ) {
			return '';
		}

		return mb_substr( sanitize_text_field( $value ), 0, self::MAX_TEXT );
	}

	/**
	 * A multi-line string value.
	 *
	 * @param array<string, mixed> $raw Configuration as sent.
	 * @param string               $key Key.
	 * @return string
	 */
	private static function multiline( array $raw, string $key ): string {
		$value = $raw[ $key ] ?? null;

		if ( ! is_string( $value ) ) {
			return '';
		}

		return mb_substr( sanitize_textarea_field( $value ), 0, self::MAX_TEXT );
	}

	/**
	 * An option-key value.
	 *
	 * @param array<string, mixed> $raw Configuration as sent.
	 * @param string               $key Key.
	 * @return string|null
	 */
	private static function key( array $raw, string $key ): ?string {
		$value = $raw[ $key ] ?? null;

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		$clean = sanitize_key( $value );

		return '' === $clean ? null : $clean;
	}

	/**
	 * A UUID value, or the empty string.
	 *
	 * Kept as an empty string rather than dropped, so a template can ship
	 * a sequence node with nothing chosen and the builder can show the
	 * field waiting to be filled in.
	 *
	 * @param array<string, mixed> $raw Configuration as sent.
	 * @param string               $key Key.
	 * @return string
	 */
	private static function uuid( array $raw, string $key ): string {
		$value = $raw[ $key ] ?? null;

		if ( ! is_string( $value ) ) {
			return '';
		}

		return Uuid::isValid( $value ) ? $value : '';
	}
}
