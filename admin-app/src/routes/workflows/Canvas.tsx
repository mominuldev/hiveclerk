import { Fragment } from 'react';
import { Clock, GitBranch, Trash2, Zap, type LucideIcon } from 'lucide-react';
import { chainFrom, ENTRY, type Edge } from './graph';
import { Badge } from '@/components/ui/Badge';
import { cn } from '@/lib/cn';
import type {
  ActionOption,
  Blocker,
  NodeType,
  Vocabulary,
  WorkflowGraph,
  WorkflowNode,
} from '@/api/queries/useWorkflows';

const ICONS: Record<NodeType, LucideIcon> = {
  trigger: Zap,
  condition: GitBranch,
  delay: Clock,
  action: Zap,
};

interface CanvasProps {
  graph: WorkflowGraph;
  vocabulary: Vocabulary;
  blockers: Blocker[];
  selected: string | null;
  triggerLabel: string;
  onSelect: (id: string) => void;
  onAdd: (parentId: string, edge: Edge, type: NodeType) => void;
  onRemove: (id: string) => void;
}

/**
 * The workflow, drawn top to bottom (FR-WFL-02).
 *
 * ## Why this is a tree and not a free canvas
 *
 * The obvious build is a pannable canvas with draggable boxes and drawn
 * connectors. It photographs well and it fails the two requirements this
 * product holds every screen to: it is not keyboard reachable in any
 * honest sense, and it needs a graph layout library that costs more of
 * the admin bundle than the whole Workflows feature.
 *
 * A workflow is also not an arbitrary graph. It starts at one trigger,
 * runs downward, and forks only at conditions — which is a tree, and a
 * tree renders as nested columns with no layout engine, reads top to
 * bottom like the thing it describes, and is navigable with Tab because
 * every part of it is a button.
 *
 * ## Branches are drawn as columns, not as arrows
 *
 * Yes on the left, No on the right, each with its own rail. Arrows
 * between free-floating boxes are the part of a node editor people
 * misread most often; two labelled columns cannot be misread.
 */
export function Canvas({
  graph,
  vocabulary,
  blockers,
  selected,
  triggerLabel,
  onSelect,
  onAdd,
  onRemove,
}: CanvasProps) {
  return (
    <div className="overflow-x-auto pb-2">
      <div className="min-w-fit space-y-2">
        <button
          type="button"
          onClick={() => onSelect(ENTRY)}
          className={cn(
            'flex w-full max-w-md items-center gap-3 rounded-lg border px-3.5 py-3 text-left transition-colors',
            'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
            selected === ENTRY
              ? 'border-accent bg-accent-subtle'
              : 'border-border bg-surface-sunken hover:border-border-strong'
          )}
        >
          <span className="flex size-7 shrink-0 items-center justify-center rounded-md bg-accent/10 text-accent-text">
            <Zap size={14} aria-hidden="true" />
          </span>
          <span className="min-w-0">
            <span className="block text-[11px] uppercase tracking-wide text-content-tertiary">
              When
            </span>
            <span className="block truncate text-sm font-medium text-content">
              {triggerLabel}
            </span>
          </span>
        </button>

        <Branch
          graph={graph}
          vocabulary={vocabulary}
          blockers={blockers}
          selected={selected}
          parentId={ENTRY}
          edge="next"
          onSelect={onSelect}
          onAdd={onAdd}
          onRemove={onRemove}
          depth={0}
        />
      </div>
    </div>
  );
}

interface BranchProps extends Omit<CanvasProps, 'triggerLabel'> {
  parentId: string;
  edge: Edge;
  depth: number;
}

/**
 * One chain of steps, plus whatever hangs off a condition at its end.
 *
 * Depth is bounded at eight. Nothing enforces that server-side — the node
 * ceiling does the real work — but a tree nested deeper than this scrolls
 * sideways past the point of being readable, and refusing to draw it is
 * kinder than drawing it badly.
 */
function Branch({
  graph,
  vocabulary,
  blockers,
  selected,
  parentId,
  edge,
  onSelect,
  onAdd,
  onRemove,
  depth,
}: BranchProps) {
  const parent = graph[parentId];
  const chain = chainFrom(graph, parent ? parent[edge] : null);
  const last = chain.at(-1);

  if (depth > 8) {
    return (
      <p className="py-2 text-xs text-content-tertiary">
        This branch is nested too deeply to draw. Simplify it to carry on
        editing.
      </p>
    );
  }

  return (
    <div className="space-y-2">
      {chain.map((id, index) => {
        const node = graph[id];
        const previous = (index === 0 ? parentId : chain[index - 1]) ?? parentId;
        const previousEdge: Edge = index === 0 ? edge : 'next';

        if (!node) {
          return null;
        }

        return (
          <Fragment key={id}>
            <Rail />

            <AddHere
              onAdd={(type) => onAdd(previous, previousEdge, type)}
              label="Add a step here"
            />

            <Rail />

            <NodeCard
              id={id}
              node={node}
              vocabulary={vocabulary}
              blocker={blockers.find((b) => b.node === id)?.message}
              selected={selected === id}
              onSelect={() => onSelect(id)}
              onRemove={() => onRemove(id)}
            />

            {node.type === 'condition' && (
              <div className="flex flex-wrap gap-4 pl-4 pt-1 sm:flex-nowrap">
                {(['yes', 'no'] as const).map((branch) => (
                  <div
                    key={branch}
                    className="min-w-[19rem] flex-1 rounded-lg border border-dashed border-border p-3"
                  >
                    <Badge tone={branch === 'yes' ? 'positive' : 'neutral'}>
                      {branch === 'yes' ? 'Yes' : 'No'}
                    </Badge>

                    <Branch
                      graph={graph}
                      vocabulary={vocabulary}
                      blockers={blockers}
                      selected={selected}
                      parentId={id}
                      edge={branch}
                      onSelect={onSelect}
                      onAdd={onAdd}
                      onRemove={onRemove}
                      depth={depth + 1}
                    />

                    {node[branch] === null && (
                      <p className="pt-2 text-[11px] leading-relaxed text-content-tertiary">
                        Nothing here — the workflow ends for anyone who takes
                        this branch.
                      </p>
                    )}
                  </div>
                ))}
              </div>
            )}
          </Fragment>
        );
      })}

      {/* The tail. A chain that ends in a condition already offers its own
          two branches, so a third button under it would be an edge the
          graph has no room for. */}
      {(last === undefined || graph[last]?.type !== 'condition') && (
        <>
          <Rail />
          <AddHere
            onAdd={(type) =>
              onAdd(last ?? parentId, last === undefined ? edge : 'next', type)
            }
            label={last === undefined ? 'Add the first step' : 'Then'}
            prominent={last === undefined}
          />
        </>
      )}
    </div>
  );
}

function Rail() {
  return <div className="ml-[13px] h-3 w-px bg-border" aria-hidden="true" />;
}

/**
 * The one control that adds anything.
 *
 * Three buttons rather than a menu behind a plus. The set is small, the
 * words are the whole vocabulary of the feature, and an operator who has
 * never seen this screen learns what a workflow is made of by reading a
 * row of buttons.
 */
function AddHere({
  onAdd,
  label,
  prominent = false,
}: {
  onAdd: (type: NodeType) => void;
  label: string;
  prominent?: boolean;
}) {
  const options: Array<{ type: NodeType; label: string; icon: LucideIcon }> = [
    { type: 'action', label: 'Do something', icon: Zap },
    { type: 'condition', label: 'Check something', icon: GitBranch },
    { type: 'delay', label: 'Wait', icon: Clock },
  ];

  return (
    <div
      className={cn(
        'group flex items-center gap-1.5',
        !prominent && 'opacity-60 transition-opacity hover:opacity-100 focus-within:opacity-100'
      )}
    >
      <span className="text-[11px] text-content-tertiary">{label}</span>

      {options.map(({ type, label: optionLabel, icon: Icon }) => (
        <button
          key={type}
          type="button"
          onClick={() => onAdd(type)}
          className={cn(
            'inline-flex items-center gap-1 rounded-md border border-border bg-surface px-2 py-1',
            'text-[11px] text-content-secondary transition-colors',
            'hover:border-border-strong hover:text-content',
            'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent'
          )}
        >
          <Icon size={11} aria-hidden="true" />
          {optionLabel}
        </button>
      ))}
    </div>
  );
}

function NodeCard({
  id,
  node,
  vocabulary,
  blocker,
  selected,
  onSelect,
  onRemove,
}: {
  id: string;
  node: WorkflowNode;
  vocabulary: Vocabulary;
  blocker: string | undefined;
  selected: boolean;
  onSelect: () => void;
  onRemove: () => void;
}) {
  const Icon = ICONS[node.type];

  return (
    <div
      className={cn(
        'flex max-w-md items-start gap-3 rounded-lg border px-3.5 py-3 transition-colors',
        selected
          ? 'border-accent bg-accent-subtle'
          : blocker
            ? 'border-warning/40 bg-surface'
            : 'border-border bg-surface hover:border-border-strong'
      )}
    >
      <span className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-md bg-surface-sunken text-content-secondary">
        <Icon size={14} aria-hidden="true" />
      </span>

      <button
        type="button"
        onClick={onSelect}
        className="min-w-0 flex-1 text-left focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
      >
        <span className="block text-sm font-medium text-content">
          {headline(node, vocabulary)}
        </span>
        <span className="mt-0.5 block text-xs text-content-tertiary">
          {detail(node, vocabulary)}
        </span>
        {blocker && (
          <span className="mt-1 block text-xs leading-relaxed text-warning-ink">
            {blocker}
          </span>
        )}
      </button>

      <button
        type="button"
        onClick={onRemove}
        aria-label={`Delete this step (${headline(node, vocabulary)})`}
        className={cn(
          'shrink-0 rounded-md p-1 text-content-tertiary transition-colors',
          'hover:bg-danger/10 hover:text-danger-ink',
          'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent'
        )}
        data-node={id}
      >
        <Trash2 size={13} aria-hidden="true" />
      </button>
    </div>
  );
}

/** The bold line on a card: what this step is. */
function headline(node: WorkflowNode, vocabulary: Vocabulary): string {
  switch (node.type) {
    case 'delay':
      return formatDelay(Number(node.config.minutes ?? 0));
    case 'condition': {
      const field = vocabulary.fields.find((f) => f.value === node.config.field);

      return field ? `If ${field.label.toLowerCase()}` : 'If…';
    }
    case 'action': {
      const action = actionFor(node, vocabulary);

      return action ? action.label : 'Pick an action';
    }
    default:
      return 'Trigger';
  }
}

/** The quiet line under it: the part that changes between two of the same. */
function detail(node: WorkflowNode, vocabulary: Vocabulary): string {
  switch (node.type) {
    case 'condition': {
      const operator = vocabulary.operators.find(
        (o) => o.value === node.config.operator
      );
      const value = String(node.config.value ?? '');

      if (!operator) {
        return 'Not configured yet';
      }

      return operator.needs_value
        ? `${operator.label} ${value === '' ? '…' : value}`
        : operator.label;
    }
    case 'action': {
      const action = actionFor(node, vocabulary);

      if (!action) {
        return 'This step does nothing yet';
      }

      return action.available
        ? action.description
        : 'Not available on this site';
    }
    case 'delay':
      return 'Then carry on';
    default:
      return '';
  }
}

function actionFor(
  node: WorkflowNode,
  vocabulary: Vocabulary
): ActionOption | undefined {
  return vocabulary.actions.find((a) => a.value === node.config.action);
}

/**
 * A wait, in the unit somebody would say out loud.
 *
 * "Wait 2880 minutes" is a number nobody can picture. The builder stores
 * minutes because the engine needs one unit; the screen never shows them
 * above an hour.
 */
export function formatDelay(minutes: number): string {
  if (minutes >= 1440) {
    const days = Math.round(minutes / 1440);

    return `Wait ${days} ${days === 1 ? 'day' : 'days'}`;
  }

  if (minutes >= 60) {
    const hours = Math.round(minutes / 60);

    return `Wait ${hours} ${hours === 1 ? 'hour' : 'hours'}`;
  }

  return `Wait ${minutes} ${minutes === 1 ? 'minute' : 'minutes'}`;
}
