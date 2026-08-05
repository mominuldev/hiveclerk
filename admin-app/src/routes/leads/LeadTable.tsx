import { useState } from 'react';
import { BAND_TONE, withFilter } from './band';
import { LeadDrawer } from './LeadDrawer';
import { Badge } from '@/components/ui/Badge';
import { DataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { Filters } from '@/components/ui/Filters';
import { Pagination } from '@/components/ui/Pagination';
import { formatTimestamp } from '@/lib/format';
import { useLeads, type LeadFilters } from '@/api/queries/useLeads';

/**
 * The same leads, as rows.
 *
 * The board answers "what is in play"; this answers "who is there", which
 * is the question somebody asks with a filter and a sort rather than by
 * scanning columns.
 */
export function LeadTable() {
  const [filters, setFilters] = useState<LeadFilters>({
    order_by: 'last_active_at',
    order: 'desc',
    per_page: 25,
  });
  const [open, setOpen] = useState<string | null>(null);

  const leads = useLeads(filters);

  if (leads.isError) {
    return <ErrorNotice error={leads.error} onRetry={() => void leads.refetch()} />;
  }

  const rows = leads.data?.leads ?? [];

  return (
    <div className="space-y-4">
      <Filters
        search={{
          value: filters.search ?? '',
          onChange: (search) =>
            setFilters((current) => ({
              ...withFilter(current, 'search', search),
              page: 1,
            })),
          placeholder: 'Search name, email or company',
        }}
        selects={[
          {
            key: 'band',
            label: 'Band',
            value: filters.band ?? '',
            onChange: (band) =>
              setFilters((current) => ({
                ...withFilter(current, 'band', band),
                page: 1,
              })),
            options: [
              { value: '', label: 'Any band' },
              { value: 'qualified', label: 'Qualified' },
              { value: 'hot', label: 'Hot' },
              { value: 'warm', label: 'Warm' },
              { value: 'cold', label: 'Cold' },
            ],
          },
          {
            key: 'status',
            label: 'Status',
            value: filters.status ?? '',
            onChange: (status) =>
              setFilters((current) => ({
                ...withFilter(current, 'status', status),
                page: 1,
              })),
            options: [
              { value: '', label: 'Any status' },
              { value: 'new', label: 'New' },
              { value: 'contacted', label: 'Contacted' },
              { value: 'qualified', label: 'Qualified' },
              { value: 'unqualified', label: 'Unqualified' },
              { value: 'converted', label: 'Converted' },
              { value: 'lost', label: 'Lost' },
            ],
          },
        ]}
        onClear={() =>
          setFilters({ order_by: 'last_active_at', order: 'desc', per_page: 25 })
        }
      />

      {!leads.isPending && rows.length === 0 ? (
        <EmptyState
          title="No leads yet"
          description="A clerk with lead capture turned on will start filling this in. Turn it on from a clerk's Leads tab."
        />
      ) : (
        <>
          <DataTable
            isLoading={leads.isPending}
            rows={rows}
            rowKey={(lead) => lead.uuid}
            onRowClick={(lead) => setOpen(lead.uuid)}
            columns={[
              {
                key: 'name',
                header: 'Name',
                render: (lead) => (
                  <div className="min-w-0">
                    <p className="truncate font-medium text-content">
                      {lead.name}
                    </p>
                    {lead.company && (
                      <p className="truncate text-xs text-content-secondary">
                        {lead.company}
                      </p>
                    )}
                  </div>
                ),
              },
              {
                key: 'score',
                header: 'Score',
                numeric: true,
                width: '6rem',
                render: (lead) => lead.score,
              },
              {
                key: 'band',
                header: 'Band',
                width: '8rem',
                render: (lead) => (
                  <Badge tone={BAND_TONE[lead.band]}>{lead.band_label}</Badge>
                ),
              },
              {
                key: 'stage',
                header: 'Stage',
                render: (lead) => lead.stage ?? '—',
              },
              {
                key: 'status',
                header: 'Status',
                secondary: true,
                render: (lead) => lead.status_label,
              },
              {
                key: 'active',
                header: 'Last active',
                secondary: true,
                render: (lead) =>
                  lead.last_active_at
                    ? formatTimestamp(lead.last_active_at)
                    : '—',
              },
            ]}
          />

          <Pagination
            meta={{
              page: filters.page ?? 1,
              per_page: filters.per_page ?? 25,
              total: leads.data?.total ?? 0,
              total_pages: leads.data?.totalPages ?? 1,
            }}
            noun="lead"
            onChange={(page) => setFilters((current) => ({ ...current, page }))}
          />
        </>
      )}

      <LeadDrawer uuid={open} onClose={() => setOpen(null)} />
    </div>
  );
}
