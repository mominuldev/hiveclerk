import { useEffect, useState } from 'react';
import { Search, X } from 'lucide-react';
import { Input, Select } from './Field';
import { cn } from '@/lib/cn';

export interface FilterOption {
  value: string;
  label: string;
}

export interface FilterSelect {
  key: string;
  label: string;
  value: string;
  options: FilterOption[];
  onChange: (value: string) => void;
}

interface FiltersProps {
  search?: {
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
  };
  selects?: FilterSelect[];
  /** Shown when anything is active. Resets every control at once. */
  onClear?: () => void;
  className?: string;
}

/**
 * A filter bar: one search box and any number of selects.
 *
 * Search is debounced here rather than in each caller. Every list screen
 * needs the same 300ms, and a screen that forgets it issues a request per
 * keystroke — which on a slow connection means results arriving out of
 * order and the list flickering between answers to different queries.
 */
export function Filters({ search, selects, onClear, className }: FiltersProps) {
  const active =
    Boolean(search?.value) || Boolean(selects?.some((s) => s.value !== ''));

  return (
    <div className={cn('flex flex-wrap items-center gap-2', className)}>
      {search && (
        <DebouncedSearch
          value={search.value}
          onChange={search.onChange}
          {...(search.placeholder ? { placeholder: search.placeholder } : {})}
        />
      )}

      {selects?.map((select) => (
        <Select
          key={select.key}
          value={select.value}
          onChange={(event) => select.onChange(event.target.value)}
          aria-label={select.label}
          className="h-9 w-auto min-w-[10rem]"
        >
          <option value="">{select.label}: any</option>
          {select.options.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </Select>
      ))}

      {active && onClear && (
        <button
          type="button"
          onClick={onClear}
          className="flex items-center gap-1 rounded-lg px-2 py-1.5 text-xs text-content-tertiary transition-colors hover:bg-surface-hover hover:text-content"
        >
          <X size={13} />
          Clear
        </button>
      )}
    </div>
  );
}

interface DebouncedSearchProps {
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
}

function DebouncedSearch({
  value,
  onChange,
  placeholder = 'Search',
}: DebouncedSearchProps) {
  const [draft, setDraft] = useState(value);

  // Follow the parent when it resets the filters from outside.
  useEffect(() => {
    setDraft(value);
  }, [value]);

  useEffect(() => {
    if (draft === value) {
      return;
    }

    const timer = window.setTimeout(() => onChange(draft), 300);

    return () => window.clearTimeout(timer);
  }, [draft, value, onChange]);

  return (
    <div className="relative">
      <Search
        size={14}
        aria-hidden="true"
        className="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-content-tertiary"
      />
      <Input
        type="search"
        value={draft}
        onChange={(event) => setDraft(event.target.value)}
        placeholder={placeholder}
        aria-label={placeholder}
        className="w-56 pl-8"
      />
    </div>
  );
}
