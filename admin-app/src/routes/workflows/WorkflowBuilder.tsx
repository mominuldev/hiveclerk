import { useState } from 'react';
import { Link, useParams } from 'react-router';
import { ArrowLeft, FlaskConical, History, Pause, Play } from 'lucide-react';
import { Canvas } from './Canvas';
import { NodeInspector } from './NodeInspector';
import { TestPanel } from './TestPanel';
import { TriggerSettings } from './TriggerSettings';
import { insertAfter, removeNode, updateConfig, type Edge } from './graph';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { Skeleton } from '@/components/ui/Skeleton';
import { toast } from '@/components/ui/Toast';
import {
  useUpdateWorkflow,
  useWorkflow,
  useWorkflowStatus,
  useWorkflowVocabulary,
  type NodeType,
  type WorkflowGraph,
} from '@/api/queries/useWorkflows';

/**
 * The workflow builder (FR-WFL-02, FR-WFL-05, FR-WFL-06).
 *
 * ## Activation refuses out loud, node by node
 *
 * The server will not activate a workflow whose steps are incomplete —
 * a sequence that has been deleted, a condition with nothing to compare,
 * a branch that leads back on itself — and it says which step and why.
 * Those reasons are shown twice: as a list above the canvas, and on the
 * card of the step they are about. An operator should never have to hunt
 * through nine steps for the one that is wrong.
 *
 * ## Every edit is a write
 *
 * There is no save button and no local draft. A builder that holds
 * unsaved state loses work to a closed tab, and the whole graph is a few
 * kilobytes — cheap enough that correctness is worth more than the
 * request.
 *
 * ## Nothing here runs anything
 *
 * The one control that looks like it might — Test — is a dry run. It
 * evaluates conditions against a real lead and describes what each action
 * would have done without doing it, which is what makes it safe to press
 * on a workflow pointed at a live mailing list.
 */
export function WorkflowBuilder() {
  const { uuid = '' } = useParams();

  const workflow = useWorkflow(uuid);
  const vocabulary = useWorkflowVocabulary();
  const update = useUpdateWorkflow(uuid);
  const status = useWorkflowStatus(uuid);

  const [selected, setSelected] = useState<string | null>(null);
  const [testing, setTesting] = useState(false);

  if (workflow.isError) {
    return (
      <ErrorNotice error={workflow.error} onRetry={() => void workflow.refetch()} />
    );
  }

  if (workflow.isLoading || !workflow.data || !vocabulary.data) {
    return <Skeleton className="h-96 w-full" />;
  }

  const data = workflow.data;
  const words = vocabulary.data;
  const trigger = words.triggers.find((t) => t.value === data.trigger);
  const subject = trigger?.subject ?? 'lead';

  const writeGraph = (graph: WorkflowGraph): void => {
    update.mutate(
      { graph },
      {
        onError: (error) => toast.error('That edit did not save', error.message),
      }
    );
  };

  const addStep = (parentId: string, edge: Edge, type: NodeType): void => {
    const result = insertAfter(data.graph, parentId, edge, type);

    writeGraph(result.graph);

    // Straight into the panel. A step added and left unconfigured is the
    // most common way a workflow ends up unactivatable, and opening the
    // fields is cheaper than explaining that later in a blocker list.
    setSelected(result.id);
  };

  const deleteStep = (id: string): void => {
    const { graph, orphanedBranch } = removeNode(data.graph, id);

    writeGraph(graph);

    if (selected === id) {
      setSelected(null);
    }

    if (orphanedBranch) {
      toast.info(
        'Step removed',
        'Its No branch went with it — the Yes branch is now what follows.'
      );
    }
  };

  const setStatus = (action: 'activate' | 'pause'): void => {
    status.mutate(action, {
      onSuccess: () =>
        toast.success(
          action === 'activate' ? 'Workflow is live' : 'Workflow paused',
          action === 'pause'
            ? 'Everyone part-way through keeps their place and resumes where they stopped.'
            : 'It will run the next time the trigger fires.'
        ),
      onError: (error) => toast.error('That did not work', error.message),
    });
  };

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <Link
          to="/workflows"
          className="inline-flex items-center gap-1.5 text-sm text-content-secondary hover:text-content"
        >
          <ArrowLeft size={14} aria-hidden="true" />
          All workflows
        </Link>

        <div className="flex items-center gap-2">
          <Badge tone={data.status === 'active' ? 'positive' : 'neutral'}>
            {data.status_label}
          </Badge>

          <Link
            to={`/workflows/${uuid}/runs`}
            className="inline-flex items-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-sm text-content-secondary transition-colors hover:border-border-strong hover:text-content"
          >
            <History size={14} aria-hidden="true" />
            Activity
          </Link>

          <Button
            icon={<FlaskConical size={14} aria-hidden="true" />}
            onClick={() => setTesting(true)}
          >
            Test
          </Button>

          {data.status === 'active' ? (
            <Button
              icon={<Pause size={14} aria-hidden="true" />}
              loading={status.isPending}
              onClick={() => setStatus('pause')}
            >
              Pause
            </Button>
          ) : (
            <Button
              variant="primary"
              icon={<Play size={14} aria-hidden="true" />}
              loading={status.isPending}
              disabled={!data.can_activate}
              onClick={() => setStatus('activate')}
            >
              Activate
            </Button>
          )}
        </div>
      </div>

      {data.blockers.length > 0 && data.status !== 'active' && (
        <div className="rounded-lg border border-border bg-surface-sunken p-3">
          <p className="text-sm font-medium text-content">Not ready to activate</p>
          <ul className="mt-1.5 space-y-1">
            {data.blockers.map((blocker, index) => (
              <li
                key={`${blocker.node ?? 'workflow'}-${index}`}
                className="text-xs leading-relaxed text-content-secondary"
              >
                {blocker.message}
              </li>
            ))}
          </ul>
        </div>
      )}

      <TriggerSettings
        workflow={data}
        vocabulary={words}
        onChange={(changes) =>
          update.mutate(changes, {
            onError: (error) => toast.error('That did not save', error.message),
          })
        }
      />

      <Card title="Steps">
        <Canvas
          graph={data.graph}
          vocabulary={words}
          blockers={data.blockers}
          selected={selected}
          triggerLabel={data.trigger_label}
          onSelect={setSelected}
          onAdd={addStep}
          onRemove={deleteStep}
        />

        <p className="mt-4 border-t border-border pt-3 text-xs leading-relaxed text-content-tertiary">
          Steps run top to bottom. A workflow can hold {words.max_nodes} steps,
          and it cannot loop back on itself — a workflow that could would keep
          going for ever.
        </p>
      </Card>

      <NodeInspector
        graph={data.graph}
        vocabulary={words}
        nodeId={selected}
        subject={subject}
        onClose={() => setSelected(null)}
        onChange={(patch) => {
          if (selected !== null) {
            writeGraph(updateConfig(data.graph, selected, patch));
          }
        }}
      />

      <TestPanel
        uuid={uuid}
        open={testing}
        onClose={() => setTesting(false)}
        graph={data.graph}
        vocabulary={words}
      />
    </div>
  );
}
