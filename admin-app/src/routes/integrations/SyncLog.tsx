import { useState } from 'react';
import { Badge } from '@/components/ui/Badge';
import { Card } from '@/components/ui/Card';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { Select } from '@/components/ui/Field';
import { Pagination } from '@/components/ui/Pagination';
import { relative } from '@/lib/format';
import { useSyncLog, type SyncLogRow } from '@/api/queries/useIntegrations';

const TONES: Record<SyncLogRow['status'], 'positive' | 'warning' | 'danger' | 'neutral'> = {
  success: 'positive',
  retrying: 'warning',
  failed: 'danger',
  skipped: 'neutral',
};

/**
 * Every sync attempt, whether it worked or not (FR-CRM-08).
 *
 * ## Retrying is its own row and its own colour
 *
 * A failure that the plugin is still working on is not the same as one it
 * gave up on, and showing both as red would have an operator re-pushing
 * leads that were about to succeed on their own. A retrying row carries
 * the attempt number and when the next one is due, which together answer
 * "do I need to do anything" without anybody having to ask.
 */
export function SyncLog() {
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);

  const log = useSyncLog({
    ...(status ? { status } : {}),
    page,
  });

  if (log.isError) {
    return <ErrorNotice error={log.error} onRetry={() => void log.refetch()} />;
  }

  const columns: Array<Column<SyncLogRow>> = [
    {
      key: 'when',
      header: 'When',
      width: '9rem',
      render: (row) => (
        <span className="text-xs text-content-secondary">
          {relative(row.created_at)}
        </span>
      ),
    },
    {
      key: 'provider',
      header: 'Integration',
      width: '8rem',
      render: (row) => (
        <span className="text-sm text-content">{row.provider ?? '—'}</span>
      ),
    },
    {
      key: 'operation',
      header: 'Operation',
      secondary: true,
      width: '9rem',
      render: (row) => (
        <span className="font-mono text-[12px] text-content-tertiary">
          {row.operation}
        </span>
      ),
    },
    {
      key: 'status',
      header: 'Result',
      width: '7rem',
      render: (row) => <Badge tone={TONES[row.status]}>{row.status_label}</Badge>,
    },
    {
      key: 'detail',
      header: 'Detail',
      render: (row) => <Detail row={row} />,
    },
  ];

  return (
    <Card
      title="Sync log"
      actions={
        <Select
          aria-label="Filter by result"
          className="max-w-40"
          value={status}
          onChange={(event) => {
            setStatus(event.target.value);
            setPage(1);
          }}
        >
          <option value="">Everything</option>
          <option value="success">Synced</option>
          <option value="retrying">Retrying</option>
          <option value="failed">Failed</option>
        </Select>
      }
    >
      <DataTable
        columns={columns}
        rows={log.data?.rows ?? []}
        rowKey={(row) => String(row.id)}
        isLoading={log.isLoading}
        empty={
          <EmptyState
            bare
            title="Nothing has synced yet"
            description="Connect a CRM and qualify a lead, and every attempt to push it will appear here — including the ones that fail."
          />
        }
      />

      {(log.data?.totalPages ?? 1) > 1 && (
        <div className="mt-4">
          <Pagination
            meta={{
              page,
              per_page: 25,
              total: log.data?.total ?? 0,
              total_pages: log.data?.totalPages ?? 1,
            }}
            onChange={setPage}
            noun="attempt"
          />
        </div>
      )}
    </Card>
  );
}

/**
 * The rightmost cell: what happened, in the fewest words that are true.
 */
function Detail({ row }: { row: SyncLogRow }) {
  if (row.status === 'retrying') {
    return (
      <span className="text-xs text-content-secondary">
        Attempt {row.attempt} failed
        {row.error ? ` — ${row.error}` : ''}.{' '}
        {row.next_retry_at && (
          <span className="text-content-tertiary">
            Trying again {relative(row.next_retry_at)}.
          </span>
        )}
      </span>
    );
  }

  if (row.error) {
    return <span className="text-xs text-danger">{row.error}</span>;
  }

  if (row.external_id) {
    return (
      <span className="font-mono text-[12px] text-content-tertiary">
        {row.external_id}
      </span>
    );
  }

  return <span className="text-xs text-content-tertiary">—</span>;
}
