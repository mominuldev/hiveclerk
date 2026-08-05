import {
  useMutation,
  useQuery,
  useQueryClient,
  type UseQueryResult,
} from '@tanstack/react-query';
import { api, type ApiError } from '@/api/client';

export type SequenceStatus = 'draft' | 'active' | 'paused' | 'archived';

export interface SequenceStep {
  id: number | null;
  position: number;
  delay_minutes: number;
  subject: string;
  body_html: string;
  body_text: string | null;
  ai_generated: boolean;
  approved_by: number | null;
  approved_at: string | null;
  sendable: boolean;
  blocker: string | null;
}

export interface ExitConditionRow {
  type: string;
  value: string | null;
}

export interface Sequence {
  uuid: string;
  name: string;
  status: SequenceStatus;
  status_label: string;
  trigger: string;
  trigger_label: string;
  threshold: number;
  stage_id: number | null;
  abandon_after: number;
  from_name: string | null;
  from_email: string | null;
  reply_to: string | null;
  enrolled: number;
  steps: number;
  exit_conditions: ExitConditionRow[];
  stats: Record<string, number>;
  created_at: string | null;
}

export interface SequenceDetail extends Sequence {
  steps: number;
}

export interface StepBundle {
  steps: SequenceStep[];
  blockers: Array<{ position: number; reason: string }>;
  can_activate: boolean;
}

export interface TriggerOption {
  value: string;
  label: string;
  needs_threshold: boolean;
}

export interface ExitOption {
  value: string;
  label: string;
  needs_value: boolean;
}

export interface MergeTag {
  tag: string;
  key: string;
  description: string;
}

export interface EmailLogRow {
  id: number | null;
  lead_id: number;
  to: string;
  subject: string;
  status: 'queued' | 'sent' | 'failed' | 'suppressed';
  status_label: string;
  error: string | null;
  step_id: number | null;
  sent_at: string | null;
  created_at: string | null;
}

export interface EmailDraft {
  subject: string;
  body_html: string;
  body_text: string;
  goal: string;
}

/** A sequence plus its steps, which is what every write returns. */
type SequenceResponse = Sequence & StepBundle;

const LIST_KEY = ['email', 'sequences'] as const;
const LOG_KEY = ['email', 'log'] as const;

function detailKey(uuid: string) {
  return ['email', 'sequence', uuid] as const;
}

export function useSequences(): UseQueryResult<
  {
    sequences: Sequence[];
    triggers: TriggerOption[];
    exits: ExitOption[];
    merge_tags: MergeTag[];
    suppressed: number;
    max_steps: number;
  },
  ApiError
> {
  return useQuery({
    queryKey: LIST_KEY,
    queryFn: async () =>
      (
        await api.get<{
          sequences: Sequence[];
          triggers: TriggerOption[];
          exits: ExitOption[];
          merge_tags: MergeTag[];
          suppressed: number;
          max_steps: number;
        }>('admin/email/sequences', { per_page: 50 })
      ).data,
  });
}

export function useSequence(uuid: string | null) {
  return useQuery({
    queryKey: detailKey(uuid ?? ''),
    queryFn: async () =>
      (await api.get<SequenceResponse>(`admin/email/sequences/${uuid}`)).data,
    enabled: uuid !== null && uuid !== '',
  });
}

function useSequenceInvalidation() {
  const client = useQueryClient();

  return (uuid?: string) => {
    void client.invalidateQueries({ queryKey: LIST_KEY });

    if (uuid) {
      void client.invalidateQueries({ queryKey: detailKey(uuid) });
    }
  };
}

export function useCreateSequence() {
  const invalidate = useSequenceInvalidation();

  return useMutation<SequenceResponse, ApiError, Partial<Sequence>>({
    mutationFn: async (input) =>
      (await api.post<SequenceResponse>('admin/email/sequences', input)).data,
    onSuccess: () => invalidate(),
  });
}

export function useUpdateSequence(uuid: string) {
  const invalidate = useSequenceInvalidation();

  return useMutation<SequenceResponse, ApiError, Partial<Sequence>>({
    mutationFn: async (input) =>
      (await api.patch<SequenceResponse>(`admin/email/sequences/${uuid}`, input))
        .data,
    onSuccess: () => invalidate(uuid),
  });
}

export function useDeleteSequence() {
  const invalidate = useSequenceInvalidation();

  return useMutation<{ deleted: boolean }, ApiError, string>({
    mutationFn: async (uuid) =>
      (await api.delete<{ deleted: boolean }>(`admin/email/sequences/${uuid}`))
        .data,
    onSuccess: () => invalidate(),
  });
}

/**
 * Activate or pause.
 *
 * Activation can fail with a 422 naming the step that is not ready, and
 * that message is the whole value of the gate — the caller shows it
 * rather than a generic failure.
 */
export function useSequenceStatus(uuid: string) {
  const invalidate = useSequenceInvalidation();

  return useMutation<SequenceResponse, ApiError, 'activate' | 'pause'>({
    mutationFn: async (action) =>
      (
        await api.post<SequenceResponse>(
          `admin/email/sequences/${uuid}/${action}`
        )
      ).data,
    onSuccess: () => invalidate(uuid),
  });
}

export function useCreateStep(uuid: string) {
  const invalidate = useSequenceInvalidation();

  return useMutation<SequenceStep, ApiError, Partial<SequenceStep>>({
    mutationFn: async (input) =>
      (
        await api.post<SequenceStep>(
          `admin/email/sequences/${uuid}/steps`,
          input
        )
      ).data,
    onSuccess: () => invalidate(uuid),
  });
}

export function useUpdateStep(uuid: string) {
  const invalidate = useSequenceInvalidation();

  return useMutation<
    SequenceStep,
    ApiError,
    { id: number; changes: Partial<SequenceStep> }
  >({
    mutationFn: async ({ id, changes }) =>
      (await api.patch<SequenceStep>(`admin/email/steps/${id}`, changes)).data,
    onSuccess: () => invalidate(uuid),
  });
}

export function useDeleteStep(uuid: string) {
  const invalidate = useSequenceInvalidation();

  return useMutation<StepBundle, ApiError, number>({
    mutationFn: async (id) =>
      (await api.delete<StepBundle>(`admin/email/steps/${id}`)).data,
    onSuccess: () => invalidate(uuid),
  });
}

/**
 * Ask a model for copy.
 *
 * Returns a draft. Nothing is saved until the operator presses save, and
 * saving marks the step AI-generated so it still cannot send until it has
 * been approved.
 */
export function useGenerateCopy() {
  return useMutation<EmailDraft, ApiError, { id: number; goal: string }>({
    mutationFn: async ({ id, goal }) =>
      (await api.post<EmailDraft>(`admin/email/steps/${id}/generate`, { goal }))
        .data,
  });
}

export function useApproveStep(uuid: string) {
  const invalidate = useSequenceInvalidation();

  return useMutation<SequenceStep, ApiError, number>({
    mutationFn: async (id) =>
      (await api.post<SequenceStep>(`admin/email/steps/${id}/approve`)).data,
    onSuccess: () => invalidate(uuid),
  });
}

export function usePreviewStep() {
  return useMutation<
    { subject: string; html: string; text: string; to: string; sample: boolean },
    ApiError,
    number
  >({
    mutationFn: async (id) =>
      (
        await api.post<{
          subject: string;
          html: string;
          text: string;
          to: string;
          sample: boolean;
        }>(`admin/email/steps/${id}/preview`)
      ).data,
  });
}

export function useEmailLog(filters: { status?: string; page?: number }) {
  return useQuery({
    queryKey: [...LOG_KEY, filters],
    queryFn: async () => {
      const envelope = await api.get<EmailLogRow[]>('admin/email/log', {
        per_page: 25,
        ...filters,
      });

      return {
        rows: envelope.data,
        total: envelope.meta?.pagination?.total ?? envelope.data.length,
        totalPages: envelope.meta?.pagination?.total_pages ?? 1,
      };
    },
  });
}
