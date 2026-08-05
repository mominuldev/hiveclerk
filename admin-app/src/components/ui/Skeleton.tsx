import type { CSSProperties } from 'react';
import { cn } from '@/lib/cn';

interface SkeletonProps {
  className?: string;
  /** For widths that come from data, such as a table's column sizing. */
  style?: CSSProperties;
}

/**
 * A placeholder shaped like the content it replaces.
 *
 * Skeletons rather than spinners: a spinner says "something is happening",
 * a skeleton says "this is what is coming", and it prevents the layout
 * shift that a spinner-to-content swap causes.
 */
export function Skeleton({ className, style }: SkeletonProps) {
  return (
    <div
      aria-hidden="true"
      style={style}
      className={cn(
        'animate-pulse rounded-md bg-surface-sunken',
        className
      )}
    />
  );
}
