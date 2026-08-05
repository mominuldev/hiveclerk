import {
  useMutation,
  useQuery,
  useQueryClient,
  type UseQueryResult,
} from '@tanstack/react-query';
import { api, type ApiError } from '@/api/client';

export interface ConversationSummary {
  uuid: string;
  agent: { id: number; name: string; uuid: string | null };
  status: string;
  needs_attention: boolean;
  human_handled: boolean;
  handoff_at: string | null;
  handoff_user: string | null;
  message_count: number;
  has_lead: boolean;
  rating: number | null;
  sentiment: string | null;
  starred: boolean;
  tags: string[];
  note_count: number;
  preview: string;
  tokens: number;
  cost: number;
  started_at: string | null;
  last_message_at: string | null;
  resolved_by_ai: boolean;
}

export interface TranscriptCitation {
  chunk_id: number | null;
  document_id: number | null;
  title: string;
  url: string | null;
  heading_path: string | null;
  excerpt: string;
  score: number;
}

export interface TranscriptMessage {
  uuid: string;
  role: 'visitor' | 'assistant' | 'human_agent' | 'system';
  content: string;
  author: string | null;
  created_at: string | null;
  tokens_in: number;
  tokens_out: number;
  cost: number;
  latency_ms: number | null;
  retrieval_score: number | null;
  grounded: boolean;
  rating: number | null;
  flags: string[];
  model: string | null;
  provider: string | null;
  citations: TranscriptCitation[];
}

export interface ConversationNote {
  text: string;
  author_id: number | null;
  author_name: string;
  created_at: string;
}

export interface ConversationDetail extends ConversationSummary {
  page_url: string | null;
  page_title: string | null;
  language: string | null;
  summary: string | null;
  notes: ConversationNote[];
  messages: TranscriptMessage[];
}

export interface ConversationFilters {
  agent?: string;
  status?: string;
  handoff?: boolean;
  starred?: boolean;
  has_lead?: boolean;
  rating?: number;
  search?: string;
  page?: number;
}

const LIST_KEY = ['conversations', 'list'] as const;

function detailKey(uuid: string) {
  return ['conversations', 'detail', uuid] as const;
}

/**
 * The conversation list.
 *
 * Polled while anything is waiting on a person. Somebody watching this
 * screen for handoffs is doing the one job that is time-critical in the
 * product, and a list that only updates on reload makes them reload.
 */
export function useConversations(
  filters: ConversationFilters = {}
): UseQueryResult<
  { conversations: ConversationSummary[]; total: number; totalPages: number },
  ApiError
> {
  return useQuery({
    queryKey: [...LIST_KEY, filters],
    queryFn: async () => {
      const envelope = await api.get<ConversationSummary[]>(
        'admin/conversations',
        { per_page: 25, ...filters }
      );

      return {
        conversations: envelope.data,
        total: envelope.meta?.pagination?.total ?? envelope.data.length,
        totalPages: envelope.meta?.pagination?.total_pages ?? 1,
      };
    },
    refetchInterval: (query) =>
      query.state.data?.conversations.some((c) => c.needs_attention)
        ? 15_000
        : false,
  });
}

export function useConversation(uuid: string | null) {
  return useQuery({
    queryKey: detailKey(uuid ?? ''),
    queryFn: async () =>
      (await api.get<ConversationDetail>(`admin/conversations/${uuid}`)).data,
    enabled: uuid !== null && uuid !== '',
    // A transcript a colleague is replying into changes under the reader.
    refetchInterval: (query) =>
      query.state.data?.human_handled || query.state.data?.needs_attention
        ? 15_000
        : false,
  });
}

export function useRetentionPolicy() {
  return useQuery({
    queryKey: ['conversations', 'retention'],
    queryFn: async () =>
      (
        await api.get<{
          months: number;
          cutoff: string | null;
          pending: number;
        }>('admin/conversations/retention')
      ).data,
    staleTime: 5 * 60_000,
  });
}

function useConversationMutation<TInput>(
  uuid: string,
  path: string,
  method: 'post' | 'patch' = 'post'
) {
  const client = useQueryClient();

  return useMutation<unknown, ApiError, TInput>({
    mutationFn: async (input) =>
      method === 'patch'
        ? (await api.patch<unknown>(`admin/conversations/${uuid}/${path}`, input))
            .data
        : (await api.post<unknown>(`admin/conversations/${uuid}/${path}`, input))
            .data,
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: detailKey(uuid) });
      void client.invalidateQueries({ queryKey: LIST_KEY });
    },
  });
}

export function useTakeover(uuid: string) {
  return useConversationMutation<void>(uuid, 'takeover');
}

export function useHumanReply(uuid: string) {
  return useConversationMutation<{ message: string }>(uuid, 'reply');
}

export function useResolveConversation(uuid: string) {
  return useConversationMutation<void>(uuid, 'resolve');
}

export function useTagConversation(uuid: string) {
  return useConversationMutation<{ tags?: string[]; starred?: boolean }>(
    uuid,
    'tags',
    'patch'
  );
}

export function useAddNote(uuid: string) {
  return useConversationMutation<{ note: string }>(uuid, 'notes');
}

export function useDeleteConversation() {
  const client = useQueryClient();

  return useMutation<void, ApiError, string>({
    mutationFn: async (uuid) => {
      await api.delete<{ deleted: boolean }>(`admin/conversations/${uuid}`);
    },
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: LIST_KEY });
    },
  });
}
