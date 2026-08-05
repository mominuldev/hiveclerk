import {
  useMutation,
  useQuery,
  useQueryClient,
  type UseQueryResult,
} from '@tanstack/react-query';
import { api, type ApiError } from '@/api/client';

export interface IngestionProgress {
  processed: number;
  total: number;
  indexed: number;
  skipped: number;
  failed: number;
  chunks: number;
  stage: string;
  current: string | null;
  percent: number | null;
}

export interface KnowledgeSource {
  uuid: string;
  name: string;
  type: string;
  type_label: string;
  status: string;
  is_busy: boolean;
  needs_action: boolean;
  document_count: number;
  chunk_count: number;
  token_count: number;
  sync_schedule: string;
  last_synced_at: string | null;
  last_error: string | null;
  progress: Partial<IngestionProgress>;
  config?: Record<string, unknown>;
}

export interface SourceTypeOption {
  value: string;
  label: string;
  available: boolean;
  unavailable_reason: string;
  supports_schedule: boolean;
}

export interface KnowledgeDocument {
  id: number;
  title: string;
  url: string;
  chunk_count: number;
  token_count: number;
  status: string;
  metadata: Record<string, unknown>;
}

export interface DocumentChunk {
  id: number;
  index: number;
  content: string;
  heading_path: string[];
  token_count: number;
  char_start: number;
  char_end: number;
}

export interface CreateSourceInput {
  name: string;
  type: string;
  config: Record<string, unknown>;
  sync_schedule?: string;
}

const SOURCES_KEY = ['knowledge', 'sources'] as const;

/**
 * How often a running import is re-read, in milliseconds.
 *
 * Two seconds is fast enough that the counters look live and slow enough
 * that a long crawl does not add a request per second to a site that is
 * already busy indexing itself.
 */
const POLL_MS = 2000;

/**
 * Knowledge sources, polled only while something is actually running.
 *
 * The polling interval is a function of the data rather than a constant.
 * A fixed interval keeps hitting the server for the entire time the tab
 * is open — on a screen an operator is quite likely to leave open — and
 * every one of those requests reports the same finished numbers.
 */
export function useSources(): UseQueryResult<
  { sources: KnowledgeSource[]; total: number },
  ApiError
> {
  return useQuery({
    queryKey: SOURCES_KEY,
    queryFn: async () => {
      const envelope = await api.get<KnowledgeSource[]>(
        'admin/knowledge/sources',
        { per_page: 100 }
      );

      return {
        sources: envelope.data,
        total: envelope.meta?.pagination?.total ?? envelope.data.length,
      };
    },
    refetchInterval: (query) => {
      const busy = query.state.data?.sources.some((source) => source.is_busy);
      return busy ? POLL_MS : false;
    },
  });
}

export function useSource(uuid: string | null) {
  return useQuery({
    queryKey: ['knowledge', 'source', uuid],
    queryFn: async () =>
      (await api.get<KnowledgeSource>(`admin/knowledge/sources/${uuid}`)).data,
    enabled: uuid !== null,
  });
}

/**
 * Which source types this installation can actually use.
 *
 * Cached hard: the answer only changes when a plugin is activated, and
 * offering a WooCommerce source on a site without WooCommerce produces a
 * source that can only ever fail.
 */
export function useSourceTypes() {
  return useQuery({
    queryKey: ['knowledge', 'types'],
    queryFn: async () =>
      (await api.get<{ types: SourceTypeOption[] }>('admin/knowledge/types'))
        .data.types,
    staleTime: 10 * 60_000,
  });
}

export function useDocuments(uuid: string | null, page: number) {
  return useQuery({
    queryKey: ['knowledge', 'documents', uuid, page],
    queryFn: async () => {
      const envelope = await api.get<KnowledgeDocument[]>(
        `admin/knowledge/sources/${uuid}/documents`,
        { page, per_page: 25 }
      );

      return {
        documents: envelope.data,
        total: envelope.meta?.pagination?.total ?? 0,
        totalPages: envelope.meta?.pagination?.total_pages ?? 1,
      };
    },
    enabled: uuid !== null,
  });
}

export function useChunks(documentId: number | null) {
  return useQuery({
    queryKey: ['knowledge', 'chunks', documentId],
    queryFn: async () =>
      (
        await api.get<{
          document: { id: number; title: string; url: string };
          chunks: DocumentChunk[];
        }>(`admin/knowledge/documents/${documentId}/chunks`)
      ).data,
    enabled: documentId !== null,
  });
}

export function useCreateSource() {
  const client = useQueryClient();

  return useMutation<KnowledgeSource, ApiError, CreateSourceInput>({
    mutationFn: async (input) =>
      (await api.post<KnowledgeSource>('admin/knowledge/sources', input)).data,
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: SOURCES_KEY });
    },
  });
}

export function useReindexSource() {
  const client = useQueryClient();

  return useMutation<KnowledgeSource, ApiError, string>({
    mutationFn: async (uuid) =>
      (await api.post<KnowledgeSource>(`admin/knowledge/sources/${uuid}/index`))
        .data,
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: SOURCES_KEY });
    },
  });
}

export function useCancelSource() {
  const client = useQueryClient();

  return useMutation<KnowledgeSource, ApiError, string>({
    mutationFn: async (uuid) =>
      (await api.post<KnowledgeSource>(`admin/knowledge/sources/${uuid}/cancel`))
        .data,
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: SOURCES_KEY });
    },
  });
}

export function useDeleteSource() {
  const client = useQueryClient();

  return useMutation<void, ApiError, string>({
    mutationFn: async (uuid) => {
      await api.delete<void>(`admin/knowledge/sources/${uuid}`);
    },
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: SOURCES_KEY });
    },
  });
}

export interface ParsedFaq {
  pairs: Array<{ question: string; answer: string; url: string }>;
  skipped: number;
}

export function useParseFaqCsv() {
  return useMutation<ParsedFaq, ApiError, string>({
    mutationFn: async (csv) =>
      (await api.post<ParsedFaq>('admin/knowledge/faq/parse', { csv })).data,
  });
}
