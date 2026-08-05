import {
  useMutation,
  useQuery,
  useQueryClient,
  type UseQueryResult,
} from '@tanstack/react-query';
import { api, type ApiError } from '@/api/client';

export interface ProviderPricing {
  input_per_million: number;
  output_per_million: number;
  currency: string;
}

export interface ProviderModel {
  id: string;
  label: string;
  context_window: number;
  max_output: number;
  streams: boolean;
  embeds: boolean;
  dimensions: number;
  pricing: ProviderPricing | null;
}

export interface ProviderCapabilities {
  streaming: boolean;
  embeddings: boolean;
  system_prompt: boolean;
  live_models: boolean;
  needs_endpoint: boolean;
}

/**
 * Everything the settings screen knows about a provider.
 *
 * Note what is absent: there is no key field, encrypted or otherwise. The
 * server has no endpoint that returns one, so the type cannot describe one
 * either — which is the point of writing it out here rather than reaching
 * for a loose record type.
 */
export interface ProviderState {
  provider: string;
  label: string;
  is_set: boolean;
  masked: string;
  from_config: boolean;
  endpoint: string;
  api_version: string;
  model: string;
  verified_at: string;
  model_count: number;
  capabilities: ProviderCapabilities;
  default_model: string;
  console_url: string;
  key_hint: string;
}

export interface VerifyResult {
  ok: boolean;
  message: string;
  model_count: number;
  latency_ms: number;
  state: ProviderState;
}

export interface SaveProviderInput {
  provider: string;
  api_key?: string;
  endpoint?: string;
  api_version?: string;
  model?: string;
}

export interface VerifyProviderInput {
  provider: string;
  api_key?: string;
  endpoint?: string;
  api_version?: string;
}

const PROVIDERS_KEY = ['providers'] as const;

export function useProviders(): UseQueryResult<
  { providers: ProviderState[]; pricingAsOf: string },
  ApiError
> {
  return useQuery({
    queryKey: PROVIDERS_KEY,
    queryFn: async () => {
      const envelope = await api.get<ProviderState[]>(
        'admin/settings/providers'
      );

      return {
        providers: envelope.data,
        pricingAsOf:
          (envelope.meta as { pricing_as_of?: string } | undefined)
            ?.pricing_as_of ?? '',
      };
    },
  });
}

/**
 * Models the stored key can reach.
 *
 * Only runs once a key exists — asking otherwise guarantees a 409 and
 * shows the operator an error for something they have not done yet.
 * Retries are off because the two likely failures, a bad key and an
 * unreachable endpoint, both fail identically on a second attempt.
 */
export function useProviderModels(provider: string, enabled: boolean) {
  return useQuery({
    queryKey: ['providers', provider, 'models'],
    queryFn: async () =>
      (await api.get<ProviderModel[]>(`admin/settings/providers/${provider}/models`))
        .data,
    enabled,
    staleTime: 5 * 60_000,
    retry: false,
  });
}

export function useSaveProvider() {
  const client = useQueryClient();

  return useMutation<ProviderState, ApiError, SaveProviderInput>({
    mutationFn: async (input) =>
      (await api.put<ProviderState>('admin/settings/providers', input)).data,
    onSuccess: (_data, input) => {
      void client.invalidateQueries({ queryKey: PROVIDERS_KEY });
      // The cached model list belonged to the previous key.
      void client.invalidateQueries({
        queryKey: ['providers', input.provider, 'models'],
      });
    },
  });
}

export function useVerifyProvider() {
  const client = useQueryClient();

  return useMutation<VerifyResult, ApiError, VerifyProviderInput>({
    mutationFn: async ({ provider, ...body }) =>
      (await api.post<VerifyResult>(
        `admin/settings/providers/${provider}/verify`,
        body
      )).data,
    onSuccess: (result, input) => {
      void client.invalidateQueries({ queryKey: PROVIDERS_KEY });

      if (result.ok) {
        void client.invalidateQueries({
          queryKey: ['providers', input.provider, 'models'],
        });
      }
    },
  });
}

export function useRemoveProvider() {
  const client = useQueryClient();

  return useMutation<ProviderState, ApiError, string>({
    mutationFn: async (provider) =>
      (await api.delete<ProviderState>(`admin/settings/providers/${provider}`))
        .data,
    onSuccess: (_data, provider) => {
      void client.invalidateQueries({ queryKey: PROVIDERS_KEY });
      void client.removeQueries({ queryKey: ['providers', provider, 'models'] });
    },
  });
}
