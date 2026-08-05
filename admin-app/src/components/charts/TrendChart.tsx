import { useId, useState } from 'react';
import { cn } from '@/lib/cn';

export interface TrendPoint {
  date: string;
  value: number;
}

interface TrendChartProps {
  points: TrendPoint[];
  label: string;
  /** Rendered in the hover readout. Defaults to the raw number. */
  format?: (value: number) => string;
  className?: string;
  height?: number;
}

/**
 * A dated line with a baseline, two axis labels and a hover readout.
 *
 * Deliberately spare. The wireframe's conversation-volume panel has one
 * series, two dates and a maximum — everything else a general charting
 * library brings would be furniture around a shape that is already
 * legible.
 *
 * The readout is keyboard reachable: each day is a focusable point, so
 * the figures are available without a pointer. A chart whose only way in
 * is `mousemove` is a chart half the audit cannot read.
 */
export function TrendChart({
  points,
  label,
  format = (value) => value.toLocaleString(),
  className,
  height = 180,
}: TrendChartProps) {
  const [active, setActive] = useState<number | null>(null);
  const gradientId = useId();

  if (points.length < 2) {
    return (
      <div
        className={cn(
          'flex items-center justify-center rounded-lg border border-dashed border-border text-sm text-content-tertiary',
          className
        )}
        style={{ height }}
      >
        Not enough history to draw a trend yet.
      </div>
    );
  }

  const first = points[0];
  const last = points[points.length - 1];

  if (!first || !last) {
    return null;
  }

  const width = 600;
  const padding = { top: 10, right: 4, bottom: 4, left: 4 };
  const plotHeight = height - padding.top - padding.bottom;
  const max = Math.max(...points.map((p) => p.value), 1);
  const step = (width - padding.left - padding.right) / (points.length - 1);

  const coords = points.map((point, index) => {
    const x = padding.left + index * step;
    const y = padding.top + plotHeight - (point.value / max) * plotHeight;
    return { x, y, point };
  });

  const line = coords.map(({ x, y }) => `${x.toFixed(1)},${y.toFixed(1)}`);
  const area = `M${padding.left},${height} L${line.join(' L')} L${width - padding.right},${height} Z`;
  const shown = active === null ? null : (coords[active] ?? null);

  return (
    <figure className={cn('m-0', className)}>
      <div className="relative">
        <svg
          viewBox={`0 0 ${width} ${height}`}
          width="100%"
          height={height}
          preserveAspectRatio="none"
          role="img"
          aria-label={`${label} from ${first.date} to ${last.date}, peaking at ${max}`}
          className="text-accent"
        >
          <defs>
            <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor="currentColor" stopOpacity="0.22" />
              <stop offset="100%" stopColor="currentColor" stopOpacity="0" />
            </linearGradient>
          </defs>

          <path d={area} fill={`url(#${gradientId})`} />
          <polyline
            points={line.join(' ')}
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            vectorEffect="non-scaling-stroke"
          />

          {shown && (
            <>
              <line
                x1={shown.x}
                y1={padding.top}
                x2={shown.x}
                y2={height}
                stroke="currentColor"
                strokeWidth="1"
                strokeDasharray="3 3"
                opacity="0.5"
                vectorEffect="non-scaling-stroke"
              />
              <circle cx={shown.x} cy={shown.y} r="3.5" fill="currentColor" vectorEffect="non-scaling-stroke" />
            </>
          )}
        </svg>

        {/* One button per day, laid over the plot. Invisible, but focusable
            and named — which is what makes the numbers reachable without a
            pointer. */}
        <div className="absolute inset-0 flex">
          {points.map((point, index) => (
            <button
              key={point.date}
              type="button"
              className="h-full flex-1 cursor-default focus-visible:bg-surface-hover focus-visible:outline-none"
              onMouseEnter={() => setActive(index)}
              onFocus={() => setActive(index)}
              onMouseLeave={() => setActive(null)}
              onBlur={() => setActive(null)}
            >
              <span className="sr-only">{`${point.date}: ${format(point.value)}`}</span>
            </button>
          ))}
        </div>
      </div>

      <figcaption className="mt-2 flex items-baseline justify-between text-[11px] text-content-tertiary">
        <span className="font-mono tabular-nums">{first.date}</span>
        <span
          aria-live="polite"
          className={cn(
            'font-mono tabular-nums transition-opacity',
            shown ? 'text-content opacity-100' : 'opacity-0'
          )}
        >
          {shown ? `${shown.point.date} · ${format(shown.point.value)}` : ' '}
        </span>
        <span className="font-mono tabular-nums">{last.date}</span>
      </figcaption>
    </figure>
  );
}
