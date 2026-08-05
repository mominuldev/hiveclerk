import type { ScoreBand } from '@/api/queries/useLeads';

/**
 * How a band renders.
 *
 * Mapped to the state tones the rest of the admin uses rather than to
 * colours of its own. A "hot" lead and a clerk that needs attention are
 * the same kind of signal to the person scanning the screen, and giving
 * scoring its own palette would make the operator learn a second one.
 */
export const BAND_TONE: Record<
  ScoreBand,
  'neutral' | 'info' | 'warning' | 'positive'
> = {
  cold: 'neutral',
  warm: 'info',
  hot: 'warning',
  qualified: 'positive',
};

/**
 * The meter fill for a band.
 *
 * Kept as class names rather than inline styles so the values stay in the
 * token file, which is the rule for every colour in this application.
 */
export const BAND_FILL: Record<ScoreBand, string> = {
  cold: 'bg-content-tertiary',
  warm: 'bg-accent',
  hot: 'bg-warning',
  qualified: 'bg-on-duty',
};

/**
 * The score as a fraction of what the rule set can award.
 *
 * A ceiling of zero means the customer has disabled every positive rule,
 * and dividing by it would render every lead as full. Returning zero is
 * the honest answer: nothing can be scored, so nothing is.
 */
export function scoreFraction(score: number, ceiling: number): number {
  if (ceiling <= 0) {
    return 0;
  }

  return Math.max(0, Math.min(1, score / ceiling));
}

/**
 * Set a filter, or remove it when it is empty.
 *
 * `exactOptionalPropertyTypes` is on, so assigning `undefined` to an
 * optional key is not the same as leaving it out — and the query key is
 * built from this object, so a key holding `undefined` would be a
 * different cache entry from one without it.
 */
export function withFilter<T extends object, K extends keyof T>(
  current: T,
  key: K,
  value: T[K] | '' | undefined
): T {
  const next = { ...current };

  if (value === '' || value === undefined) {
    delete next[key];
  } else {
    next[key] = value;
  }

  return next;
}
