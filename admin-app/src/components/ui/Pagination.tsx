import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button } from './Button';

export interface PageMeta {
  page: number;
  per_page: number;
  total: number;
  total_pages: number;
}

interface PaginationProps {
  meta: PageMeta;
  onChange: (page: number) => void;
  /** What is being counted, singular. "entry", "conversation". */
  noun?: string;
}

/**
 * Previous and next, with an honest count.
 *
 * Numbered page links are deliberately absent. They only help when the
 * pages mean something — an alphabetical index, say — and for a
 * reverse-chronological log "page 7" is a position that changes every time
 * a row is written. The count is what an operator actually reads.
 */
export function Pagination({ meta, onChange, noun = 'row' }: PaginationProps) {
  if (meta.total_pages <= 1) {
    return null;
  }

  const first = (meta.page - 1) * meta.per_page + 1;
  const last = Math.min(meta.page * meta.per_page, meta.total);

  return (
    <div className="flex items-center justify-between gap-4 pt-3">
      <p className="text-xs text-content-tertiary">
        <span className="font-mono tabular-nums text-content-secondary">
          {first}–{last}
        </span>{' '}
        of{' '}
        <span className="font-mono tabular-nums text-content-secondary">
          {meta.total.toLocaleString()}
        </span>{' '}
        {meta.total === 1 ? noun : `${noun}s`}
      </p>

      <div className="flex items-center gap-1.5">
        <Button
          size="sm"
          onClick={() => onChange(meta.page - 1)}
          disabled={meta.page <= 1}
          icon={<ChevronLeft size={14} />}
        >
          Previous
        </Button>
        <Button
          size="sm"
          onClick={() => onChange(meta.page + 1)}
          disabled={meta.page >= meta.total_pages}
        >
          Next
          <ChevronRight size={14} />
        </Button>
      </div>
    </div>
  );
}
