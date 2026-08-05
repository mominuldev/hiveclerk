import { useState } from 'react';
import { Download } from 'lucide-react';
import { withFilter } from './band';
import { LeadCard } from './LeadCard';
import { LeadDrawer } from './LeadDrawer';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { Filters } from '@/components/ui/Filters';
import { Skeleton } from '@/components/ui/Skeleton';
import { toast } from '@/components/ui/Toast';
import { cn } from '@/lib/cn';
import {
  useExportLeads,
  useLeads,
  useMoveLead,
  useScoringPolicy,
  type LeadFilters,
  type LeadStage,
} from '@/api/queries/useLeads';

/**
 * The pipeline board (FR-LED-05, D11 §6.1).
 *
 * ## Drag with a pointer, move with a keyboard
 *
 * Cards drag between columns with the native HTML5 API, which is not
 * reachable from a keyboard and cannot be made so. The same move is
 * therefore available as a Stage dropdown in the lead's own panel, which
 * is where the wireframe puts it anyway — so the operation is reachable
 * by every input, even though the gesture is not.
 *
 * ## The move is optimistic and the failure is loud
 *
 * A card that snaps back on its own after a failed request looks like a
 * bug in the drag. It snaps back *and* says why.
 */
export function Pipeline() {
  const [filters, setFilters] = useState<LeadFilters>({});
  const [open, setOpen] = useState<string | null>(null);
  const [dragging, setDragging] = useState<string | null>(null);
  const [over, setOver] = useState<number | null>(null);

  const leads = useLeads(filters);
  const policy = useScoringPolicy();
  const move = useMoveLead();
  const exporter = useExportLeads();

  const ceiling = policy.data?.ceiling ?? 100;

  const drop = (stage: LeadStage): void => {
    const uuid = dragging;

    setDragging(null);
    setOver(null);

    if (!uuid) {
      return;
    }

    move.mutate(
      { uuid, stageId: stage.id },
      {
        onError: (error) =>
          toast.error('That lead did not move', error.message),
      }
    );
  };

  const download = (): void => {
    exporter.mutate(filters, {
      onSuccess: (file) => {
        const url = URL.createObjectURL(
          new Blob([file.csv], { type: 'text/csv;charset=utf-8' })
        );
        const link = document.createElement('a');

        link.href = url;
        link.download = file.filename;
        link.click();

        URL.revokeObjectURL(url);

        if (file.truncated) {
          // Said out loud, never silently. A spreadsheet that stops at
          // five thousand rows without saying so is worse than an error.
          toast.info(
            `Exported the first ${file.rows} of ${file.total} leads`,
            'Narrow the filters by date or stage to export the rest.'
          );
        } else {
          toast.success(`Exported ${file.rows} leads`);
        }
      },
      onError: (error) => toast.error('The export failed', error.message),
    });
  };

  if (leads.isError) {
    return <ErrorNotice error={leads.error} onRetry={() => void leads.refetch()} />;
  }

  const stages = leads.data?.stages ?? [];

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <Filters
          search={{
            value: filters.search ?? '',
            onChange: (search) =>
              setFilters((current) => withFilter(current, 'search', search)),
            placeholder: 'Search name, email or company',
          }}
          selects={[
            {
              key: 'band',
              label: 'Band',
              value: filters.band ?? '',
              onChange: (band) =>
                setFilters((current) => withFilter(current, 'band', band)),
              options: [
                { value: '', label: 'Any band' },
                { value: 'qualified', label: 'Qualified' },
                { value: 'hot', label: 'Hot' },
                { value: 'warm', label: 'Warm' },
                { value: 'cold', label: 'Cold' },
              ],
            },
          ]}
          onClear={() => setFilters({})}
        />

        <Button
          icon={<Download size={15} />}
          onClick={download}
          loading={exporter.isPending}
        >
          Export
        </Button>
      </div>

      {leads.isPending ? (
        <div className="flex gap-3 overflow-x-auto pb-2">
          {[0, 1, 2, 3, 4].map((column) => (
            <Skeleton key={column} className="h-64 w-[260px] shrink-0" />
          ))}
        </div>
      ) : stages.length === 0 ? (
        <EmptyState
          title="There is no pipeline yet"
          description="Add the stages your team actually uses on the Scoring tab, and leads will start landing in the first one."
        />
      ) : (
        <div className="flex gap-3 overflow-x-auto pb-2">
          {stages.map((stage) => {
            const cards = (leads.data?.leads ?? []).filter(
              (lead) => lead.stage_id === stage.id
            );
            const total = leads.data?.counts?.[String(stage.id)] ?? cards.length;

            return (
              <section
                key={stage.id}
                aria-label={stage.name}
                onDragOver={(event) => {
                  event.preventDefault();
                  setOver(stage.id);
                }}
                onDragLeave={() => setOver((current) => (current === stage.id ? null : current))}
                onDrop={(event) => {
                  event.preventDefault();
                  drop(stage);
                }}
                className={cn(
                  'flex w-[260px] shrink-0 flex-col rounded-xl border bg-surface-sunken/60 p-2.5',
                  'transition-colors duration-[var(--hvc-duration-fast)]',
                  over === stage.id
                    ? 'border-accent bg-accent-subtle'
                    : 'border-border'
                )}
              >
                <header className="flex items-baseline justify-between px-1 pb-2">
                  <h2 className="text-xs font-semibold uppercase tracking-wide text-content-secondary">
                    {stage.name}
                  </h2>
                  {/* The count for the whole stage, not for this page. */}
                  <span className="tabular-nums text-xs text-content-tertiary">
                    {total}
                  </span>
                </header>

                <div className="flex flex-col gap-2">
                  {cards.map((lead) => (
                    <LeadCard
                      key={lead.uuid}
                      lead={lead}
                      ceiling={ceiling}
                      onOpen={setOpen}
                      onDragStart={setDragging}
                      dragging={dragging === lead.uuid}
                    />
                  ))}

                  {cards.length === 0 && (
                    <p className="px-1 py-6 text-center text-xs text-content-tertiary">
                      Nothing here yet
                    </p>
                  )}
                </div>
              </section>
            );
          })}
        </div>
      )}

      <LeadDrawer uuid={open} onClose={() => setOpen(null)} />
    </div>
  );
}
