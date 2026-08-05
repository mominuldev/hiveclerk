import { useQuery } from '@tanstack/react-query';
import { api } from '@/api/client';

export interface SystemStatus {
  version: string;
  time: string;
  database: {
    version: number;
    latest: number;
    needs_migration: boolean;
  };
  counts: {
    agents: number;
    published: number;
    conversations: number;
    sources: number;
    chunks: number;
  };
  ready: boolean;
}

export const systemKeys = {
  status: ['system', 'status'] as const,
};

export function useSystemStatus() {
  return useQuery({
    queryKey: systemKeys.status,
    queryFn: async ({ signal }) => {
      const { data } = await api.get<SystemStatus>('system/status', undefined, signal);
      return data;
    },
  });
}
