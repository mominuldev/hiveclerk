import { useState } from 'react';
import { Check, Clock, GitBranch, Minus, X } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Field, Select } from '@/components/ui/Field';
import { Modal } from '@/components/ui/Modal';
import { useLeads } from '@/api/queries/useLeads';
import {
  useTestWorkflow,
  type TraceStep,
  type Vocabulary,
  type WorkflowGraph,
} from '@/api/queries/useWorkflows';

interface TestPanelProps {
  uuid: string;
  open: boolean;
  onClose: () => void;
  graph: WorkflowGraph;
  vocabulary: Vocabulary;
}

/**
 * A dry run against a real lead (FR-WFL-06).
 *
 * ## It says what it did not do, every time
 *
 * The line at the bottom — "nothing above was actually done" — is not
 * reassurance, it is the contract. An operator who is not certain a test
 * is safe will not press it, and a builder nobody tests is a builder
 * whose first real run is the test.
 *
 * ## Conditions are evaluated for real
 *
 * Which is the only way this answers the question people actually have:
 * not "what would this do to somebody" but "what would this do to *them*".
 * That is why the picker holds real leads rather than a made-up one.
 */
export function TestPanel({
  uuid,
  open,
  onClose,
  graph,
  vocabulary,
}: TestPanelProps) {
  const leads = useLeads({ per_page: 25 });
  const test = useTestWorkflow(uuid);

  const [lead, setLead] = useState('');

  const run = (): void => {
    test.mutate(lead === '' ? {} : { lead });
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Test this workflow"
      description="Conditions are checked against the lead you pick. Nothing is sent, moved or pushed anywhere."
      size="lg"
      footer={
        <>
          <Button onClick={onClose}>Close</Button>
          <Button variant="primary" loading={test.isPending} onClick={run}>
            Run the test
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <Field
          label="Against"
          hint="Pick somebody who should match, and then somebody who should not. The branches are the part worth checking."
        >
          {({ id, describedBy }) => (
            <Select
              id={id}
              aria-describedby={describedBy}
              value={lead}
              onChange={(event) => setLead(event.target.value)}
            >
              <option value="">Nobody — just show me the shape</option>
              {(leads.data?.leads ?? []).map((candidate) => (
                <option key={candidate.uuid} value={candidate.uuid}>
                  {candidate.name} · {candidate.score}
                </option>
              ))}
            </Select>
          )}
        </Field>

        {test.isError && (
          <p className="text-sm text-danger" role="alert">
            {test.error.message}
          </p>
        )}

        {test.data && (
          <ol className="space-y-1.5 border-t border-border pt-4">
            {test.data.trace.map((step, index) => (
              <TraceRow
                key={`${step.node}-${index}`}
                step={step}
                graph={graph}
                vocabulary={vocabulary}
              />
            ))}
          </ol>
        )}

        {!test.data && !test.isPending && (
          <p className="text-xs leading-relaxed text-content-tertiary">
            {Object.keys(graph).length <= 1
              ? 'There is nothing to test yet — add a step first.'
              : 'The test walks the workflow the way a real run would, and stops at nothing.'}
          </p>
        )}
      </div>
    </Modal>
  );
}

const OUTCOME_ICONS: Record<string, typeof Check> = {
  matched: Check,
  unmatched: X,
  waited: Clock,
  skipped: Minus,
  finished: Check,
  failed: X,
};

function TraceRow({
  step,
  graph,
  vocabulary,
}: {
  step: TraceStep;
  graph: WorkflowGraph;
  vocabulary: Vocabulary;
}) {
  const Icon =
    step.type === 'condition' ? GitBranch : (OUTCOME_ICONS[step.outcome] ?? Minus);

  const node = graph[step.node];
  const action =
    node?.type === 'action'
      ? vocabulary.actions.find((a) => a.value === node.config.action)
      : undefined;

  return (
    <li className="flex items-start gap-2.5 text-sm">
      <span className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-surface-sunken text-content-tertiary">
        <Icon size={11} aria-hidden="true" />
      </span>

      <span className="min-w-0">
        <span className="text-content">{step.detail}</span>
        {action?.outbound === true && (
          <Badge tone="warning" className="ml-2">
            Would leave the site
          </Badge>
        )}
      </span>
    </li>
  );
}
