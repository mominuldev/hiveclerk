import { Plus } from 'lucide-react';
import { StatusDot } from '@/components/ui/StatusDot';
import { useRoster, type Clerk } from '@/stores/useRoster';
import { cn } from '@/lib/cn';

interface RosterRailProps {
  clerks: Clerk[];
  onHire?: () => void;
}

/**
 * The signature component: a permanent staff board.
 *
 * Clerks are the nouns of this product, so they stay on screen everywhere.
 * Selecting one filters the current screen instead of navigating away, which
 * is what makes this a roster rather than a menu.
 */
export function RosterRail({ clerks, onHire }: RosterRailProps) {
  const selected = useRoster((s) => s.selected);
  const select = useRoster((s) => s.select);

  return (
    <nav aria-label="Roster" className="flex min-h-0 flex-1 flex-col">
      <div className="flex items-center justify-between px-4 pb-2">
        <h2 className="text-[10px] font-semibold uppercase tracking-[0.12em] text-content-tertiary">
          Roster
        </h2>
        <button
          type="button"
          onClick={onHire}
          aria-label="Hire a clerk"
          className="rounded-md p-1 text-content-tertiary transition-colors hover:bg-surface-hover hover:text-content"
        >
          <Plus size={14} aria-hidden="true" />
        </button>
      </div>

      {clerks.length === 0 ? (
        <div className="mx-3 rounded-xl border border-dashed border-border px-3 py-4">
          <p className="text-xs leading-relaxed text-content-tertiary">
            Nobody&rsquo;s on duty yet. Hire your first clerk and it&rsquo;ll
            start answering in about ten minutes.
          </p>
        </div>
      ) : (
        <ul className="min-h-0 flex-1 space-y-0.5 overflow-y-auto px-2.5 pb-3">
          {clerks.map((clerk) => {
            const isActive = selected === clerk.uuid;

            return (
              <li key={clerk.uuid}>
                <button
                  type="button"
                  aria-current={isActive ? 'true' : undefined}
                  onClick={() => select(isActive ? null : clerk.uuid)}
                  className={cn(
                    'relative flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left',
                    'transition-colors duration-[var(--hvc-duration-fast)]',
                    isActive ? 'bg-surface-hover' : 'hover:bg-surface-hover'
                  )}
                >
                  <span
                    aria-hidden="true"
                    className={cn(
                      'hvc-gradient-brand absolute left-0 top-1/2 w-[3px] -translate-y-1/2 rounded-full transition-all duration-[var(--hvc-duration-base)]',
                      isActive ? 'h-6 opacity-100' : 'h-0 opacity-0'
                    )}
                  />

                  <StatusDot status={clerk.status} iconOnly />

                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-medium text-content">
                      {clerk.name}
                    </span>
                    <span className="block truncate text-xs text-content-tertiary">
                      {clerk.role}
                    </span>
                  </span>
                </button>
              </li>
            );
          })}
        </ul>
      )}
    </nav>
  );
}
