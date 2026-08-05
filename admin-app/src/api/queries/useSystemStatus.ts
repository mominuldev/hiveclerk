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

export interface SystemHealth {
  php: {
    version: string;
    memory_limit: string;
    max_execution_time: string;
    openssl: boolean;
  };
  wordpress: { version: string; multisite: boolean; cron_disabled: boolean };
  mysql: { version: string; mariadb: boolean; charset: string; collation: string };
  database: {
    version: number;
    latest: number;
    tables_present: number;
    tables_total: number;
    missing: string[];
  };
  queue: { driver: string; depth: number };
  cron: {
    scheduled: number;
    overdue: number;
    /**
     * Jobs that are being scheduled but not answered.
     *
     * Distinct from `overdue`, which counts jobs whose next run has passed.
     * A stalled job's next run keeps advancing perfectly — rescheduling
     * happens whether or not a callback existed — so it looks healthy by
     * every measure except whether anything ever ran.
     */
    stalled: number;
    events: {
      hook: string;
      next_run: string;
      is_late: boolean;
      last_run: string | null;
      is_stalled: boolean;
    }[];
  };
  providers: {
    provider: string;
    from_config: boolean;
    model: string;
    verified_at: string;
  }[];
  object_cache: { persistent: boolean; note: string };
}

/**
 * Environment diagnostics.
 *
 * Never refetched on window focus. Every value here changes on the scale
 * of a deploy, and a screen that re-queried the database server's version
 * each time an operator tabbed back would spend requests to tell them
 * nothing new.
 */
export function useSystemHealth() {
  return useQuery({
    queryKey: ['system', 'health'] as const,
    queryFn: async ({ signal }) =>
      (await api.get<SystemHealth>('system/health', undefined, signal)).data,
    refetchOnWindowFocus: false,
  });
}
