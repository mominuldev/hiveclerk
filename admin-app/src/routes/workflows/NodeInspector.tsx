import { ENTRY } from './graph';
import { Badge } from '@/components/ui/Badge';
import { Drawer } from '@/components/ui/Drawer';
import { Field, Input, Select, Textarea } from '@/components/ui/Field';
import type {
  Vocabulary,
  WorkflowNode,
  WorkflowGraph,
} from '@/api/queries/useWorkflows';

interface NodeInspectorProps {
  graph: WorkflowGraph;
  vocabulary: Vocabulary;
  nodeId: string | null;
  subject: 'lead' | 'conversation';
  onClose: () => void;
  onChange: (patch: Record<string, unknown>) => void;
}

/**
 * The configuration panel for one step (FR-WFL-03).
 *
 * A drawer rather than a modal, and rather than fields on the card. The
 * canvas behind it is the thing being configured, and an operator setting
 * a condition needs to see where it sits in the flow — which is exactly
 * what a centred modal takes away. Inline fields would work for one node
 * type and turn a twelve-step workflow into an unreadable wall.
 *
 * Every control writes on change or on blur, and there is no save button.
 * A builder with unsaved state is a builder that loses work to a browser
 * tab, and the graph is small enough that a write per edit costs nothing.
 */
export function NodeInspector({
  graph,
  vocabulary,
  nodeId,
  subject,
  onClose,
  onChange,
}: NodeInspectorProps) {
  const node = nodeId === null ? undefined : graph[nodeId];

  return (
    <Drawer
      open={node !== undefined && nodeId !== ENTRY}
      onClose={onClose}
      title={(node && TITLES[node.type]) || 'Step'}
      {...(node && SUBTITLES[node.type] ? { subtitle: SUBTITLES[node.type] } : {})}
    >
      {node && (
        <div className="space-y-5 p-5">
          {node.type === 'delay' && (
            <DelayFields node={node} onChange={onChange} />
          )}
          {node.type === 'condition' && (
            <ConditionFields
              node={node}
              vocabulary={vocabulary}
              onChange={onChange}
            />
          )}
          {node.type === 'action' && (
            <ActionFields
              node={node}
              vocabulary={vocabulary}
              subject={subject}
              onChange={onChange}
            />
          )}
        </div>
      )}
    </Drawer>
  );
}

const TITLES: Record<string, string> = {
  delay: 'Wait',
  condition: 'Check something',
  action: 'Do something',
  trigger: 'Trigger',
};

const SUBTITLES: Record<string, string> = {
  delay: 'Everyone who reaches this step pauses here, then carries on.',
  condition: 'Everyone is asked this question, and the answer picks a branch.',
  action: 'This is the part that actually changes something.',
  trigger: '',
};

function DelayFields({
  node,
  onChange,
}: {
  node: WorkflowNode;
  onChange: (patch: Record<string, unknown>) => void;
}) {
  const minutes = Number(node.config.minutes ?? 0);
  const unit = minutes >= 1440 ? 1440 : minutes >= 60 ? 60 : 1;
  const amount = Math.max(1, Math.round(minutes / unit));

  return (
    <div className="grid grid-cols-2 gap-3">
      <Field label="Wait for">
        {({ id }) => (
          <Input
            id={id}
            type="number"
            min={1}
            max={60}
            value={amount}
            onChange={(event) =>
              onChange({
                minutes: Math.max(1, Number(event.target.value)) * unit,
              })
            }
          />
        )}
      </Field>

      <Field label="Unit">
        {({ id }) => (
          <Select
            id={id}
            value={String(unit)}
            onChange={(event) =>
              onChange({ minutes: amount * Number(event.target.value) })
            }
          >
            <option value="1">Minutes</option>
            <option value="60">Hours</option>
            <option value="1440">Days</option>
          </Select>
        )}
      </Field>
    </div>
  );
}

function ConditionFields({
  node,
  vocabulary,
  onChange,
}: {
  node: WorkflowNode;
  vocabulary: Vocabulary;
  onChange: (patch: Record<string, unknown>) => void;
}) {
  const field = vocabulary.fields.find((f) => f.value === node.config.field);
  const operator = vocabulary.operators.find(
    (o) => o.value === node.config.operator
  );

  const operators = vocabulary.operators.filter((candidate) =>
    field?.numeric ? true : !candidate.numeric
  );

  return (
    <>
      <Field label="Look at">
        {({ id }) => (
          <Select
            id={id}
            value={String(node.config.field ?? '')}
            onChange={(event) => onChange({ field: event.target.value })}
          >
            <option value="">Choose a field</option>
            {vocabulary.fields.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </Select>
        )}
      </Field>

      {field?.needs_key === true && (
        <Field
          label="Question key"
          hint="The key of the qualification question, as it appears on the clerk that asks it."
        >
          {({ id, describedBy }) => (
            <Input
              id={id}
              aria-describedby={describedBy}
              mono
              defaultValue={String(node.config.key ?? '')}
              placeholder="budget"
              onBlur={(event) => onChange({ key: event.target.value })}
            />
          )}
        </Field>
      )}

      <Field label="Compare">
        {({ id }) => (
          <Select
            id={id}
            value={String(node.config.operator ?? '')}
            onChange={(event) => onChange({ operator: event.target.value })}
          >
            <option value="">Choose a comparison</option>
            {operators.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </Select>
        )}
      </Field>

      {operator?.needs_value !== false && (
        <Field label="To">
          {({ id }) => (
            <Input
              id={id}
              type={field?.numeric ? 'number' : 'text'}
              defaultValue={String(node.config.value ?? '')}
              onBlur={(event) => onChange({ value: event.target.value })}
            />
          )}
        </Field>
      )}

      <p className="border-t border-border pt-3 text-xs leading-relaxed text-content-tertiary">
        Conditions are read at the moment the step runs, not when the workflow
        started. After a wait, this asks about today.
      </p>
    </>
  );
}

function ActionFields({
  node,
  vocabulary,
  subject,
  onChange,
}: {
  node: WorkflowNode;
  vocabulary: Vocabulary;
  subject: 'lead' | 'conversation';
  onChange: (patch: Record<string, unknown>) => void;
}) {
  const action = vocabulary.actions.find((a) => a.value === node.config.action);

  return (
    <>
      <Field label="Action">
        {({ id }) => (
          <Select
            id={id}
            value={String(node.config.action ?? '')}
            onChange={(event) => onChange({ action: event.target.value })}
          >
            <option value="">Choose an action</option>
            {vocabulary.actions.map((option) => (
              <option
                key={option.value}
                value={option.value}
                disabled={!option.available || !option.subjects.includes(subject)}
              >
                {option.label}
                {!option.available ? ' — not installed' : ''}
                {option.available && !option.subjects.includes(subject)
                  ? ' — needs a lead'
                  : ''}
              </option>
            ))}
          </Select>
        )}
      </Field>

      {action && (
        <p className="flex items-start gap-2 text-xs leading-relaxed text-content-secondary">
          {action.outbound && <Badge tone="warning">Leaves the site</Badge>}
          {action.description}
        </p>
      )}

      {action?.value === 'enrol_sequence' && (
        <Field
          label="Sequence"
          hint="Anyone unsubscribed or already in it is skipped — the email module decides that, not this step."
        >
          {({ id, describedBy }) => (
            <Select
              id={id}
              aria-describedby={describedBy}
              value={String(node.config.sequence ?? '')}
              onChange={(event) => onChange({ sequence: event.target.value })}
            >
              <option value="">Choose a sequence</option>
              {vocabulary.sequences.map((sequence) => (
                <option key={sequence.uuid} value={sequence.uuid}>
                  {sequence.name}
                  {sequence.active ? '' : ' (not active)'}
                </option>
              ))}
            </Select>
          )}
        </Field>
      )}

      {action?.value === 'set_stage' && (
        <Field label="Move to">
          {({ id }) => (
            <Select
              id={id}
              value={String(node.config.stage_id ?? '')}
              onChange={(event) =>
                onChange({ stage_id: Number(event.target.value) })
              }
            >
              <option value="">Choose a stage</option>
              {vocabulary.stages.map((stage) => (
                <option key={stage.id} value={stage.id}>
                  {stage.name}
                </option>
              ))}
            </Select>
          )}
        </Field>
      )}

      {action?.value === 'adjust_score' && (
        <>
          <Field
            label="Points"
            hint="Negative numbers subtract. A single step can move a score by at most 50."
          >
            {({ id, describedBy }) => (
              <Input
                id={id}
                aria-describedby={describedBy}
                type="number"
                min={-50}
                max={50}
                className="max-w-28"
                defaultValue={String(node.config.points ?? 0)}
                onBlur={(event) => onChange({ points: Number(event.target.value) })}
              />
            )}
          </Field>

          <Field
            label="Reason"
            hint="Shown on the score breakdown, where somebody will read it months from now."
          >
            {({ id, describedBy }) => (
              <Input
                id={id}
                aria-describedby={describedBy}
                defaultValue={String(node.config.reason ?? '')}
                placeholder="Asked about pricing"
                onBlur={(event) => onChange({ reason: event.target.value })}
              />
            )}
          </Field>
        </>
      )}

      {action?.value === 'add_note' && (
        <Field label="Note" hint="Placeholders are filled in when the step runs.">
          {({ id, describedBy }) => (
            <Textarea
              id={id}
              aria-describedby={describedBy}
              rows={4}
              defaultValue={String(node.config.note ?? '')}
              onBlur={(event) => onChange({ note: event.target.value })}
            />
          )}
        </Field>
      )}

      {action?.value === 'webhook' && (
        <Field
          label="Event name"
          hint="Your endpoints subscribe to this name. It is sent prefixed with workflow. — a workflow cannot impersonate one of Hiveclerk's own events."
        >
          {({ id, describedBy }) => (
            <Input
              id={id}
              aria-describedby={describedBy}
              mono
              defaultValue={String(node.config.event ?? '')}
              placeholder="qualified_lead"
              onBlur={(event) => onChange({ event: event.target.value })}
            />
          )}
        </Field>
      )}

      {action?.value === 'notify_admin' && (
        <>
          <Field
            label="To"
            hint="Comma separated, up to five. Left empty, this goes to the site's admin address."
          >
            {({ id, describedBy }) => (
              <Input
                id={id}
                aria-describedby={describedBy}
                type="text"
                defaultValue={String(node.config.recipients ?? '')}
                placeholder="sales@example.com"
                onBlur={(event) => onChange({ recipients: event.target.value })}
              />
            )}
          </Field>

          <Field label="Subject">
            {({ id }) => (
              <Input
                id={id}
                defaultValue={String(node.config.subject ?? '')}
                onBlur={(event) => onChange({ subject: event.target.value })}
              />
            )}
          </Field>

          <Field label="Message">
            {({ id }) => (
              <Textarea
                id={id}
                rows={5}
                defaultValue={String(node.config.message ?? '')}
                onBlur={(event) => onChange({ message: event.target.value })}
              />
            )}
          </Field>
        </>
      )}

      {(action?.value === 'add_note' || action?.value === 'notify_admin') && (
        <div className="border-t border-border pt-3">
          <p className="text-xs font-medium text-content">Placeholders</p>
          <ul className="mt-1.5 space-y-1">
            {vocabulary.tags.map((tag) => (
              <li key={tag.key} className="text-xs text-content-tertiary">
                <code className="font-mono text-[11px] text-content-secondary">
                  {tag.tag}
                </code>{' '}
                — {tag.description}
              </li>
            ))}
          </ul>
        </div>
      )}
    </>
  );
}
