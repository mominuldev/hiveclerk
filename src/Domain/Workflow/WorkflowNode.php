<?php
/**
 * One node in a workflow graph.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Workflow;

/**
 * A step, its configuration, and where control goes next.
 *
 * Edges are stored on the node rather than in a separate edge list. A
 * graph this shape cannot express an edge whose source was deleted, which
 * is the corruption a separate list makes possible and a builder screen
 * makes likely — deleting a step is the most common edit there is.
 *
 * Condition nodes use {@see self::$yes} and {@see self::$no}; everything
 * else uses {@see self::$next}. Both spellings are kept rather than
 * overloading one field, because "the false branch" and "carry on" are
 * different intentions and a null in a shared field cannot tell them apart.
 */
final readonly class WorkflowNode {

	/**
	 * The longest a single wait may be: sixty days.
	 *
	 * Longer than any follow-up this product is for, and short enough that
	 * a run cannot sit in the table until the table is the problem.
	 */
	public const MAX_DELAY_MINUTES = 86400;

	/**
	 * Construct.
	 *
	 * @param string               $id     Graph-unique identifier.
	 * @param NodeType             $type   What this node does.
	 * @param array<string, mixed> $config Node configuration.
	 * @param string|null          $next   Next node, for non-branching nodes.
	 * @param string|null          $yes    Next node when a condition matches.
	 * @param string|null          $no     Next node when it does not.
	 */
	public function __construct(
		public string $id,
		public NodeType $type,
		public array $config = array(),
		public ?string $next = null,
		public ?string $yes = null,
		public ?string $no = null,
	) {
	}

	/**
	 * A string from this node's configuration.
	 *
	 * @param string      $key      Configuration key.
	 * @param string|null $fallback Value when absent or wrong-typed.
	 * @return string|null
	 */
	public function string( string $key, ?string $fallback = null ): ?string {
		$value = $this->config[ $key ] ?? null;

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return $fallback;
		}

		return trim( $value );
	}

	/**
	 * An integer from this node's configuration.
	 *
	 * @param string $key      Configuration key.
	 * @param int    $fallback Value when absent or wrong-typed.
	 * @return int
	 */
	public function int( string $key, int $fallback = 0 ): int {
		$value = $this->config[ $key ] ?? null;

		return is_numeric( $value ) ? (int) $value : $fallback;
	}

	/**
	 * The action this node performs, when it is an action node.
	 *
	 * @return ActionType|null
	 */
	public function action(): ?ActionType {
		if ( NodeType::Action !== $this->type ) {
			return null;
		}

		return ActionType::tryFromStorage( $this->string( 'action' ) );
	}

	/**
	 * How long a delay node waits, in minutes.
	 *
	 * Clamped rather than validated here so the engine can never be handed
	 * a negative wait, which would resume a run before it paused and turn
	 * a delay into a hot loop.
	 *
	 * @return int
	 */
	public function delayMinutes(): int {
		return max( 0, min( self::MAX_DELAY_MINUTES, $this->int( 'minutes' ) ) );
	}

	/**
	 * Every node this one can hand control to.
	 *
	 * @return array<int, string>
	 */
	public function edges(): array {
		$edges = array();

		foreach ( array( $this->next, $this->yes, $this->no ) as $edge ) {
			if ( null !== $edge && '' !== $edge ) {
				$edges[] = $edge;
			}
		}

		return array_values( array_unique( $edges ) );
	}

	/**
	 * Where control goes after this node, given a condition result.
	 *
	 * @param bool $matched Whether a condition matched. Ignored elsewhere.
	 * @return string|null Next node id, or null to end the run.
	 */
	public function successor( bool $matched = true ): ?string {
		if ( ! $this->type->branches() ) {
			return '' === (string) $this->next ? null : $this->next;
		}

		$edge = $matched ? $this->yes : $this->no;

		return '' === (string) $edge ? null : $edge;
	}

	/**
	 * Rebuild from stored JSON.
	 *
	 * @param string               $id  Node id.
	 * @param array<string, mixed> $row Stored node.
	 * @return self|null Null when the row names no known node type.
	 */
	public static function fromArray( string $id, array $row ): ?self {
		$type = NodeType::tryFromStorage( is_string( $row['type'] ?? null ) ? $row['type'] : null );

		if ( null === $type ) {
			return null;
		}

		$config = $row['config'] ?? array();

		return new self(
			id: $id,
			type: $type,
			config: is_array( $config ) ? $config : array(),
			next: self::edge( $row, 'next' ),
			yes: self::edge( $row, 'yes' ),
			no: self::edge( $row, 'no' ),
		);
	}

	/**
	 * Storage form.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'type'   => $this->type->value,
			'config' => $this->config,
			'next'   => $this->next,
			'yes'    => $this->yes,
			'no'     => $this->no,
		);
	}

	/**
	 * Read one edge from a stored node.
	 *
	 * @param array<string, mixed> $row Stored node.
	 * @param string               $key Edge name.
	 * @return string|null
	 */
	private static function edge( array $row, string $key ): ?string {
		$value = $row[ $key ] ?? null;

		return is_string( $value ) && '' !== $value ? $value : null;
	}
}
