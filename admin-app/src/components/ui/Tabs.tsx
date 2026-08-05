import type { ReactNode } from 'react';
import { NavLink } from 'react-router';
import { cn } from '@/lib/cn';

interface TabItem {
  label: string;
  to: string;
  count?: number;
  icon?: ReactNode;
}

interface TabsProps {
  items: TabItem[];
  className?: string;
}

/**
 * A routed tab bar.
 *
 * Tabs address URLs rather than component state. A support conversation
 * that ends "open Settings, then the Audit log tab" is worse than one that
 * ends with a link, and in-page tab state cannot be linked to, bookmarked
 * or reloaded back into.
 *
 * The consequence is that these are links, not ARIA tabs — so they get
 * link semantics and browser navigation, which is what they actually are.
 */
export function Tabs({ items, className }: TabsProps) {
  return (
    <nav className={cn('flex items-end gap-1', className)} aria-label="Section">
      {items.map(({ label, to, count, icon }) => (
        <NavLink
          key={to}
          to={to}
          end
          className={({ isActive }) =>
            cn(
              'group relative flex items-center gap-2 px-3 pb-2.5 pt-1 text-sm',
              'transition-colors duration-[var(--hvc-duration-fast)]',
              isActive
                ? 'font-medium text-content'
                : 'text-content-tertiary hover:text-content-secondary'
            )
          }
        >
          {({ isActive }) => (
            <>
              {icon}
              <span>{label}</span>

              {typeof count === 'number' && (
                <span
                  className={cn(
                    'rounded-full px-1.5 py-px font-mono text-[10px] tabular-nums',
                    isActive
                      ? 'bg-accent-subtle text-accent-text'
                      : 'bg-surface-sunken text-content-tertiary'
                  )}
                >
                  {count}
                </span>
              )}

              {/* The active edge sits on the container's hairline, so the
                  two read as one rule rather than two stacked lines. */}
              <span
                aria-hidden="true"
                className={cn(
                  'absolute inset-x-1 bottom-[-1px] h-0.5 rounded-full transition-opacity',
                  isActive ? 'hvc-gradient-brand opacity-100' : 'opacity-0'
                )}
              />
            </>
          )}
        </NavLink>
      ))}
    </nav>
  );
}
