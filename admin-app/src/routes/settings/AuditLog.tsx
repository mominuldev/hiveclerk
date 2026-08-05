import { useState } from 'react';
import { ShieldAlert } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { Drawer } from '@/components/ui/Drawer';
import { EmptyState } from '@/components/ui/EmptyState';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { Filters } from '@/components/ui/Filters';
import { Pagination } from '@/components/ui/Pagination';
import {
  useAuditActions,
  useAuditLog,
  type AuditEntry,
} from '@/api/queries/useAuditLog';

/**
 * Every configuration change, newest first.
 *
 * Read-only, because a log that can be edited proves nothing. The payload
 * opens in a drawer rather than expanding inline so the list keeps its
 * position — an operator scanning for the moment something changed should
 * not lose their place to look at one row.
 */
export function AuditLog() {
  const [page, setPage] = useState(1);
  const [action, setAction] = useState('');
  const [selected, setSelected] = useState<AuditEntry | null>(null);

  const { data, isPending, isError, error, refetch } = useAuditLog({
    page,
    action,
  });
  const actions = useAuditActions();

  if (isError) {
    return <ErrorNotice error={error} onRetry={() => void refetch()} />;
  }

  const columns: Array<Column<AuditEntry>> = [
    {
      key: 'action',
      header: 'Action',
      render: (entry) => (
        <span className="flex items-center gap-2">
          <span className="font-mono text-[13px] text-content">
            {entry.action}
          </span>
          {entry.sensitive && (
            <Badge
              tone="warning"
              icon={<ShieldAlert size={11} aria-hidden="true" />}
            >
              Security
            </Badge>
          )}
        </span>
      ),
    },
    {
      key: 'user',
      header: 'Who',
      width: '12rem',
      render: (entry) => entry.user,
    },
    {
      key: 'object',
      header: 'Object',
      width: '9rem',
      secondary: true,
      render: (entry) =>
        entry.object_type ? (
          <span className="text-content-tertiary">
            {entry.object_type}
            {entry.object_id !== null && ` #${entry.object_id}`}
          </span>
        ) : (
          <span className="text-content-tertiary">—</span>
        ),
    },
    {
      key: 'created_at',
      header: 'When (UTC)',
      width: '12rem',
      numeric: true,
      render: (entry) => (
        <span className="whitespace-nowrap">{entry.created_at}</span>
      ),
    },
  ];

  return (
    <div className="space-y-3">
      <Filters
        selects={[
          {
            key: 'action',
            label: 'Action',
            value: action,
            options: (actions.data ?? []).map((name) => ({
              value: name,
              label: name,
            })),
            onChange: (value) => {
              setAction(value);
              // A filtered result set is shorter, so page 4 of the old
              // query is usually past the end of the new one.
              setPage(1);
            },
          },
        ]}
        onClear={() => {
          setAction('');
          setPage(1);
        }}
      />

      <DataTable
        columns={columns}
        rows={data?.entries ?? []}
        rowKey={(entry) => `${entry.created_at}-${entry.action}-${entry.user_id}`}
        isLoading={isPending}
        onRowClick={setSelected}
        empty={
          <EmptyState
            title={action ? 'Nothing matches that filter.' : 'Nothing logged yet.'}
            description={
              action
                ? 'No changes of that kind have been made on this site.'
                : 'Changing a setting or adding a provider key will appear here.'
            }
            bare={Boolean(action)}
          />
        }
      />

      {data?.meta && (
        <Pagination meta={data.meta} onChange={setPage} noun="entry" />
      )}

      <Drawer
        open={selected !== null}
        onClose={() => setSelected(null)}
        title={selected?.action ?? ''}
        {...(selected
          ? { subtitle: `${selected.user} · ${selected.created_at} UTC` }
          : {})}
      >
        {selected && (
          <div className="space-y-4">
            {selected.sensitive && (
              <div className="flex items-start gap-2 rounded-lg border border-warning/25 bg-warning/10 px-3 py-2">
                <ShieldAlert
                  size={14}
                  className="mt-px shrink-0 text-warning"
                  aria-hidden="true"
                />
                <p className="text-xs leading-relaxed text-content-secondary">
                  A security-relevant change. Secret values are replaced with
                  <span className="font-mono"> [redacted] </span>
                  before the record is written, so a key is never stored here.
                </p>
              </div>
            )}

            <div>
              <p className="mb-1.5 text-[10px] font-semibold uppercase tracking-[0.12em] text-content-tertiary">
                What changed
              </p>
              <pre className="overflow-x-auto rounded-lg border border-border bg-surface-sunken p-3 font-mono text-xs leading-relaxed text-content-secondary">
                {JSON.stringify(selected.changes, null, 2)}
              </pre>
            </div>
          </div>
        )}
      </Drawer>
    </div>
  );
}
