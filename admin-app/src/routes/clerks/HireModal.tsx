import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Button } from '@/components/ui/Button';
import { Field, Input } from '@/components/ui/Field';
import { Modal } from '@/components/ui/Modal';
import { Skeleton } from '@/components/ui/Skeleton';
import { toast } from '@/components/ui/Toast';
import { cn } from '@/lib/cn';
import { useCreateAgent, usePresets } from '@/api/queries/useAgents';

interface HireModalProps {
  open: boolean;
  onClose: () => void;
}

/**
 * Hiring: a name and a role, and nothing else.
 *
 * The role is the only decision that matters here, because it arrives
 * with its instructions already written — the difference between a clerk
 * that behaves like staff and one that behaves like a search box. Every
 * other field belongs in the editor, where it can be tested.
 */
export function HireModal({ open, onClose }: HireModalProps) {
  const navigate = useNavigate();
  const presets = usePresets();
  const create = useCreateAgent();

  const [name, setName] = useState('');
  const [role, setRole] = useState('support');

  const licence = presets.data?.licence;

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Hire a clerk"
      description="Pick the job. The instructions come written; you can rewrite every word of them afterwards."
      size="lg"
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button
            variant="primary"
            loading={create.isPending}
            onClick={() => {
              create.mutate(
                { name: name.trim(), role_preset: role },
                {
                  onSuccess: (agent) => {
                    onClose();
                    setName('');
                    toast.success(`${agent.name} hired. Draft — not answering yet.`);
                    void navigate(`/clerks/${agent.uuid}`);
                  },
                  onError: (error) => toast.error('Could not hire', error.message),
                }
              );
            }}
          >
            Hire and configure
          </Button>
        </>
      }
    >
      <div className="space-y-5">
        <Field
          label="Name"
          hint="Visitors see this above every reply. A person's name reads better than a job title."
        >
          {({ id, describedBy }) => (
            <Input
              id={id}
              value={name}
              onChange={(event) => setName(event.target.value)}
              placeholder="Ada"
              aria-describedby={describedBy}
            />
          )}
        </Field>

        <div>
          <p className="mb-2 text-sm font-medium text-content">Role</p>

          {presets.isPending ? (
            <div className="grid gap-2 sm:grid-cols-2">
              {[0, 1, 2, 3].map((i) => (
                <Skeleton key={i} className="h-20 w-full rounded-xl" />
              ))}
            </div>
          ) : (
            <div className="grid gap-2 sm:grid-cols-2" role="radiogroup" aria-label="Role">
              {(presets.data?.presets ?? []).map((preset) => {
                const selected = preset.key === role;

                return (
                  <button
                    key={preset.key}
                    type="button"
                    role="radio"
                    aria-checked={selected}
                    onClick={() => setRole(preset.key)}
                    className={cn(
                      'rounded-xl border p-3 text-left transition-colors',
                      'focus:outline-none focus-visible:border-accent',
                      selected
                        ? 'border-accent bg-accent-subtle'
                        : 'border-border bg-surface hover:border-border-strong'
                    )}
                  >
                    <span className="block text-sm font-medium text-content">
                      {preset.label}
                    </span>
                    <span className="mt-1 block text-xs leading-relaxed text-content-secondary">
                      {preset.summary}
                    </span>
                  </button>
                );
              })}
            </div>
          )}
        </div>

        {licence?.limit !== null && licence !== undefined && (
          <p className="text-xs leading-relaxed text-content-tertiary">
            {/* Said before the work, not after it. Finding out about a cap
                when you press Publish is finding out too late. */}
            Your licence keeps {licence.limit}{' '}
            {licence.limit === 1 ? 'clerk' : 'clerks'} on duty at a time —{' '}
            {licence.published} in use. You can hire and configure as many as you
            like; only publishing is capped.
          </p>
        )}
      </div>
    </Modal>
  );
}
