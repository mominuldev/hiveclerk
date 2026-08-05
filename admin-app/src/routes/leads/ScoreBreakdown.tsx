import { BAND_FILL, BAND_TONE, scoreFraction } from './band';
import { Badge } from '@/components/ui/Badge';
import { cn } from '@/lib/cn';
import type { LeadDetail } from '@/api/queries/useLeads';

/**
 * How this was calculated (FR-LED-04, D11 §6.2).
 *
 * Every line is attributed and every model adjustment carries the
 * sentence that justifies it. This screen exists because a sales team
 * does not trust opaque scoring — and an unexplained number is worse
 * than no number, because it invites decisions nobody can defend.
 *
 * The lines add up to the total shown above them, visibly. That is not
 * decoration either: the total is a materialised column and the lines are
 * an append-only log, and the day they disagree is the day this screen
 * has to show it rather than hide it behind a recalculation.
 */
export function ScoreBreakdown({ lead }: { lead: LeadDetail }) {
  const fraction = scoreFraction(lead.score, lead.ceiling);
  const sum = lead.breakdown.reduce((total, line) => total + line.points, 0);

  return (
    <section className="space-y-4">
      <header className="flex items-baseline justify-between gap-3">
        <h3 className="text-xs font-semibold uppercase tracking-wide text-content-secondary">
          Score
        </h3>
        <div className="flex items-baseline gap-2">
          <span className="tabular-nums text-2xl font-semibold text-content">
            {lead.score}
          </span>
          <span className="tabular-nums text-sm text-content-tertiary">
            / {lead.ceiling}
          </span>
          <Badge tone={BAND_TONE[lead.band]}>{lead.band_label}</Badge>
        </div>
      </header>

      <span
        className="block h-2 overflow-hidden rounded-full bg-surface-sunken"
        role="img"
        aria-label={`${lead.score} out of a possible ${lead.ceiling}`}
      >
        <span
          className={cn('block h-full rounded-full', BAND_FILL[lead.band])}
          style={{ width: `${Math.round(fraction * 100)}%` }}
        />
      </span>

      {lead.breakdown.length === 0 ? (
        <p className="text-sm text-content-secondary">
          Nothing has scored yet. Rules award points as a conversation gives
          them something to go on — an address, a page visit, a stated budget.
        </p>
      ) : (
        <>
          <h4 className="text-xs font-semibold uppercase tracking-wide text-content-secondary">
            How this was calculated
          </h4>

          <ul className="space-y-3">
            {lead.breakdown.map((line, index) => (
              <li
                key={`${line.label}-${line.created_at ?? index}`}
                className="flex items-start justify-between gap-4"
              >
                <div className="min-w-0">
                  <p className="text-sm text-content">{line.label}</p>
                  <p className="mt-0.5 text-xs uppercase tracking-wide text-content-tertiary">
                    {line.source === 'ai'
                      ? 'AI'
                      : line.source === 'manual'
                        ? 'Manual'
                        : 'Rule'}
                  </p>
                  {line.rationale && (
                    <p className="mt-1 text-xs leading-relaxed text-content-secondary">
                      {line.rationale}
                    </p>
                  )}
                </div>

                <span
                  className={cn(
                    'shrink-0 tabular-nums text-sm font-medium',
                    line.points >= 0 ? 'text-content' : 'text-danger'
                  )}
                >
                  {line.points > 0 ? `+${line.points}` : line.points}
                </span>
              </li>
            ))}
          </ul>

          <div className="flex items-baseline justify-between border-t border-border pt-3">
            <span className="text-sm font-medium text-content">Total</span>
            <span className="tabular-nums text-sm font-semibold text-content">
              {sum}
            </span>
          </div>

          {sum !== lead.score && (
            /* Surfaced rather than smoothed over. The total on the lead and
               the sum of its events are written together and can still
               drift — a crash between the two writes, a partial restore —
               and a screen that quietly showed one of them would make that
               permanent and invisible. */
            <p role="status" className="text-xs text-warning">
              These lines add up to {sum}, but the lead is stored as{' '}
              {lead.score}. Adjusting the score by hand rewrites the stored
              total from the log.
            </p>
          )}
        </>
      )}
    </section>
  );
}
