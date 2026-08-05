import {
  useMutation,
  useQuery,
  useQueryClient,
  type UseQueryResult,
} from '@tanstack/react-query';
import { api, type ApiError } from '@/api/client';

export interface LeadStage {
  id: number;
  name: string;
  slug: string;
  color: string | null;
  position: number;
  is_won: boolean;
  is_lost: boolean;
}

export type ScoreBand = 'cold' | 'warm' | 'hot' | 'qualified';

export interface LeadSummary {
  uuid: string;
  name: string;
  first_name: string | null;
  last_name: string | null;
  email: string | null;
  phone: string | null;
  company: string | null;
  job_title: string | null;
  website: string | null;
  score: number;
  band: ScoreBand;
  band_label: string;
  status: string;
  status_label: string;
  stage_id: number | null;
  stage: string | null;
  source: string | null;
  owner_user_id: number | null;
  custom_fields: Record<string, string>;
  first_seen_at: string | null;
  last_active_at: string | null;
  created_at: string | null;
}

export interface ScoreLine {
  source: 'rule' | 'ai' | 'manual';
  label: string;
  points: number;
  rationale: string | null;
  created_at: string | null;
}

export interface TimelineEntry {
  id: number | null;
  type: string;
  title: string;
  body: string | null;
  url: string | null;
  user_id: number | null;
  metadata: Record<string, unknown>;
  created_at: string | null;
}

export interface LeadConversation {
  uuid: string;
  started_at: string | null;
  message_count: number;
  page_url: string | null;
  status: string;
}

export interface LeadDetail extends LeadSummary {
  ceiling: number;
  breakdown: ScoreLine[];
  consent: Record<string, unknown>;
  conversations: LeadConversation[];
  timeline: TimelineEntry[];
}

export interface LeadFilters {
  stage_id?: number | 'none';
  status?: string;
  band?: string;
  source?: string;
  search?: string;
  min_score?: number;
  order_by?: string;
  order?: 'asc' | 'desc';
  page?: number;
  per_page?: number;
}

export interface ScoringRule {
  id: string;
  label: string;
  kind: 'field' | 'keyword' | 'page' | 'engagement';
  operator: string;
  points: number;
  target: string;
  value: string;
  enabled: boolean;
  once: boolean;
}

export interface RuleVocabulary {
  value: string;
  label: string;
  operators: Array<{ value: string; label: string; needs_value: boolean }>;
  targets: string[];
}

export interface ScoringPolicy {
  rules: ScoringRule[];
  bands: { warm: number; hot: number; qualified: number };
  alerts: {
    enabled: boolean;
    score: number;
    emails: string[];
    slack_webhook: string | null;
  };
  ceiling: number;
  customised: boolean;
  kinds: RuleVocabulary[];
  max_rules: number;
}

const LIST_KEY = ['leads', 'list'] as const;
const STAGES_KEY = ['leads', 'stages'] as const;
const RULES_KEY = ['leads', 'rules'] as const;

function detailKey(uuid: string) {
  return ['leads', 'detail', uuid] as const;
}

/**
 * The board and the table read the same endpoint.
 *
 * One request, not one per column. The counts come back for every stage
 * regardless of what this page holds, because a column header showing the
 * number on the current page would say "New 25" on a column with four
 * hundred people in it.
 */
export function useLeads(
  filters: LeadFilters = {}
): UseQueryResult<
  {
    leads: LeadSummary[];
    stages: LeadStage[];
    counts: Record<string, number>;
    total: number;
    totalPages: number;
  },
  ApiError
> {
  return useQuery({
    queryKey: [...LIST_KEY, filters],
    queryFn: async () => {
      const envelope = await api.get<{
        leads: LeadSummary[];
        stages: LeadStage[];
        counts: Record<string, number>;
      }>('admin/leads', { per_page: 100, ...filters });

      return {
        leads: envelope.data.leads,
        stages: envelope.data.stages,
        counts: envelope.data.counts,
        total: envelope.meta?.pagination?.total ?? envelope.data.leads.length,
        totalPages: envelope.meta?.pagination?.total_pages ?? 1,
      };
    },
  });
}

export function useLead(uuid: string | null) {
  return useQuery({
    queryKey: detailKey(uuid ?? ''),
    queryFn: async () => (await api.get<LeadDetail>(`admin/leads/${uuid}`)).data,
    enabled: uuid !== null && uuid !== '',
  });
}

function useLeadInvalidation() {
  const client = useQueryClient();

  return (uuid?: string) => {
    void client.invalidateQueries({ queryKey: LIST_KEY });

    if (uuid) {
      void client.invalidateQueries({ queryKey: detailKey(uuid) });
    }
  };
}

export function useMoveLead() {
  const invalidate = useLeadInvalidation();

  return useMutation<
    LeadDetail,
    ApiError,
    { uuid: string; stageId: number | null }
  >({
    mutationFn: async ({ uuid, stageId }) =>
      (
        await api.post<LeadDetail>(`admin/leads/${uuid}/stage`, {
          stage_id: stageId ?? 0,
        })
      ).data,
    onSuccess: (_data, { uuid }) => invalidate(uuid),
  });
}

export function useUpdateLead(uuid: string) {
  const invalidate = useLeadInvalidation();

  return useMutation<LeadDetail, ApiError, Partial<LeadSummary>>({
    mutationFn: async (input) =>
      (await api.patch<LeadDetail>(`admin/leads/${uuid}`, input)).data,
    onSuccess: () => invalidate(uuid),
  });
}

export function useAdjustScore(uuid: string) {
  const invalidate = useLeadInvalidation();

  return useMutation<
    { score: number; band: ScoreBand; breakdown: ScoreLine[] },
    ApiError,
    { points: number; reason?: string }
  >({
    mutationFn: async (input) =>
      (
        await api.post<{
          score: number;
          band: ScoreBand;
          breakdown: ScoreLine[];
        }>(`admin/leads/${uuid}/score/adjust`, input)
      ).data,
    onSuccess: () => invalidate(uuid),
  });
}

export function useLeadNote(uuid: string) {
  const invalidate = useLeadInvalidation();

  return useMutation<TimelineEntry, ApiError, { body: string }>({
    mutationFn: async (input) =>
      (await api.post<TimelineEntry>(`admin/leads/${uuid}/notes`, input)).data,
    onSuccess: () => invalidate(uuid),
  });
}

export function useDeleteLead() {
  const invalidate = useLeadInvalidation();

  return useMutation<void, ApiError, string>({
    mutationFn: async (uuid) => {
      await api.delete<{ deleted: boolean }>(`admin/leads/${uuid}`);
    },
    onSuccess: () => invalidate(),
  });
}

export function useMergeLeads() {
  const invalidate = useLeadInvalidation();

  return useMutation<
    LeadDetail,
    ApiError,
    { winner: string; loser: string }
  >({
    mutationFn: async (input) =>
      (await api.post<LeadDetail>('admin/leads/merge', input)).data,
    onSuccess: (_data, { winner }) => invalidate(winner),
  });
}

export function useStages() {
  return useQuery({
    queryKey: STAGES_KEY,
    queryFn: async () =>
      (
        await api.get<{
          stages: LeadStage[];
          counts: Record<string, number>;
        }>('admin/leads/stages')
      ).data,
    staleTime: 60_000,
  });
}

function useStageInvalidation() {
  const client = useQueryClient();

  return () => {
    void client.invalidateQueries({ queryKey: STAGES_KEY });
    void client.invalidateQueries({ queryKey: LIST_KEY });
  };
}

export function useCreateStage() {
  const invalidate = useStageInvalidation();

  return useMutation<LeadStage, ApiError, Partial<LeadStage>>({
    mutationFn: async (input) =>
      (await api.post<LeadStage>('admin/leads/stages', input)).data,
    onSuccess: invalidate,
  });
}

export function useUpdateStage() {
  const invalidate = useStageInvalidation();

  return useMutation<
    LeadStage,
    ApiError,
    { id: number; changes: Partial<LeadStage> }
  >({
    mutationFn: async ({ id, changes }) =>
      (await api.patch<LeadStage>(`admin/leads/stages/${id}`, changes)).data,
    onSuccess: invalidate,
  });
}

export function useDeleteStage() {
  const invalidate = useStageInvalidation();

  return useMutation<
    { deleted: boolean; leads_moved: number },
    ApiError,
    { id: number; moveTo: number | null }
  >({
    mutationFn: async ({ id, moveTo }) =>
      (
        await api.delete<{ deleted: boolean; leads_moved: number }>(
          `admin/leads/stages/${id}${moveTo ? `?move_to=${moveTo}` : ''}`
        )
      ).data,
    onSuccess: invalidate,
  });
}

export function useReorderStages() {
  const invalidate = useStageInvalidation();

  return useMutation<LeadStage[], ApiError, number[]>({
    mutationFn: async (order) =>
      (await api.post<LeadStage[]>('admin/leads/stages/reorder', { order }))
        .data,
    onSuccess: invalidate,
  });
}

export function useScoringPolicy() {
  return useQuery({
    queryKey: RULES_KEY,
    queryFn: async () =>
      (await api.get<ScoringPolicy>('admin/leads/scoring-rules')).data,
    staleTime: 60_000,
  });
}

export function useSaveScoringPolicy() {
  const client = useQueryClient();

  return useMutation<
    ScoringPolicy,
    ApiError,
    {
      rules?: ScoringRule[];
      bands?: ScoringPolicy['bands'];
      alerts?: ScoringPolicy['alerts'];
    }
  >({
    mutationFn: async (input) =>
      (await api.put<ScoringPolicy>('admin/leads/scoring-rules', input)).data,
    onSuccess: (data) => {
      client.setQueryData(RULES_KEY, data);
      void client.invalidateQueries({ queryKey: LIST_KEY });
    },
  });
}

/**
 * Export, as a file the browser builds.
 *
 * The admin authenticates with a cookie plus a nonce header, and a plain
 * download link carries neither. Rather than design a second auth
 * mechanism for one button, the CSV comes back as text and the browser
 * makes the file.
 */
export function useExportLeads() {
  return useMutation<
    { filename: string; csv: string; rows: number; total: number; truncated: boolean },
    ApiError,
    LeadFilters
  >({
    mutationFn: async (filters) =>
      (
        await api.get<{
          filename: string;
          csv: string;
          rows: number;
          total: number;
          truncated: boolean;
        }>('admin/leads/export', { ...filters })
      ).data,
  });
}
