import {
  useMutation,
  useQuery,
  useQueryClient,
  type UseQueryResult,
} from '@tanstack/react-query';
import { api, type ApiEnvelope, type ApiError } from '@/api/client';

export interface AgentBudget {
  tokens: number | null;
  used: number;
  ratio: number;
  exhausted: boolean;
  blocking: boolean;
  warning: boolean;
  on_exhausted: 'fallback' | 'continue';
  resets_at: string;
  estimated_cost: number | null;
  estimated_basis: 'unpriced' | 'published_rates';
}

export interface AgentStats {
  days: number;
  conversations: number;
  resolved: number;
  handoffs: number;
  cost: number;
}

export interface DisplayRules {
  include: string[];
  exclude: string[];
  devices: string[];
  audience: 'everyone' | 'logged_in' | 'logged_out';
  roles: string[];
  countries: string[];
}

export interface AgentSummary {
  uuid: string;
  name: string;
  slug: string;
  role: string;
  role_label: string;
  status: 'draft' | 'published' | 'paused' | 'archived';
  status_label: string;
  avatar_url: string | null;
  source_count: number;
  budget: AgentBudget;
  created_at: string | null;
  stats: AgentStats | null;
}

export interface AgentDetail extends AgentSummary {
  greeting: string | null;
  token_budget: number | null;
  fallback_message: string | null;
  instructions: string | null;
  model_config: Record<string, unknown>;
  guardrails: Record<string, unknown>;
  personality: Record<string, unknown>;
  display_rules: DisplayRules;
  appears: 'everywhere' | 'rules';
  widget_config: Record<string, unknown>;
  lead_config: Record<string, unknown>;
  source_ids: number[];
  source_uuids: string[];
  sources: Array<{
    id: number;
    uuid: string;
    name: string;
    type_label: string;
    chunk_count: number;
  }>;
  blockers: string[];
  can_publish: boolean;
}

export interface RolePreset {
  key: string;
  label: string;
  summary: string;
  instructions: string;
  greeting: string;
  fallback: string;
  guardrails: Record<string, unknown>;
  personality: Record<string, unknown>;
  needs_knowledge: boolean;
}

export interface TestCitation {
  chunk_id: number | null;
  document_id: number | null;
  title: string;
  url: string | null;
  heading_path: string;
  excerpt: string;
  score: number;
  confident: boolean;
}

export interface TestDiagnostics {
  retrieval_ms: number;
  chunks_found: number;
  completion_ms: number;
  tokens_in?: number;
  tokens_out?: number;
  cost?: number | null;
  grounded?: boolean;
  blocked?: boolean;
  guardrails_triggered?: string[];
  model?: string;
  provider?: string;
  finish_reason?: string;
  prompt_tokens_est?: number;
  dropped_chunks?: number;
  dropped_turns?: number;
  prompt_preview?: string;
  refused_because?: string;
}

export interface TestResult {
  reply: string;
  citations: TestCitation[];
  error: string | null;
  diagnostics: TestDiagnostics;
}

export interface AgentFilters {
  status?: string;
  role?: string;
  search?: string;
}

export type AgentInput = Partial<{
  name: string;
  role_preset: string;
  avatar_url: string;
  greeting: string;
  fallback_message: string;
  instructions: string;
  token_budget: number;
  model_config: Record<string, unknown>;
  guardrails: Record<string, unknown>;
  personality: Record<string, unknown>;
  display_rules: Partial<DisplayRules>;
  widget_config: Record<string, unknown>;
  lead_config: Record<string, unknown>;
  source_ids: number[];
  source_uuids: string[];
}>;

const ROSTER_KEY = ['agents', 'roster'] as const;

function agentKey(uuid: string) {
  return ['agents', 'detail', uuid] as const;
}

/**
 * The roster.
 *
 * Kept warm across screens: the rail in the sidebar renders it on every
 * page, so a short stale time here would mean a request on every
 * navigation for a list that changes when somebody edits a clerk.
 */
export function useAgents(
  filters: AgentFilters = {}
): UseQueryResult<{ agents: AgentSummary[]; total: number }, ApiError> {
  return useQuery({
    queryKey: [...ROSTER_KEY, filters],
    queryFn: async () => {
      const envelope: ApiEnvelope<AgentSummary[]> = await api.get<AgentSummary[]>(
        'admin/agents',
        { per_page: 100, ...filters }
      );

      return {
        agents: envelope.data,
        total: envelope.meta?.pagination?.total ?? envelope.data.length,
      };
    },
    staleTime: 60_000,
  });
}

export function useAgent(uuid: string | null) {
  return useQuery({
    queryKey: agentKey(uuid ?? ''),
    queryFn: async () => (await api.get<AgentDetail>(`admin/agents/${uuid}`)).data,
    enabled: uuid !== null && uuid !== '',
  });
}

/**
 * Role presets and what the licence allows.
 *
 * Cached hard. The presets are shipped constants, and the licence tier
 * changes about once in a site's life.
 */
export function usePresets() {
  return useQuery({
    queryKey: ['agents', 'presets'],
    queryFn: async () =>
      (
        await api.get<{
          presets: RolePreset[];
          licence: { tier: string; limit: number | null; published: number };
        }>('admin/agents/presets')
      ).data,
    staleTime: 10 * 60_000,
  });
}

export function useCreateAgent() {
  const client = useQueryClient();

  return useMutation<AgentDetail, ApiError, AgentInput>({
    mutationFn: async (input) =>
      (await api.post<AgentDetail>('admin/agents', input)).data,
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: ROSTER_KEY });
    },
  });
}

export function useUpdateAgent(uuid: string) {
  const client = useQueryClient();

  return useMutation<AgentDetail, ApiError, AgentInput>({
    mutationFn: async (input) =>
      (await api.patch<AgentDetail>(`admin/agents/${uuid}`, input)).data,
    onSuccess: (agent) => {
      // The response is the whole clerk, so it seeds the detail cache
      // rather than triggering a refetch of what we already hold.
      client.setQueryData(agentKey(uuid), agent);
      void client.invalidateQueries({ queryKey: ROSTER_KEY });
    },
  });
}

type LifecycleAction = 'publish' | 'pause' | 'duplicate';

export function useAgentAction(uuid: string, action: LifecycleAction) {
  const client = useQueryClient();

  return useMutation<AgentDetail, ApiError, void>({
    mutationFn: async () =>
      (await api.post<AgentDetail>(`admin/agents/${uuid}/${action}`)).data,
    onSuccess: (agent) => {
      if (action !== 'duplicate') {
        client.setQueryData(agentKey(uuid), agent);
      }

      void client.invalidateQueries({ queryKey: ROSTER_KEY });
    },
  });
}

export function useDeleteAgent() {
  const client = useQueryClient();

  return useMutation<void, ApiError, string>({
    mutationFn: async (uuid) => {
      await api.delete<{ deleted: boolean }>(`admin/agents/${uuid}`);
    },
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: ROSTER_KEY });
    },
  });
}

export interface TestInput {
  message: string;
  history: Array<{ role: 'visitor' | 'assistant'; content: string }>;
}

/**
 * One run of the test console.
 *
 * Deliberately a mutation, not a query. Every run spends the customer's
 * money, and React Query retries and refetches queries on its own.
 */
export function useTestAgent(uuid: string) {
  return useMutation<TestResult, ApiError, TestInput>({
    mutationFn: async (input) =>
      (await api.post<TestResult>(`admin/agents/${uuid}/test`, input)).data,
  });
}
