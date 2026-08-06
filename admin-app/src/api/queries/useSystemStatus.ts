import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
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
  licence: { sodium: boolean; key_configured: boolean; verifying: boolean };
  encryption: { rotating: boolean; outstanding: number };
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

/** Where a rotation has got to. */
export interface RotationState {
  rotating: boolean;
  /** Labels of secrets not yet moved. Never the secrets themselves. */
  outstanding: string[];
  rewritten?: number;
  remaining?: number;
  unreadable?: number;
}

const healthKey = ['system', 'health'] as const;

/**
 * The three steps of a key rotation.
 *
 * Separate mutations rather than one, because they are separate decisions.
 * Starting is safe and reversible-by-finishing; finishing destroys the old
 * key and is the only one that can lose data, so it must not be something a
 * caller can trigger by retrying the first.
 */
export function useKeyRotation() {
  const client = useQueryClient();
  const refresh = () => void client.invalidateQueries({ queryKey: healthKey });

  const begin = useMutation({
    mutationFn: async () => (await api.post<RotationState>('system/encryption/rotation')).data,
    onSuccess: refresh,
  });

  const sweep = useMutation({
    mutationFn: async () =>
      (await api.post<RotationState>('system/encryption/rotation/sweep')).data,
    onSuccess: refresh,
  });

  const finish = useMutation({
    mutationFn: async () =>
      (await api.post<RotationState>('system/encryption/rotation/finish')).data,
    onSuccess: refresh,
  });

  return { begin, sweep, finish };
}
