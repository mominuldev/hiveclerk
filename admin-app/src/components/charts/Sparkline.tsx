interface SparklineProps {
  values: number[];
  /** Accessible summary. The shape alone is not information to a screen reader. */
  label: string;
  height?: number;
}

/**
 * The trend line under a KPI figure.
 *
 * Hand-drawn SVG rather than a charting library. Recharts is in the
 * dependency list and would render this in four lines, but it costs
 * roughly a third of the whole admin bundle budget to draw a shape with
 * no axes, no tooltip and no legend — and the KPI row renders four of
 * them above the fold on the first screen of the product.
 *
 * Colour comes from `currentColor`, so the card decides whether a rise is
 * good news. Spend is the one place where it is not.
 */
export function Sparkline({ values, label, height = 28 }: SparklineProps) {
  const width = 120;

  if (values.length < 2) {
    // One point is not a trend. Rendering a flat line for a site that
    // has been live a day would state a fact nobody measured.
    return (
      <div
        className="text-[11px] text-content-tertiary"
        style={{ height }}
        role="img"
        aria-label={`${label}: not enough history to show a trend yet`}
      >
        Not enough history yet
      </div>
    );
  }

  const max = Math.max(...values);
  const min = Math.min(...values);
  const span = max - min || 1;
  const step = width / (values.length - 1);

  const points = values.map((value, index) => {
    const x = index * step;
    // Inverted: SVG y grows downward, and a chart that grew downward
    // would be read upside down by everybody.
    const y = height - ((value - min) / span) * (height - 2) - 1;
    return `${x.toFixed(1)},${y.toFixed(1)}`;
  });

  const area = `M0,${height} L${points.join(' L')} L${width},${height} Z`;

  return (
    <svg
      viewBox={`0 0 ${width} ${height}`}
      width="100%"
      height={height}
      preserveAspectRatio="none"
      role="img"
      aria-label={`${label}: ${values.length} days, from ${values[0]} to ${values[values.length - 1]}`}
      className="overflow-visible"
    >
      <path d={area} fill="currentColor" opacity="0.12" />
      <polyline
        points={points.join(' ')}
        fill="none"
        stroke="currentColor"
        strokeWidth="1.5"
        strokeLinecap="round"
        strokeLinejoin="round"
        vectorEffect="non-scaling-stroke"
      />
    </svg>
  );
}
