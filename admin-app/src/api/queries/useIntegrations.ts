import {
  useMutation,
  useQuery,
  useQueryClient,
  type UseQueryResult,
} from '@tanstack/react-query';
import { api, type ApiError } from '@/api/client';

export type IntegrationStatus =
  | 'disconnected'
  | 'connected'
  | 'degraded'
  | 'expired';

export type ConnectorAuth = 'none' | 'local' | 'token' | 'url' | 'oauth';

export interface ConnectorSetting {
  key: string;
  label: string;
  type: 'text' | 'url' | 'password' | 'select';
  secret: boolean;
  required: boolean;
  help: string;
  placeholder: string;
}

export interface IntegrationCard {
  id: string;
  name: string;
  kind: 'crm' | 'notification';
  auth: ConnectorAuth;
  summary: string;
  is_pro: boolean;
  settings: ConnectorSetting[];
  docs_url: string;
  available: boolean;
  status: IntegrationStatus;
  status_label: string;
  account: string | null;
  contacts: number;
  failures: number;
  last_sync_at: string | null;
  last_error: string | null;
  expires_at: string | null;
  trigger: string;
}

export interface MappingSource {
  key: string;
  label: string;
  locked: boolean;
  sensitive: boolean;
}

export interface MappingTarget {
  key: string;
  label: string;
  type: string;
  custom: boolean;
  locked: boolean;
  required: boolean;
}

export interface MappingState {
  provider: string;
  mapping: Record<string, string>;
  trigger: string;
  threshold: number;
  send_transcript: boolean;
  events: string[];
  connected: boolean;
}

export interface SyncLogRow {
  id: number | null;
  provider: string | null;
  operation: string;
  status: 'success' | 'retrying' | 'failed' | 'skipped';
  status_label: string;
  lead_id: number | null;
  attempt: number;
  external_id: string | null;
  summary: Record<string, unknown>;
  response_code: number | null;
  error: string | null;
  next_retry_at: string | null;
  created_at: string | null;
}

const GRID_KEY = ['integrations', 'grid'] as const;
const LOG_KEY = ['integrations', 'log'] as const;

function mappingKey(provider: string) {
  return ['integrations', 'mapping', provider] as const;
}

function fieldsKey(provider: string) {
  return ['integrations', 'fields', provider] as const;
}

/**
 * Every connector this site knows about, connected or not.
 *
 * One request for the whole grid. A card per connector with its own query
 * would mean five requests on a screen that renders in full before
 * anybody scrolls, and the counts come from the same tables anyway.
 */
export function useIntegrations(): UseQueryResult<
  { integrations: IntegrationCard[]; events: string[] },
  ApiError
> {
  return useQuery({
    queryKey: GRID_KEY,
    queryFn: async () =>
      (
        await api.get<{ integrations: IntegrationCard[]; events: string[] }>(
          'admin/integrations'
        )
      ).data,
  });
}

function useGridInvalidation() {
  const client = useQueryClient();

  return (provider?: string) => {
    void client.invalidateQueries({ queryKey: GRID_KEY });
    void client.invalidateQueries({ queryKey: LOG_KEY });

    if (provider) {
      void client.invalidateQueries({ queryKey: mappingKey(provider) });
      void client.invalidateQueries({ queryKey: fieldsKey(provider) });
    }
  };
}

/**
 * Connect, or start an OAuth redirect.
 *
 * The response carries a redirect URL for connectors that use OAuth. The
 * caller navigates the whole window rather than opening a popup: a popup
 * blocked by the browser leaves the operator on a screen that looks like
 * nothing happened, and the callback comes back into wp-admin anyway.
 */
export function useConnect() {
  const invalidate = useGridInvalidation();

  return useMutation<
    { integration: IntegrationCard; redirect: string | null },
    ApiError,
    { provider: string; settings: Record<string, string> }
  >({
    mutationFn: async ({ provider, settings }) =>
      (
        await api.post<{
          integration: IntegrationCard;
          redirect: string | null;
        }>(`admin/integrations/${provider}/connect`, { settings })
      ).data,
    onSuccess: (_data, { provider }) => invalidate(provider),
  });
}

export function useTestIntegration() {
  const invalidate = useGridInvalidation();

  return useMutation<
    {
      ok: boolean;
      message: string;
      account: string | null;
      records: number | null;
      integration: IntegrationCard;
    },
    ApiError,
    string
  >({
    mutationFn: async (provider) =>
      (
        await api.post<{
          ok: boolean;
          message: string;
          account: string | null;
          records: number | null;
          integration: IntegrationCard;
        }>(`admin/integrations/${provider}/test`)
      ).data,
    onSuccess: (_data, provider) => invalidate(provider),
  });
}

export function useDisconnect() {
  const invalidate = useGridInvalidation();

  return useMutation<{ integration: IntegrationCard }, ApiError, string>({
    mutationFn: async (provider) =>
      (
        await api.delete<{ integration: IntegrationCard }>(
          `admin/integrations/${provider}`
        )
      ).data,
    onSuccess: (_data, provider) => invalidate(provider),
  });
}

export function useMapping(provider: string | null) {
  return useQuery({
    queryKey: mappingKey(provider ?? ''),
    queryFn: async () =>
      (await api.get<MappingState>(`admin/integrations/${provider}/mapping`))
        .data,
    enabled: provider !== null && provider !== '',
  });
}

/**
 * Both sides of the mapping screen.
 *
 * Kept apart from the mapping itself because the target list may involve
 * a live call to the CRM, and a slow HubSpot should not stop the mapping
 * an operator already saved from rendering.
 */
export function useMappingFields(provider: string | null) {
  return useQuery({
    queryKey: fieldsKey(provider ?? ''),
    queryFn: async () =>
      (
        await api.get<{ sources: MappingSource[]; targets: MappingTarget[] }>(
          `admin/integrations/${provider}/fields`
        )
      ).data,
    enabled: provider !== null && provider !== '',
    staleTime: 60_000,
  });
}

export function useSaveMapping(provider: string) {
  const client = useQueryClient();
  const invalidate = useGridInvalidation();

  return useMutation<
    MappingState,
    ApiError,
    {
      mapping: Record<string, string>;
      trigger?: string;
      threshold?: number;
      send_transcript?: boolean;
      events?: string[];
    }
  >({
    mutationFn: async (input) =>
      (
        await api.put<MappingState>(
          `admin/integrations/${provider}/mapping`,
          input
        )
      ).data,
    onSuccess: (data) => {
      client.setQueryData(mappingKey(provider), data);
      invalidate();
    },
  });
}

export function useSyncLog(filters: {
  provider?: string;
  status?: string;
  page?: number;
}) {
  return useQuery({
    queryKey: [...LOG_KEY, filters],
    queryFn: async () => {
      const envelope = await api.get<SyncLogRow[]>('admin/integrations/log', {
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
