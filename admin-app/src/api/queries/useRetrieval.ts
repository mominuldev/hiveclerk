import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, type ApiError } from '@/api/client';

export interface RetrievalDiagnostics {
  scanned: number;
  candidates: number;
  keyword_matches: number;
  returned: number;
  confident: number;
  embed_ms: number;
  stage1_ms: number;
  stage2_ms: number;
  keyword_ms: number;
  fusion_ms: number;
  total_ms: number;
  strategy: string;
  matrix_source: string;
  popcount: string;
  cached: boolean;
  peak_mb: number;
  notes: string[];
}

export interface SearchResult {
  chunk_id: number;
  document_id: number;
  source_id: number;
  document_title: string;
  document_url: string | null;
  heading_path: string[];
  excerpt: string;
  token_count: number;
  rank: number;
  vector_score: number;
  vector_rank: number | null;
  bm25_score: number;
  keyword_rank: number | null;
  fused_score: number;
  confident: boolean;
}

export interface EmbeddingPin {
  provider: string;
  model: string;
  dimensions: number;
}

export interface SearchResponse {
  results: SearchResult[];
  diagnostics: Partial<RetrievalDiagnostics>;
  threshold: number;
  best_score: number;
  sources: number[];
  embedding: EmbeddingPin | null;
  message?: string;
}

export interface SearchInput {
  query: string;
  source_ids?: number[];
  top_k?: number;
  threshold?: number;
  use_keyword?: boolean;
  fresh?: boolean;
}

/**
 * A search is a mutation, not a query.
 *
 * It costs money — every run embeds the question through the customer's
 * provider account — so it must never be refetched on a window focus, a
 * reconnect or a cache revalidation. React Query does all three to
 * queries by default and none of them to mutations.
 */
export function useSearch() {
  return useMutation<SearchResponse, ApiError, SearchInput>({
    mutationFn: async (input) =>
      (await api.post<SearchResponse>('admin/knowledge/search', input)).data,
  });
}

export interface RetrievalSourceStatus {
  id: number;
  uuid: string;
  name: string;
  chunk_count: number;
  vectors: number;
  models: Array<{
    provider: string;
    model: string;
    dimensions: number;
    count: number;
  }>;
  pinned: EmbeddingPin | null;
  searchable: boolean;
}

export interface RetrievalStatus {
  store: {
    driver: string;
    quantisation: string;
    max_dimensions: number;
    popcount: string;
    cache: {
      backend: string;
      persistent: boolean;
      ttl: number;
      max_cacheable: number | null;
      note: string | null;
    };
  };
  sources: RetrievalSourceStatus[];
}

export function useRetrievalStatus() {
  return useQuery<RetrievalStatus, ApiError>({
    queryKey: ['knowledge', 'retrieval'],
    queryFn: async () =>
      (await api.get<RetrievalStatus>('admin/knowledge/retrieval')).data,
  });
}

export interface EmbeddingProviderOption {
  id: string;
  label: string;
  ready: boolean;
  error: string | null;
  models: Array<{
    id: string;
    label: string;
    dimensions: number;
    pricing: { input_per_million: number; currency: string } | null;
  }>;
}

export interface EmbeddingSettings {
  configured: EmbeddingPin | null;
  is_explicit: boolean;
  providers: EmbeddingProviderOption[];
  max_dimensions: number;
}

export function useEmbeddingSettings() {
  return useQuery<EmbeddingSettings, ApiError>({
    queryKey: ['knowledge', 'embedding'],
    queryFn: async () =>
      (await api.get<EmbeddingSettings>('admin/knowledge/embedding')).data,
    staleTime: 5 * 60_000,
  });
}

export interface SaveEmbeddingResult {
  configured: EmbeddingPin;
  sources_affected: number;
  message: string | null;
}

export function useSaveEmbedding() {
  const client = useQueryClient();

  return useMutation<
    SaveEmbeddingResult,
    ApiError,
    { provider: string; model: string }
  >({
    mutationFn: async (input) =>
      (await api.put<SaveEmbeddingResult>('admin/knowledge/embedding', input))
        .data,
    onSuccess: () => {
      // Sources may have just been flagged for re-embedding, so the list
      // that shows their status is now wrong on screen.
      void client.invalidateQueries({ queryKey: ['knowledge'] });
    },
  });
}
