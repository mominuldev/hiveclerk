import {
  useMutation,
  useQuery,
  useQueryClient,
  type UseQueryResult,
} from '@tanstack/react-query';
import { api, type ApiError } from '@/api/client';

export type WorkflowStatus = 'draft' | 'active' | 'paused' | 'archived';
export type NodeType = 'trigger' | 'condition' | 'delay' | 'action';
export type RunStatus =
  | 'pending'
  | 'waiting'
  | 'completed'
  | 'failed'
  | 'cancelled';

/**
 * A node exactly as it is stored.
 *
 * Edges live on the node rather than in a separate list, which is what
 * makes deleting a step a local edit instead of a scan for orphaned
 * edges. `next` is for everything that continues; `yes` and `no` are for
 * conditions.
 */
export interface WorkflowNode {
  type: NodeType;
  config: Record<string, unknown>;
  next: string | null;
  yes: string | null;
  no: string | null;
}

export type WorkflowGraph = Record<string, WorkflowNode>;

export interface Blocker {
  node: string | null;
  message: string;
}

export interface WorkflowSummary {
  uuid: string;
  name: string;
  status: WorkflowStatus;
  status_label: string;
  trigger: string;
  trigger_label: string;
  steps: number;
  runs_once: boolean;
  run_count: number;
  last_run_at: string | null;
  next_run_at: string | null;
  runs: { waiting: number; completed: number; failed: number };
  created_at: string | null;
}

export interface WorkflowDetail extends WorkflowSummary {
  graph: WorkflowGraph;
  trigger_config: Record<string, unknown>;
  interval: number;
  segment: Record<string, unknown>;
  blockers: Blocker[];
  can_activate: boolean;
}

export interface WorkflowTemplate {
  id: string;
  name: string;
  description: string;
  trigger: string;
}

export interface TriggerOption {
  value: string;
  label: string;
  description: string;
  subject: 'lead' | 'conversation';
  needs_stage: boolean;
  scheduled: boolean;
}

export interface ActionOption {
  value: string;
  label: string;
  description: string;
  available: boolean;
  subjects: string[];
  outbound: boolean;
}

export interface FieldOption {
  value: string;
  label: string;
  numeric: boolean;
  needs_key: boolean;
}

export interface OperatorOption {
  value: string;
  label: string;
  needs_value: boolean;
  numeric: boolean;
}

export interface Vocabulary {
  triggers: TriggerOption[];
  actions: ActionOption[];
  fields: FieldOption[];
  operators: OperatorOption[];
  stages: Array<{ id: number; name: string }>;
  sequences: Array<{ uuid: string; name: string; active: boolean }>;
  tags: Array<{ tag: string; key: string; description: string }>;
  max_nodes: number;
  min_interval: number;
}

export interface RunSummary {
  id: number | null;
  status: RunStatus;
  status_label: string;
  subject_type: string;
  subject_name: string | null;
  current_node: string | null;
  steps: number;
  error: string | null;
  resume_at: string | null;
  started_at: string | null;
  finished_at: string | null;
}

export interface RunLogLine {
  node: string;
  node_type: NodeType;
  outcome: string;
  label: string;
  detail: string | null;
  created_at: string | null;
}

export interface TraceStep {
  node: string;
  type: NodeType;
  outcome: string;
  detail: string;
}

const LIST_KEY = ['workflows'] as const;
const VOCABULARY_KEY = ['workflows', 'vocabulary'] as const;

function detailKey(uuid: string) {
  return ['workflows', 'workflow', uuid] as const;
}

function runsKey(uuid: string) {
  return ['workflows', 'runs', uuid] as const;
}

export function useWorkflows(): UseQueryResult<
  {
    workflows: WorkflowSummary[];
    templates: WorkflowTemplate[];
    entitled: boolean;
    total: number;
  },
  ApiError
> {
  return useQuery({
    queryKey: LIST_KEY,
    queryFn: async () =>
      (
        await api.get<{
          workflows: WorkflowSummary[];
          templates: WorkflowTemplate[];
          entitled: boolean;
          total: number;
        }>('admin/workflows', { per_page: 50 })
      ).data,
  });
}

/**
 * Everything the builder needs before it can draw a node.
 *
 * One request, and a long stale time: stages and sequences change on
 * other screens, not this one, and refetching them on every node edit
 * would put a spinner inside a drag.
 */
export function useWorkflowVocabulary() {
  return useQuery({
    queryKey: VOCABULARY_KEY,
    queryFn: async () =>
      (await api.get<Vocabulary>('admin/workflows/vocabulary')).data,
    staleTime: 5 * 60_000,
  });
}

export function useWorkflow(uuid: string | null) {
  return useQuery({
    queryKey: detailKey(uuid ?? ''),
    queryFn: async () =>
      (await api.get<WorkflowDetail>(`admin/workflows/${uuid}`)).data,
    enabled: uuid !== null && uuid !== '',
  });
}

function useWorkflowInvalidation() {
  const client = useQueryClient();

  return (uuid?: string) => {
    void client.invalidateQueries({ queryKey: LIST_KEY });

    if (uuid) {
      void client.invalidateQueries({ queryKey: detailKey(uuid) });
    }
  };
}

export function useCreateWorkflow() {
  const invalidate = useWorkflowInvalidation();

  return useMutation<
    WorkflowDetail,
    ApiError,
    { name?: string; trigger?: string; template?: string }
  >({
    mutationFn: async (input) =>
      (await api.post<WorkflowDetail>('admin/workflows', input)).data,
    onSuccess: () => invalidate(),
  });
}

export function useUpdateWorkflow(uuid: string) {
  const invalidate = useWorkflowInvalidation();

  return useMutation<
    WorkflowDetail,
    ApiError,
    {
      name?: string;
      trigger?: string;
      trigger_config?: Record<string, unknown>;
      graph?: WorkflowGraph;
      runs_once?: boolean;
    }
  >({
    mutationFn: async (input) =>
      (await api.patch<WorkflowDetail>(`admin/workflows/${uuid}`, input)).data,
    onSuccess: () => invalidate(uuid),
  });
}

export function useDeleteWorkflow() {
  const invalidate = useWorkflowInvalidation();

  return useMutation<
    { deleted: boolean; runs_cancelled: number },
    ApiError,
    string
  >({
    mutationFn: async (uuid) =>
      (
        await api.delete<{ deleted: boolean; runs_cancelled: number }>(
          `admin/workflows/${uuid}`
        )
      ).data,
    onSuccess: () => invalidate(),
  });
}

/**
 * Activate or pause.
 *
 * Activation fails with a 422 naming the step that is not ready, and that
 * message is the whole value of the gate — callers show it rather than a
 * generic failure.
 */
export function useWorkflowStatus(uuid: string) {
  const invalidate = useWorkflowInvalidation();

  return useMutation<WorkflowDetail, ApiError, 'activate' | 'pause'>({
    mutationFn: async (action) =>
      (await api.post<WorkflowDetail>(`admin/workflows/${uuid}/${action}`)).data,
    onSuccess: () => invalidate(uuid),
  });
}

/** A dry run. Conditions are evaluated for real; nothing is performed. */
export function useTestWorkflow(uuid: string) {
  return useMutation<
    {
      trace: TraceStep[];
      lead: { uuid: string; name: string } | null;
      executed: boolean;
    },
    ApiError,
    { lead?: string }
  >({
    mutationFn: async (input) =>
      (
        await api.post<{
          trace: TraceStep[];
          lead: { uuid: string; name: string } | null;
          executed: boolean;
        }>(`admin/workflows/${uuid}/test`, input)
      ).data,
  });
}

export function useWorkflowRuns(
  uuid: string,
  filters: { status?: string; page?: number }
) {
  return useQuery({
    queryKey: [...runsKey(uuid), filters],
    queryFn: async () => {
      const envelope = await api.get<RunSummary[]>(
        `admin/workflows/${uuid}/runs`,
        { per_page: 25, ...filters }
      );

      return {
        rows: envelope.data,
        total: envelope.meta?.pagination?.total ?? envelope.data.length,
        totalPages: envelope.meta?.pagination?.total_pages ?? 1,
      };
    },
    enabled: uuid !== '',
  });
}

export function useWorkflowRun(id: number | null) {
  return useQuery({
    queryKey: ['workflows', 'run', id],
    queryFn: async () =>
      (
        await api.get<{ run: RunSummary; log: RunLogLine[] }>(
          `admin/workflows/runs/${id}`
        )
      ).data,
    enabled: id !== null,
  });
}
