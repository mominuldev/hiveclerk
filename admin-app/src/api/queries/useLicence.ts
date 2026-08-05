import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/api/client';
import { boot } from '@/boot';
import type { FeatureKey, Licence } from '@/boot';

export type { FeatureKey, Licence, LicenceStatus, Tier } from '@/boot';

/**
 * The licence, seeded from first paint.
 *
 * PHP already put the whole licence in the boot payload, so the settings
 * screen renders correctly on its first frame and the network request is
 * a refresh rather than a load. Without the seed every gated screen in
 * the product would flash its free-tier state before correcting itself.
 */
export function useLicence() {
  return useQuery({
    queryKey: ['licence'],
    queryFn: async () => (await api.get<Licence>('admin/settings/licence')).data,
    initialData: boot().licence,
    staleTime: 60_000,
  });
}

/**
 * Whether a feature is in force right now.
 *
 * Reads the boot payload rather than the query, so a component can ask
 * without subscribing — the entitlement is fixed for the life of the page
 * unless the licence screen changes it, and that screen invalidates the
 * query itself.
 */
export function useFeature(feature: FeatureKey): boolean {
  const { data } = useLicence();

  return data?.features?.[feature] === true;
}

export function useActivateLicence() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: async (key: string) =>
      (await api.post<Licence>('admin/settings/licence', { key })).data,
    onSuccess: (licence) => {
      client.setQueryData(['licence'], licence);
      // Everything gated changes at once: the connectors grid, the
      // sequence list, the branding tab, the clerk roster's hire button.
      void client.invalidateQueries();
    },
  });
}

export function useDeactivateLicence() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: async () => (await api.delete<Licence>('admin/settings/licence')).data,
    onSuccess: (licence) => {
      client.setQueryData(['licence'], licence);
      void client.invalidateQueries();
    },
  });
}

export function useRecheckLicence() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: async () =>
      (await api.post<Licence>('admin/settings/licence/recheck')).data,
    onSuccess: (licence) => {
      client.setQueryData(['licence'], licence);
    },
  });
}
