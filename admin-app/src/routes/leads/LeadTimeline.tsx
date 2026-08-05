import { formatTimestamp } from '@/lib/format';
import type { TimelineEntry } from '@/api/queries/useLeads';

/**
 * Everything that has happened to this person (FR-LED-06).
 *
 * Newest first, and it starts before they were a lead: the page views
 * they accumulated anonymously are stitched on once an address resolves
 * who they were. A timeline that began at "lead captured" would open at
 * the moment the interesting part ends.
 */
export function LeadTimeline({ entries }: { entries: TimelineEntry[] }) {
  if (entries.length === 0) {
    return (
      <p className="text-sm text-content-secondary">
        Nothing recorded yet. Page views, conversations, score changes and
        notes all land here.
      </p>
    );
  }

  return (
    <ol className="space-y-3">
      {entries.map((entry, index) => (
        <li
          key={entry.id ?? `${entry.type}-${index}`}
          className="flex gap-3 text-sm"
        >
          <span
            aria-hidden="true"
            className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-content-tertiary"
          />

          <div className="min-w-0 flex-1">
            <p className="text-content">{entry.title}</p>

            {entry.body && (
              <p className="mt-0.5 text-xs leading-relaxed text-content-secondary">
                {entry.body}
              </p>
            )}

            <p className="mt-0.5 text-xs text-content-tertiary">
              {entry.created_at ? formatTimestamp(entry.created_at) : '—'}
            </p>
          </div>
        </li>
      ))}
    </ol>
  );
}
