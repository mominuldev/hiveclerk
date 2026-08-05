import { useState } from 'react';
import { ArrowLeft, ArrowRight, Plus, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Field';
import { Modal } from '@/components/ui/Modal';
import { Skeleton } from '@/components/ui/Skeleton';
import { toast } from '@/components/ui/Toast';
import {
  useCreateStage,
  useDeleteStage,
  useReorderStages,
  useStages,
  type LeadStage,
} from '@/api/queries/useLeads';

/**
 * The columns of the board (FR-LED-05).
 *
 * Reordering is two arrow buttons rather than a drag. The board itself is
 * a pointer surface and it should be; a settings list that could only be
 * reordered by dragging would put a configuration change out of reach of
 * a keyboard, and there are five items in it.
 */
export function StagesEditor() {
  const stages = useStages();
  const create = useCreateStage();
  const remove = useDeleteStage();
  const reorder = useReorderStages();

  const [name, setName] = useState('');
  const [deleting, setDeleting] = useState<LeadStage | null>(null);
  const [moveTo, setMoveTo] = useState<string>('');

  const list = stages.data?.stages ?? [];
  const counts = stages.data?.counts ?? {};

  const swap = (index: number, direction: -1 | 1): void => {
    const next = [...list];
    const target = index + direction;

    if (target < 0 || target >= next.length) {
      return;
    }

    const moved = next[index];
    const displaced = next[target];

    if (!moved || !displaced) {
      return;
    }

    next[index] = displaced;
    next[target] = moved;

    reorder.mutate(
      next.map((stage) => stage.id),
      {
        onError: (error) =>
          toast.error('The order did not save', error.message),
      }
    );
  };

  return (
    <Card
      title="Pipeline stages"
      actions={
        <div className="flex items-center gap-2">
          <Input
            aria-label="New stage name"
            placeholder="Stage name"
            value={name}
            onChange={(event) => setName(event.target.value)}
            className="h-9 w-44"
          />
          <Button
            icon={<Plus size={15} />}
            disabled={name.trim() === ''}
            loading={create.isPending}
            onClick={() =>
              create.mutate(
                { name: name.trim() },
                {
                  onSuccess: () => setName(''),
                  onError: (error) =>
                    toast.error('That stage was not added', error.message),
                }
              )
            }
          >
            Add
          </Button>
        </div>
      }
    >
      {stages.isPending ? (
        <Skeleton className="h-32 w-full" />
      ) : (
        <ul className="divide-y divide-border">
          {list.map((stage, index) => (
            <li
              key={stage.id}
              className="flex items-center gap-3 py-2.5 first:pt-0"
            >
              <span className="flex-1 text-sm text-content">{stage.name}</span>

              <span className="tabular-nums text-xs text-content-tertiary">
                {counts[String(stage.id)] ?? 0}
              </span>

              {stage.is_won && (
                <span className="text-xs text-on-duty">Counts as won</span>
              )}
              {stage.is_lost && (
                <span className="text-xs text-content-tertiary">
                  Counts as lost
                </span>
              )}

              <Button
                size="sm"
                variant="ghost"
                aria-label={`Move ${stage.name} left`}
                disabled={index === 0}
                icon={<ArrowLeft size={14} />}
                onClick={() => swap(index, -1)}
              />
              <Button
                size="sm"
                variant="ghost"
                aria-label={`Move ${stage.name} right`}
                disabled={index === list.length - 1}
                icon={<ArrowRight size={14} />}
                onClick={() => swap(index, 1)}
              />
              <Button
                size="sm"
                variant="ghost"
                aria-label={`Delete ${stage.name}`}
                icon={<Trash2 size={14} />}
                onClick={() => {
                  setDeleting(stage);
                  setMoveTo('');
                }}
              />
            </li>
          ))}
        </ul>
      )}

      <Modal
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        title={`Delete ${deleting?.name ?? 'stage'}?`}
        description={
          /* Named, and countable. "This may affect some leads" is the
             sentence that makes people cancel and go and check. */
          deleting && (counts[String(deleting.id)] ?? 0) > 0
            ? `${counts[String(deleting.id)]} leads are in this stage. Choose where they go — they are never deleted with it.`
            : 'Nothing is in this stage.'
        }
        danger
        footer={
          <>
            <Button onClick={() => setDeleting(null)}>Cancel</Button>
            <Button
              variant="danger"
              loading={remove.isPending}
              onClick={() => {
                if (!deleting) {
                  return;
                }

                remove.mutate(
                  { id: deleting.id, moveTo: moveTo ? Number(moveTo) : null },
                  {
                    onSuccess: (result) => {
                      setDeleting(null);
                      toast.success(
                        'Stage deleted',
                        result.leads_moved > 0
                          ? `${result.leads_moved} leads moved.`
                          : undefined
                      );
                    },
                    onError: (error) =>
                      toast.error('That stage was not deleted', error.message),
                  }
                );
              }}
            >
              Delete
            </Button>
          </>
        }
      >
        {deleting && (counts[String(deleting.id)] ?? 0) > 0 && (
          <select
            aria-label="Move leads to"
            value={moveTo}
            onChange={(event) => setMoveTo(event.target.value)}
            className="h-9 w-full rounded-lg border border-border bg-surface px-3 text-sm text-content"
          >
            <option value="">Leave them unassigned</option>
            {list
              .filter((stage) => stage.id !== deleting.id)
              .map((stage) => (
                <option key={stage.id} value={stage.id}>
                  {stage.name}
                </option>
              ))}
          </select>
        )}
      </Modal>
    </Card>
  );
}
