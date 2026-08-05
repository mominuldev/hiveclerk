import { BAND_FILL, BAND_TONE, scoreFraction } from './band';
import { Badge } from '@/components/ui/Badge';
import { cn } from '@/lib/cn';
import type { LeadSummary } from '@/api/queries/useLeads';

interface LeadCardProps {
  lead: LeadSummary;
  ceiling: number;
  onOpen: (uuid: string) => void;
  onDragStart: (uuid: string) => void;
  dragging: boolean;
}

/**
 * One card on the board.
 *
 * The whole card is the button that opens the lead, and the drag handle
 * is the card too. That double duty is why `draggable` sits on the button
 * rather than on a wrapper: a separate grip would be a second target in a
 * space that is already 240 pixels wide, and a drag that starts anywhere
 * is what people expect from a board.
 *
 * The meter has a number next to it, always. A bar on its own is a
 * comparison with nothing — 72 out of what?
 */
export function LeadCard({
  lead,
  ceiling,
  onOpen,
  onDragStart,
  dragging,
}: LeadCardProps) {
  const fraction = scoreFraction(lead.score, ceiling);

  return (
    <button
      type="button"
      draggable
      onDragStart={(event) => {
        event.dataTransfer.effectAllowed = 'move';
        // Set as text so a drop outside the board does something harmless
        // rather than nothing at all.
        event.dataTransfer.setData('text/plain', lead.uuid);
        onDragStart(lead.uuid);
      }}
      onClick={() => onOpen(lead.uuid)}
      className={cn(
        'w-full rounded-lg border border-border bg-surface p-3 text-left',
        'transition-colors duration-[var(--hvc-duration-fast)]',
        'hover:border-content-tertiary focus-visible:outline-2 focus-visible:outline-accent',
        dragging && 'opacity-40'
      )}
    >
      <div className="flex items-start justify-between gap-2">
        <span className="truncate text-sm font-medium text-content">
          {lead.name}
        </span>
        <Badge tone={BAND_TONE[lead.band]}>{lead.band_label}</Badge>
      </div>

      {lead.company && (
        <p className="mt-0.5 truncate text-xs text-content-secondary">
          {lead.company}
        </p>
      )}

      <div className="mt-2.5 flex items-center gap-2">
        <span
          className="h-1.5 flex-1 overflow-hidden rounded-full bg-surface-sunken"
          role="img"
          aria-label={`Score ${lead.score} of ${ceiling}`}
        >
          <span
            className={cn('block h-full rounded-full', BAND_FILL[lead.band])}
            style={{ width: `${Math.round(fraction * 100)}%` }}
          />
        </span>
        <span className="tabular-nums text-xs font-medium text-content-secondary">
          {lead.score}
        </span>
      </div>
    </button>
  );
}
