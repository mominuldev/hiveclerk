import { createContext, useContext, useState } from 'react';
import { Outlet, useLocation } from 'react-router-dom';
import { Download } from 'lucide-react';
import { Tabs } from '@/components/ui/Tabs';
import { Button } from '@/components/ui/Button';
import {
  RangePicker,
  rangeParams,
  type RangeDays,
} from '@/components/analytics/RangePicker';
import { downloadReport, type ReportFilters } from '@/api/queries/useAnalytics';

const TABS = [
  { label: 'Overview', to: '/analytics/overview', report: 'overview' },
  { label: 'Clerks', to: '/analytics/clerks', report: 'agents' },
  { label: 'Funnel', to: '/analytics/funnel', report: 'funnel' },
  { label: 'Topics', to: '/analytics/topics', report: 'topics' },
  { label: 'Costs', to: '/analytics/costs', report: 'overview' },
] as const;

const FiltersContext = createContext<ReportFilters>({});

/**
 * The filters every tab in this area shares.
 *
 * Context rather than a store: the range is scoped to this area and dies
 * with it, and a Zustand slice would keep a 90-day window selected on a
 * screen the operator opens next week expecting today's figures.
 */
export function useReportFilters(): ReportFilters {
  return useContext(FiltersContext);
}

/**
 * The analytics area (D11 §10).
 *
 * One range control above five tabs rather than one per tab, because the
 * question an operator is asking does not change when they move from the
 * funnel to costs — only the view of it does.
 */
export function AnalyticsShell() {
  const [days, setDays] = useState<RangeDays>(30);
  const [agent, setAgent] = useState('all');
  const location = useLocation();

  const filters: ReportFilters = { ...rangeParams(days), agent };
  const current = TABS.find((tab) => location.pathname.startsWith(tab.to));

  return (
    <FiltersContext.Provider value={filters}>
      <div className="space-y-5">
        <header className="flex flex-wrap items-center justify-between gap-3">
          <h1 className="font-display text-xl font-bold tracking-[-0.02em] text-content">
            Analytics
          </h1>

          <div className="flex items-center gap-2">
            <RangePicker
              days={days}
              onDays={setDays}
              agent={agent}
              onAgent={setAgent}
            />
            <Button
              size="md"
              icon={<Download size={14} aria-hidden="true" />}
              onClick={() => {
                void downloadReport(current?.report ?? 'overview', filters);
              }}
            >
              Export CSV
            </Button>
          </div>
        </header>

        <div className="border-b border-border">
          <Tabs items={TABS.map(({ label, to }) => ({ label, to }))} />
        </div>

        <Outlet />
      </div>
    </FiltersContext.Provider>
  );
}
