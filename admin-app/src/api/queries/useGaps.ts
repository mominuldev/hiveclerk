import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, type ApiEnvelope } from '@/api/client';

export type GapStatus = 'open' | 'resolved' | 'ignored';

export interface KnowledgeGap {
  id: number;
  question: string;
  occurrences: number;
  best_score: number | null;
  found_nothing: boolean;
  status: GapStatus;
  status_label: string;
  agent: { name: string; uuid: string; threshold: number } | null;
  conversation_id: number | null;
  first_seen_at: string | null;
  last_seen_at: string | null;
}

export interface GapCounts {
  open: number;
  resolved: number;
  ignored: number;
}

interface GapsMeta {
  pagination: { page: number; per_page: number; total: number; total_pages: number };
  counts: GapCounts;
}

export interface GapsPage {
  gaps: KnowledgeGap[];
  total: number;
  totalPages: number;
  counts: GapCounts;
}

const EMPTY_COUNTS: GapCounts = { open: 0, resolved: 0, ignored: 0 };

export function useGaps(status: GapStatus | 'all', page: number, agent?: string) {
  return useQuery({
    queryKey: ['gaps', status, page, agent ?? 'all'],
    queryFn: async (): Promise<GapsPage> => {
      const envelope = (await api.get<KnowledgeGap[]>('admin/knowledge/gaps', {
        status,
        page,
        per_page: 25,
        ...(agent && agent !== 'all' ? { agent } : {}),
      })) as ApiEnvelope<KnowledgeGap[]> & { meta?: GapsMeta };

      return {
        gaps: envelope.data,
        total: envelope.meta?.pagination.total ?? envelope.data.length,
        totalPages: envelope.meta?.pagination.total_pages ?? 1,
        counts: envelope.meta?.counts ?? EMPTY_COUNTS,
      };
    },
  });
}

interface AnswerResult {
  gap: KnowledgeGap;
  source: { uuid: string; name: string };
  indexing: boolean;
}

/**
 * Write an answer, index it, close the gap.
 *
 * Invalidates the knowledge sources as well as the gaps: the answer
 * created or grew an FAQ source and queued an index run, and a sources
 * screen still showing the old chunk count is a screen that makes the
 * operator wonder whether the answer saved.
 */
export function useAnswerGap() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: async (input: { id: number; answer: string; source?: string }) =>
      (
        await api.post<AnswerResult>(`admin/knowledge/gaps/${input.id}/answer`, {
          answer: input.answer,
          ...(input.source ? { source: input.source } : {}),
        })
      ).data,
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: ['gaps'] });
      void client.invalidateQueries({ queryKey: ['knowledge'] });
      void client.invalidateQueries({ queryKey: ['analytics'] });
    },
  });
}

export function useSetGapStatus() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: async (input: { id: number; status: GapStatus }) =>
      (await api.post<KnowledgeGap>(`admin/knowledge/gaps/${input.id}/status`, {
        status: input.status,
      })).data,
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: ['gaps'] });
      void client.invalidateQueries({ queryKey: ['analytics'] });
    },
  });
}
