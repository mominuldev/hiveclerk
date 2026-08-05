import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/api/client';

export interface PrivacySettings {
  /** Months of conversation history kept. 0 means forever. */
  retention_months: number;
  store_ip_hash: boolean;
  require_consent: boolean;
  consent_text: string | null;
  delete_on_uninstall: boolean;
}

export interface PrivacyState {
  settings: PrivacySettings;
  retention: {
    max_months: number;
    /**
     * How many conversations the current policy would delete on its next
     * run. Counted live on every read, because it is the number that
     * tells an operator what the save they are about to make will
     * destroy, and a cached one is worse than none.
     */
    pending: number;
    cutoff: string | null;
  };
}

export const privacyKeys = {
  all: ['privacy'] as const,
};

export function usePrivacy() {
  return useQuery({
    queryKey: privacyKeys.all,
    queryFn: async ({ signal }) =>
      (await api.get<PrivacyState>('admin/settings/privacy', undefined, signal)).data,
  });
}

export function useSavePrivacy() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: async (input: Partial<PrivacySettings>) =>
      (await api.put<PrivacyState>('admin/settings/privacy', input)).data,
    onSuccess: (state) => {
      client.setQueryData(privacyKeys.all, state);
    },
  });
}
