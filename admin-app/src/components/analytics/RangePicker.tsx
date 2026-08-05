import { useAgents } from '@/api/queries/useAgents';
import { cn } from '@/lib/cn';

export const RANGES = [
  { days: 7, label: 'Last 7 days' },
  { days: 30, label: 'Last 30 days' },
  { days: 90, label: 'Last 90 days' },
] as const;

export type RangeDays = (typeof RANGES)[number]['days'];

interface RangePickerProps {
  days: RangeDays;
  onDays: (days: RangeDays) => void;
  agent: string;
  onAgent: (uuid: string) => void;
  className?: string;
}

/**
 * Range and clerk, the two filters every report on this area shares.
 *
 * Native selects rather than a custom menu. These are the controls a
 * keyboard user reaches first on the screen, and the platform's own
 * select is the one control that behaves correctly in every assistive
 * technology without being reimplemented.
 *
 * Ranges are fixed windows rather than a date picker. A calendar is the
 * right control for "the week of the launch"; three buttons are the right
 * control for the question this screen is actually asked, which is
 * "what happened lately".
 */
export function RangePicker({
  days,
  onDays,
  agent,
  onAgent,
  className,
}: RangePickerProps) {
  const { data } = useAgents();
  const clerks = data?.agents ?? [];

  // The right padding is not decoration: a native select renders its own
  // chevron inside the box, and without room for it the last character of
  // "Last 30 days" sits underneath the arrow.
  const select =
    'h-9 rounded-lg border border-border bg-surface pl-2.5 pr-8 text-sm text-content ' +
    'transition-colors hover:border-border-strong focus-visible:outline-2 ' +
    'focus-visible:outline-offset-2 focus-visible:outline-accent';

  return (
    <div className={cn('flex items-center gap-2', className)}>
      <label className="sr-only" htmlFor="hvc-range">
        Date range
      </label>
      <select
        id="hvc-range"
        className={select}
        value={days}
        onChange={(event) => onDays(Number(event.target.value) as RangeDays)}
      >
        {RANGES.map((range) => (
          <option key={range.days} value={range.days}>
            {range.label}
          </option>
        ))}
      </select>

      <label className="sr-only" htmlFor="hvc-clerk">
        Clerk
      </label>
      <select
        id="hvc-clerk"
        className={select}
        value={agent}
        onChange={(event) => onAgent(event.target.value)}
      >
        <option value="all">All clerks</option>
        {clerks.map((clerk) => (
          <option key={clerk.uuid} value={clerk.uuid}>
            {clerk.name}
          </option>
        ))}
      </select>
    </div>
  );
}

/**
 * A range as the two dates the API takes.
 *
 * Both ends are inclusive, matching the server, so "last 7 days" is seven
 * days including today rather than eight.
 */
export function rangeParams(days: number): { from: string; to: string } {
  const today = new Date();
  const start = new Date(today);
  start.setUTCDate(start.getUTCDate() - (days - 1));

  return {
    from: start.toISOString().slice(0, 10),
    to: today.toISOString().slice(0, 10),
  };
}
