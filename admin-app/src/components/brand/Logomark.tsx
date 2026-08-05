import { cn } from '@/lib/cn';

interface LogomarkProps {
  size?: number;
  className?: string;
  /** Animate the gradient. One breathing element in the whole product. */
  animated?: boolean;
}

/**
 * A hexagon holding a smaller hexagon: a cell in the hive.
 *
 * The mark is the only place the spectral gradient appears at full strength.
 * A hex cell is specific to this product in a way that a generic sparkle or
 * orb is not — it says "hive", and the nested cell says "one of many
 * workers", which is the whole product thesis.
 */
export function Logomark({ size = 26, className, animated = true }: LogomarkProps) {
  return (
    <span
      aria-hidden="true"
      className={cn(
        'relative inline-grid shrink-0 place-items-center rounded-[7px]',
        'hvc-gradient-brand',
        animated && 'hvc-drift',
        className
      )}
      style={{ width: size, height: size }}
    >
      <svg
        viewBox="0 0 24 24"
        width={size * 0.62}
        height={size * 0.62}
        fill="none"
        stroke="currentColor"
        className="text-white"
        strokeWidth={1.7}
        strokeLinejoin="round"
      >
        <path d="M12 2.6 20.1 7.3v9.4L12 21.4 3.9 16.7V7.3z" opacity={0.55} />
        <path d="M12 8.1l4 2.3v4.6l-4 2.3-4-2.3v-4.6z" />
      </svg>
    </span>
  );
}

interface WordmarkProps {
  name: string;
  className?: string;
}

/**
 * The product name beside the mark.
 */
export function Wordmark({ name, className }: WordmarkProps) {
  return (
    <span
      className={cn(
        'font-display text-[15px] font-bold tracking-[-0.02em] text-content',
        className
      )}
    >
      {name}
    </span>
  );
}
