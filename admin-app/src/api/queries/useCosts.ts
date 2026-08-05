import { useQuery } from '@tanstack/react-query';
import { api } from '@/api/client';

export interface UsageSlice {
  label: string;
  provider: string;
  calls: number;
  tokens_in: number;
  tokens_out: number;
  cost: number;
  unpriced: number;
  complete: boolean;
}

export interface CostsResponse {
  range: { from: string; to: string };
  total: UsageSlice;
  by_model: UsageSlice[];
  daily: UsageSlice[];
}

/**
 * Provider spend over the last N days.
 *
 * The server defaults to 30 days and bounds the range, so the client does
 * not compute dates. A client-side date arithmetic bug here would produce
 * a plausible-looking wrong total, which is the worst kind.
 */
export function useCosts() {
  return useQuery({
    queryKey: ['costs'],
    queryFn: async () => (await api.get<CostsResponse>('admin/analytics/costs')).data,
    staleTime: 60_000,
  });
}
