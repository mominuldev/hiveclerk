import { useQuery } from '@tanstack/react-query';
import { api } from '@/api/client';

export interface Kpi {
  key: string;
  label: string;
  value: number;
  previous: number | null;
  /** Null when the previous period had nothing to compare against. */
  change: number | null;
  series: number[];
  format: 'number' | 'currency' | 'percent';
  higher_is_better: boolean;
}

export interface DailyMetrics {
  date: string;
  conversations: number;
  messages: number;
  unique_visitors: number;
  leads_captured: number;
  leads_qualified: number;
  handoffs: number;
  resolved_by_ai: number;
  positive_ratings: number;
  negative_ratings: number;
  unanswered: number;
  tokens_in: number;
  tokens_out: number;
  cost: number;
  avg_latency_ms: number | null;
}

export interface Alert {
  kind: string;
  title: string;
  detail: string | null;
  href: string;
  severity: 'info' | 'warning' | 'urgent';
  count: number;
}

export interface Topic {
  label: string;
  count: number;
}

export interface DateRange {
  from: string;
  to: string;
}

export interface OverviewResponse {
  range: DateRange;
  compare: DateRange | null;
  kpis: Kpi[];
  series: DailyMetrics[];
  totals: DailyMetrics & {
    deflection_rate: number | null;
    cost_per_conversation: number | null;
  };
  top_topics: Topic[];
  alerts: Alert[];
}

export interface AgentReportRow {
  /**
   * `status` is the stored lifecycle value, not a DutyStatus. Typed as the
   * union rather than `string` so passing it somewhere expecting duty
   * states is a compile error — it was `string`, and the cast that silenced
   * the mismatch reached the browser as a blank screen.
   */
  agent: {
    id: number;
    uuid: string;
    name: string;
    status: 'draft' | 'published' | 'paused' | 'archived';
  };
  conversations: number;
  messages: number;
  leads_captured: number;
  leads_qualified: number;
  handoffs: number;
  deflection_rate: number | null;
  cost: number;
  avg_latency_ms: number | null;
  positive: number;
  negative: number;
}

export interface FunnelStep {
  key: string;
  label: string;
  count: number;
  rate: number | null;
  drop_off: number;
}

export interface FunnelResponse {
  range: DateRange;
  steps: FunnelStep[];
  finding: { text: string; step: string } | null;
}

export interface TopicsResponse {
  range: DateRange;
  topics: Topic[];
  sampled: boolean;
  of: number;
}

export interface ReportFilters {
  from?: string;
  to?: string;
  /** A clerk uuid, or 'all'. */
  agent?: string;
}

/**
 * Query parameters, with empties dropped.
 *
 * An `agent=` with no value would be sent, reach the server, fail
 * validation and 422 the whole dashboard — so the filter object is
 * pruned rather than passed through.
 */
function params(filters: ReportFilters): Record<string, string> {
  const query: Record<string, string> = {};

  if (filters.from) query.from = filters.from;
  if (filters.to) query.to = filters.to;
  if (filters.agent && filters.agent !== 'all') query.agent = filters.agent;

  return query;
}

/**
 * The dashboard: KPIs, the volume series, top questions and the queue.
 *
 * One request, because the dashboard renders all of it at once. Five
 * queries would be five chances for the screen to assemble itself in
 * front of the reader.
 */
export function useOverview(filters: ReportFilters = {}) {
  return useQuery({
    queryKey: ['analytics', 'overview', filters],
    queryFn: async () =>
      (await api.get<OverviewResponse>('admin/analytics/overview', params(filters))).data,
    // Matches the rollup's own cadence. Refetching faster would re-read
    // the same stored rows and re-count the same partial day.
    staleTime: 60_000,
  });
}

export function useAgentReport(filters: ReportFilters = {}) {
  return useQuery({
    queryKey: ['analytics', 'agents', filters],
    queryFn: async () =>
      (
        await api.get<{ range: DateRange; agents: AgentReportRow[] }>(
          'admin/analytics/agents',
          params(filters)
        )
      ).data,
    staleTime: 60_000,
  });
}

export function useFunnel(filters: ReportFilters = {}) {
  return useQuery({
    queryKey: ['analytics', 'funnel', filters],
    queryFn: async () =>
      (await api.get<FunnelResponse>('admin/analytics/funnel', params(filters))).data,
    staleTime: 60_000,
  });
}

export function useTopics(filters: ReportFilters = {}) {
  return useQuery({
    queryKey: ['analytics', 'topics', filters],
    queryFn: async () =>
      (await api.get<TopicsResponse>('admin/analytics/topics', params(filters))).data,
    staleTime: 60_000,
  });
}

/**
 * Fetch a report as CSV and hand it to the browser.
 *
 * The file is built here from text the server produced, for the reason
 * given in LeadExporter: a plain download link carries neither the
 * session cookie's nonce nor the REST header, and inventing a second
 * auth mechanism to avoid one Blob is a poor trade.
 */
export async function downloadReport(
  report: string,
  filters: ReportFilters = {}
): Promise<void> {
  const { data } = await api.get<{ filename: string; csv: string; rows: number }>(
    'admin/analytics/export',
    { ...params(filters), report }
  );

  const url = URL.createObjectURL(
    new Blob([data.csv], { type: 'text/csv;charset=utf-8' })
  );

  const link = document.createElement('a');
  link.href = url;
  link.download = data.filename;
  link.click();

  URL.revokeObjectURL(url);
}
