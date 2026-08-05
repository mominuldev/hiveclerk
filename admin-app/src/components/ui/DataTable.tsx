import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { Skeleton } from './Skeleton';

export interface Column<T> {
  /** Stable key, also used for the React key on cells. */
  key: string;
  header: string;
  /** Right-align and use tabular figures. For counts, costs, durations. */
  numeric?: boolean;
  /** Fixed width, e.g. '10rem'. Otherwise the column shares the space. */
  width?: string;
  /** Hidden below the medium breakpoint. */
  secondary?: boolean;
  render: (row: T) => ReactNode;
}

interface DataTableProps<T> {
  columns: Array<Column<T>>;
  rows: T[];
  rowKey: (row: T) => string;
  isLoading?: boolean;
  /** Rendered in place of the table when there are no rows. */
  empty?: ReactNode;
  /** Makes rows activatable. Adds hover and keyboard affordances. */
  onRowClick?: (row: T) => void;
  /** Skeleton rows to draw while loading. Match the usual page size. */
  loadingRows?: number;
  className?: string;
}

/**
 * A table for lists of records.
 *
 * Two decisions worth stating. It renders a real `table` rather than a
 * grid of divs, because screen readers announce row and column position
 * from table semantics and nothing replaces that. And a clickable row
 * carries a real button in its first cell rather than a click handler on
 * the `tr`: a div-with-onClick is unreachable by keyboard, and putting the
 * handler on the row while also having links inside it produces the
 * nested-interactive problem that makes both unusable.
 *
 * Columns marked secondary drop out below the medium breakpoint rather
 * than forcing a horizontal scroll. Which columns survive is a content
 * decision, so it lives with the column definition.
 */
export function DataTable<T>({
  columns,
  rows,
  rowKey,
  isLoading = false,
  empty,
  onRowClick,
  loadingRows = 8,
  className,
}: DataTableProps<T>) {
  if (isLoading) {
    return (
      <div className={cn('overflow-hidden rounded-xl border border-border', className)}>
        <TableHead columns={columns} />
        <div className="divide-y divide-border">
          {Array.from({ length: loadingRows }, (_, i) => (
            <div key={i} className="flex items-center gap-4 px-4 py-3">
              {columns.map((column) => (
                <Skeleton
                  key={column.key}
                  className={cn('h-4', column.width ? '' : 'flex-1')}
                  {...(column.width ? { style: { width: column.width } } : {})}
                />
              ))}
            </div>
          ))}
        </div>
      </div>
    );
  }

  if (rows.length === 0 && empty) {
    return (
      <div className={cn('rounded-xl border border-border bg-surface', className)}>
        {empty}
      </div>
    );
  }

  return (
    <div
      className={cn(
        'overflow-x-auto rounded-xl border border-border bg-surface',
        className
      )}
    >
      <table className="w-full border-collapse text-sm">
        <thead>
          <tr className="border-b border-border bg-surface-sunken">
            {columns.map((column) => (
              <th
                key={column.key}
                scope="col"
                style={column.width ? { width: column.width } : undefined}
                className={cn(
                  'px-4 py-2.5 text-[11px] font-semibold uppercase tracking-[0.08em] text-content-tertiary',
                  column.numeric ? 'text-right' : 'text-left',
                  column.secondary && 'hidden md:table-cell'
                )}
              >
                {column.header}
              </th>
            ))}
          </tr>
        </thead>

        <tbody className="divide-y divide-border">
          {rows.map((row) => (
            <tr
              key={rowKey(row)}
              className={cn(
                'transition-colors',
                onRowClick && 'cursor-pointer hover:bg-surface-hover'
              )}
              onClick={onRowClick ? () => onRowClick(row) : undefined}
            >
              {columns.map((column, index) => (
                <td
                  key={column.key}
                  className={cn(
                    'px-4 py-2.5 align-middle text-content-secondary',
                    column.numeric && 'text-right font-mono text-[13px] tabular-nums',
                    column.secondary && 'hidden md:table-cell'
                  )}
                >
                  {/* The first cell carries the keyboard affordance so an
                      activatable row is reachable without a mouse. */}
                  {index === 0 && onRowClick ? (
                    <button
                      type="button"
                      onClick={(event) => {
                        event.stopPropagation();
                        onRowClick(row);
                      }}
                      className="text-left"
                    >
                      {column.render(row)}
                    </button>
                  ) : (
                    column.render(row)
                  )}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/**
 * Header row used by the loading state, which is not a real table.
 */
function TableHead<T>({ columns }: { columns: Array<Column<T>> }) {
  return (
    <div className="flex items-center gap-4 border-b border-border bg-surface-sunken px-4 py-2.5">
      {columns.map((column) => (
        <span
          key={column.key}
          style={column.width ? { width: column.width } : undefined}
          className={cn(
            'text-[11px] font-semibold uppercase tracking-[0.08em] text-content-tertiary',
            column.width ? '' : 'flex-1',
            column.numeric && 'text-right',
            column.secondary && 'hidden md:block'
          )}
        >
          {column.header}
        </span>
      ))}
    </div>
  );
}
