import { useState } from 'react';
import { Badge } from '@/components/ui/Badge';
import { Card } from '@/components/ui/Card';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { Select } from '@/components/ui/Field';
import { Pagination } from '@/components/ui/Pagination';
import { relative } from '@/lib/format';
import { useEmailLog, type EmailLogRow } from '@/api/queries/useEmail';

const TONES: Record<
  EmailLogRow['status'],
  'positive' | 'warning' | 'danger' | 'neutral'
> = {
  sent: 'positive',
  queued: 'neutral',
  failed: 'danger',
  suppressed: 'neutral',
};

/**
 * What actually went out.
 *
 * ## "Handed to the mailer", never "delivered"
 *
 * This plugin sends through `wp_mail()` and cannot see past it — the
 * site's SMTP plugin, its provider and the recipient's server all sit
 * between us and the truth. The label says exactly what is known, because
 * a log that claimed delivery it could not observe is a log nobody
 * believes the second time they check it.
 *
 * Suppressed rows are here too. "We did not email this person because
 * they unsubscribed in March" is the answer to a complaint, and it only
 * exists because the decision was written down at the time.
 */
export function EmailLog() {
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);

  const log = useEmailLog({ ...(status ? { status } : {}), page });

  if (log.isError) {
    return <ErrorNotice error={log.error} onRetry={() => void log.refetch()} />;
  }

  const columns: Array<Column<EmailLogRow>> = [
    {
      key: 'when',
      header: 'When',
      width: '9rem',
      render: (row) => (
        <span className="text-xs text-content-secondary">
          {relative(row.sent_at ?? row.created_at)}
        </span>
      ),
    },
    {
      key: 'to',
      header: 'To',
      width: '14rem',
      render: (row) => (
        <span className="truncate text-sm text-content">{row.to}</span>
      ),
    },
    {
      key: 'subject',
      header: 'Subject',
      render: (row) => (
        <span className="truncate text-sm text-content-secondary">
          {row.subject}
        </span>
      ),
    },
    {
      key: 'status',
      header: 'Result',
      width: '11rem',
      render: (row) => (
        <div className="flex flex-col gap-1">
          <Badge tone={TONES[row.status]}>{row.status_label}</Badge>
          {row.error && (
            <span className="text-[11px] leading-snug text-content-tertiary">
              {row.error}
            </span>
          )}
        </div>
      ),
    },
  ];

  return (
    <Card
      title="Sent email"
      actions={
        <Select
          aria-label="Filter by result"
          className="max-w-44"
          value={status}
          onChange={(event) => {
            setStatus(event.target.value);
            setPage(1);
          }}
        >
          <option value="">Everything</option>
          <option value="sent">Handed to the mailer</option>
          <option value="failed">Failed</option>
          <option value="suppressed">Not sent</option>
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
            title="Nothing has been sent"
            description="Activate a sequence and the emails it sends will be listed here, including the ones the suppression list stopped."
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
            noun="email"
          />
        </div>
      )}
    </Card>
  );
}
