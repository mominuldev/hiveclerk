import { Moon, Search, Sun } from 'lucide-react';
import { useTheme } from '@/hooks/useTheme';
import { boot } from '@/boot';

interface TopBarProps {
  title: string;
  subtitle?: string;
}

export function TopBar({ title, subtitle }: TopBarProps) {
  const { resolved, toggle } = useTheme();
  const { user } = boot();

  const initials = user.name
    ? user.name
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part[0] ?? '')
        .join('')
        .toUpperCase()
    : '';

  return (
    <header className="sticky top-0 z-20 flex h-[var(--hvc-header-height)] shrink-0 items-center justify-between gap-4 border-b border-border bg-canvas/80 px-6 backdrop-blur-xl">
      <div className="min-w-0">
        <h1 className="truncate font-display text-[19px] font-bold leading-tight tracking-[-0.02em] text-content">
          {title}
        </h1>
        {subtitle && (
          <p className="truncate text-xs text-content-tertiary">{subtitle}</p>
        )}
      </div>

      <div className="flex items-center gap-2">
        <button
          type="button"
          className="hidden items-center gap-2 rounded-lg border border-border bg-surface px-2.5 py-1.5 text-xs text-content-tertiary transition-colors hover:border-border-strong hover:text-content-secondary sm:flex"
        >
          <Search size={13} aria-hidden="true" />
          <span>Search</span>
          <kbd className="rounded border border-border px-1 font-mono text-[10px] text-content-tertiary">
            ⌘K
          </kbd>
        </button>

        <button
          type="button"
          onClick={toggle}
          aria-label={
            resolved === 'dark'
              ? 'Switch to light theme'
              : 'Switch to dark theme'
          }
          className="rounded-lg border border-transparent p-2 text-content-secondary transition-colors hover:border-border hover:bg-surface hover:text-content"
        >
          {resolved === 'dark' ? (
            <Sun size={16} aria-hidden="true" />
          ) : (
            <Moon size={16} aria-hidden="true" />
          )}
        </button>

        {initials && (
          <span
            title={user.name}
            className="grid size-8 place-items-center rounded-full border border-border bg-surface text-[11px] font-semibold text-content-secondary"
          >
            {initials}
          </span>
        )}
      </div>
    </header>
  );
}
