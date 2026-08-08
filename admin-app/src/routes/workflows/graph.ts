import type {
  NodeType,
  WorkflowGraph,
  WorkflowNode,
} from '@/api/queries/useWorkflows';

/** Which edge of a node an insertion hangs from. */
export type Edge = 'next' | 'yes' | 'no';

/** The trigger's id is fixed, on the server as well as here. */
export const ENTRY = 'trigger';

/**
 * A new node id.
 *
 * Short, lowercase and slug-shaped because the server constrains ids to
 * `[a-z0-9_-]{1,64}` — they are array keys, they are compared, and they
 * are rendered as DOM ids, and a value that has to be safe in three
 * places is easier to constrain once at birth.
 *
 * Random rather than sequential: a "step 4" that becomes step 2 after a
 * deletion is a name that lies, and the ids end up in the run log where
 * somebody reads them months later.
 */
export function newId(): string {
  const random = Math.random().toString(36).slice(2, 8);

  return `n${random}`;
}

function blank(type: NodeType): WorkflowNode {
  return { type, config: defaultConfig(type), next: null, yes: null, no: null };
}

/**
 * What a freshly added node holds.
 *
 * A delay arrives at one day rather than at zero. Zero is refused by the
 * validator, so a node that starts there begins its life as a blocker on
 * a screen the operator has not finished reading — and one day is the
 * answer they wanted often enough to be worth defaulting to.
 */
function defaultConfig(type: NodeType): Record<string, unknown> {
  switch (type) {
    case 'delay':
      return { minutes: 1440 };
    case 'condition':
      return { field: 'score', operator: 'greater_than', value: '60' };
    case 'action':
      return { action: '' };
    default:
      return {};
  }
}

/**
 * Insert a node on one edge, keeping whatever was already there.
 *
 * The new node inherits the edge's old target as its own `next`, so
 * adding a step in the middle of a chain splices rather than truncates.
 * Getting this wrong silently drops everything below the insertion point,
 * which is the single most expensive bug a builder like this can have.
 */
export function insertAfter(
  graph: WorkflowGraph,
  parentId: string,
  edge: Edge,
  type: NodeType
): { graph: WorkflowGraph; id: string } {
  const parent = graph[parentId];

  if (!parent) {
    return { graph, id: parentId };
  }

  const id = newId();
  const node = blank(type);
  node.next = parent[edge];

  return {
    graph: {
      ...graph,
      [parentId]: { ...parent, [edge]: id },
      [id]: node,
    },
    id,
  };
}

/**
 * Remove a node and close the gap behind it.
 *
 * A condition keeps its yes branch and drops its no branch, because
 * something has to happen to two chains that no longer have a fork above
 * them and silently deleting both would take steps the operator never
 * selected. The screen says which branch survived.
 */
export function removeNode(
  graph: WorkflowGraph,
  id: string
): { graph: WorkflowGraph; orphanedBranch: boolean } {
  const node = graph[id];

  if (!node || id === ENTRY) {
    return { graph, orphanedBranch: false };
  }

  const successor = node.type === 'condition' ? node.yes : node.next;
  const orphanedBranch = node.type === 'condition' && node.no !== null;

  const next: WorkflowGraph = {};

  for (const [key, current] of Object.entries(graph)) {
    if (key === id) {
      continue;
    }

    next[key] = {
      ...current,
      next: current.next === id ? successor : current.next,
      yes: current.yes === id ? successor : current.yes,
      no: current.no === id ? successor : current.no,
    };
  }

  return { graph: prune(next), orphanedBranch };
}

/** Apply a patch to one node. */
export function updateNode(
  graph: WorkflowGraph,
  id: string,
  patch: Partial<WorkflowNode>
): WorkflowGraph {
  const node = graph[id];

  if (!node) {
    return graph;
  }

  return { ...graph, [id]: { ...node, ...patch } };
}

/** Apply a patch to one node's configuration. */
export function updateConfig(
  graph: WorkflowGraph,
  id: string,
  patch: Record<string, unknown>
): WorkflowGraph {
  const node = graph[id];

  if (!node) {
    return graph;
  }

  return {
    ...graph,
    [id]: { ...node, config: { ...node.config, ...patch } },
  };
}

/**
 * Drop anything the trigger can no longer reach.
 *
 * Deleting a condition orphans the chain that hung off its no branch, and
 * an unreachable node is a validation error rather than a harmless one —
 * so the cleanup happens here, at the edit, rather than turning into a
 * message about a step the operator can no longer see.
 */
export function prune(graph: WorkflowGraph): WorkflowGraph {
  const seen = new Set<string>();
  const queue: string[] = [ENTRY];

  while (queue.length > 0) {
    const id = queue.shift();

    if (id === undefined || seen.has(id) || !graph[id]) {
      continue;
    }

    seen.add(id);

    const node = graph[id];

    for (const edge of [node.next, node.yes, node.no]) {
      if (edge !== null) {
        queue.push(edge);
      }
    }
  }

  const pruned: WorkflowGraph = {};

  for (const id of seen) {
    const node = graph[id];

    if (node) {
      pruned[id] = node;
    }
  }

  return pruned;
}

/**
 * The straight run of nodes from an id until the next fork or the end.
 *
 * Rendering walks this rather than recursing per node, so a chain of
 * twelve actions is one list and only a condition costs a nesting level.
 */
export function chainFrom(graph: WorkflowGraph, start: string | null): string[] {
  const chain: string[] = [];
  let id = start;

  while (id !== null && !chain.includes(id)) {
    const node = graph[id];

    if (!node) {
      break;
    }

    chain.push(id);

    if (node.type === 'condition') {
      break;
    }

    id = node.next;
  }

  return chain;
}

/** How many nodes there are, not counting the trigger. */
export function stepCount(graph: WorkflowGraph): number {
  return Math.max(0, Object.keys(graph).length - 1);
}
