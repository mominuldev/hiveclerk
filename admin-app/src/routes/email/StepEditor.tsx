import { useEffect, useState } from 'react';
import { Eye, Sparkles, Trash2 } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Field, Input, Select, Textarea } from '@/components/ui/Field';
import { Modal } from '@/components/ui/Modal';
import { toast } from '@/components/ui/Toast';
import { formatDelay } from '@/lib/format';
import {
  useApproveStep,
  useDeleteStep,
  useGenerateCopy,
  usePreviewStep,
  useUpdateStep,
  type MergeTag,
  type SequenceStep,
} from '@/api/queries/useEmail';

interface StepEditorProps {
  sequenceUuid: string;
  step: SequenceStep;
  index: number;
  tags: MergeTag[];
}

const DELAYS = [
  { value: 0, label: 'Immediately' },
  { value: 60, label: 'After 1 hour' },
  { value: 240, label: 'After 4 hours' },
  { value: 1440, label: 'After 1 day' },
  { value: 2880, label: 'After 2 days' },
  { value: 4320, label: 'After 3 days' },
  { value: 10080, label: 'After 1 week' },
] as const;

/**
 * One email in the builder.
 *
 * ## The approval gate is visible, not implied
 *
 * An AI-drafted step that nobody has approved carries a banner saying so
 * and the sequence refuses to activate while it stands. That is the same
 * rule the server enforces — the UI is not the gate, it is the
 * explanation of the gate.
 *
 * Editing approved copy withdraws the approval on the server, and the
 * badge disappears on the next save. Approval attaches to words, not to a
 * row.
 */
export function StepEditor({ sequenceUuid, step, index, tags }: StepEditorProps) {
  const update = useUpdateStep(sequenceUuid);
  const remove = useDeleteStep(sequenceUuid);
  const approve = useApproveStep(sequenceUuid);
  const generate = useGenerateCopy();
  const preview = usePreviewStep();

  const [draft, setDraft] = useState(step);
  const [asking, setAsking] = useState(false);
  const [goal, setGoal] = useState('');
  const [previewing, setPreviewing] = useState(false);

  useEffect(() => {
    setDraft(step);
  }, [step]);

  const dirty =
    draft.subject !== step.subject ||
    draft.body_html !== step.body_html ||
    draft.delay_minutes !== step.delay_minutes;

  const save = (changes: Partial<SequenceStep> = {}): void => {
    if (draft.id === null) {
      return;
    }

    update.mutate(
      {
        id: draft.id,
        changes: {
          subject: draft.subject,
          body_html: draft.body_html,
          delay_minutes: draft.delay_minutes,
          ...changes,
        },
      },
      {
        onSuccess: () => toast.success('Saved'),
        onError: (error) => toast.error('That did not save', error.message),
      }
    );
  };

  const runGenerate = (): void => {
    if (draft.id === null) {
      return;
    }

    generate.mutate(
      { id: draft.id, goal },
      {
        onSuccess: (result) => {
          setDraft({
            ...draft,
            subject: result.subject,
            body_html: result.body_html,
            body_text: result.body_text,
            ai_generated: true,
          });
          setAsking(false);
          toast.success(
            'Draft ready',
            'Read it, change what you want, then save and approve it.'
          );
        },
        onError: (error) => toast.error('That did not generate', error.message),
      }
    );
  };

  const runPreview = (): void => {
    if (draft.id === null) {
      return;
    }

    preview.mutate(draft.id, {
      onSuccess: () => setPreviewing(true),
      onError: (error) => toast.error('Preview failed', error.message),
    });
  };

  const needsApproval = step.ai_generated && step.approved_at === null;

  return (
    <Card
      eyebrow={`Email ${index + 1}`}
      title={step.subject || 'Untitled email'}
      actions={
        <div className="flex items-center gap-2">
          {step.ai_generated && step.approved_at !== null && (
            <Badge tone="positive">Approved</Badge>
          )}
          <Button
            size="sm"
            icon={<Eye size={14} aria-hidden="true" />}
            loading={preview.isPending}
            onClick={runPreview}
          >
            Preview
          </Button>
          <Button
            size="sm"
            variant="ghost"
            icon={<Trash2 size={14} aria-hidden="true" />}
            loading={remove.isPending}
            onClick={() => {
              if (draft.id !== null) {
                remove.mutate(draft.id, {
                  onSuccess: () => toast.success('Email removed'),
                });
              }
            }}
          >
            <span className="sr-only">Remove this email</span>
          </Button>
        </div>
      }
    >
      {needsApproval && (
        <div className="mb-4 rounded-lg border border-warning/30 bg-warning/10 p-3">
          <p className="text-sm font-medium text-content">
            A model wrote this. Nobody has read it yet.
          </p>
          <p className="mt-1 text-xs leading-relaxed text-content-secondary">
            This sequence will not send until somebody approves it, and it will
            not activate while this email is here unapproved.
          </p>
          <Button
            size="sm"
            variant="primary"
            className="mt-3"
            loading={approve.isPending}
            onClick={() => {
              if (draft.id !== null) {
                approve.mutate(draft.id, {
                  onSuccess: () => toast.success('Approved'),
                  onError: (error) =>
                    toast.error('That did not approve', error.message),
                });
              }
            }}
          >
            I have read it — approve
          </Button>
        </div>
      )}

      <div className="space-y-4">
        <Field
          label="Send"
          hint={
            index === 0
              ? 'Measured from the moment the lead is enrolled.'
              : `Measured from the previous email, not from enrolment. Currently ${formatDelay(draft.delay_minutes)} after email ${index}.`
          }
        >
          {({ id, describedBy }) => (
            <Select
              id={id}
              aria-describedby={describedBy}
              className="max-w-56"
              value={
                DELAYS.some((delay) => delay.value === draft.delay_minutes)
                  ? String(draft.delay_minutes)
                  : ''
              }
              onChange={(event) =>
                setDraft({ ...draft, delay_minutes: Number(event.target.value) })
              }
            >
              {!DELAYS.some((delay) => delay.value === draft.delay_minutes) && (
                <option value="">{formatDelay(draft.delay_minutes)}</option>
              )}
              {DELAYS.map((delay) => (
                <option key={delay.value} value={delay.value}>
                  {delay.label}
                </option>
              ))}
            </Select>
          )}
        </Field>

        <Field label="Subject">
          {({ id }) => (
            <Input
              id={id}
              value={draft.subject}
              placeholder="Following up on your question"
              onChange={(event) =>
                setDraft({ ...draft, subject: event.target.value })
              }
            />
          )}
        </Field>

        <Field
          label="Body"
          hint="Simple HTML. An unsubscribe link is added automatically — you do not need to write one."
          aside={
            <Button
              variant="link"
              onClick={() => setAsking(true)}
            >
              <Sparkles size={13} aria-hidden="true" />
              Draft with AI
            </Button>
          }
        >
          {({ id, describedBy }) => (
            <Textarea
              id={id}
              aria-describedby={describedBy}
              rows={10}
              className="font-mono text-[13px]"
              value={draft.body_html}
              onChange={(event) =>
                setDraft({ ...draft, body_html: event.target.value })
              }
            />
          )}
        </Field>

        <div className="flex flex-wrap gap-1.5">
          {tags.map((tag) => (
            <button
              key={tag.key}
              type="button"
              title={tag.description}
              className="rounded-full border border-border bg-surface-sunken px-2 py-0.5 font-mono text-[11px] text-content-secondary transition-colors hover:border-border-strong hover:text-content"
              onClick={() =>
                setDraft({ ...draft, body_html: draft.body_html + tag.tag })
              }
            >
              {tag.tag}
            </button>
          ))}
        </div>

        <div className="flex items-center gap-2">
          <Button
            variant="primary"
            loading={update.isPending}
            disabled={!dirty}
            onClick={() => save()}
          >
            Save
          </Button>
          {dirty && step.ai_generated && step.approved_at !== null && (
            <span className="text-xs text-content-tertiary">
              Saving changes will need a fresh approval.
            </span>
          )}
        </div>
      </div>

      <Modal
        open={asking}
        onClose={() => setAsking(false)}
        title="Draft this email"
        description="Say what the email should achieve. A model writes it, you read it, and nothing sends until you approve it."
        footer={
          <>
            <Button onClick={() => setAsking(false)}>Cancel</Button>
            <Button
              variant="primary"
              loading={generate.isPending}
              disabled={goal.trim() === ''}
              onClick={runGenerate}
            >
              Draft it
            </Button>
          </>
        }
      >
        <Field
          label="What should this email do?"
          hint="Costs one model call. It writes with merge tags rather than a real name, so the same email works for everybody the sequence enrols."
        >
          {({ id, describedBy }) => (
            <Textarea
              id={id}
              aria-describedby={describedBy}
              rows={4}
              value={goal}
              placeholder="Check whether they still need help choosing a plan, and offer a 15-minute call."
              onChange={(event) => setGoal(event.target.value)}
            />
          )}
        </Field>
      </Modal>

      <Modal
        open={previewing}
        onClose={() => setPreviewing(false)}
        title="Preview"
        {...(preview.data?.sample
          ? {
              description:
                'Rendered against a made-up lead. No real person’s details are shown here.',
            }
          : {})}
        size="lg"
        footer={<Button onClick={() => setPreviewing(false)}>Close</Button>}
      >
        <div className="space-y-3">
          <div className="rounded-lg border border-border bg-surface-sunken p-3">
            <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-content-tertiary">
              Subject
            </p>
            <p className="mt-1 text-sm text-content">{preview.data?.subject}</p>
          </div>

          {/* Rendered as text, never as markup. The body has been through
              wp_kses on the server, and injecting server HTML into this
              admin page would still be the wrong instinct to build. */}
          <pre className="max-h-96 overflow-auto whitespace-pre-wrap rounded-lg border border-border bg-surface-sunken p-3 font-mono text-[12px] leading-relaxed text-content-secondary">
            {preview.data?.text}
          </pre>
        </div>
      </Modal>
    </Card>
  );
}
