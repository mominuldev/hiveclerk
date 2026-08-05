import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '@/api/client';
import type { PageMeta } from '@/components/ui/Pagination';

export interface AuditEntry {
  action: string;
  user_id: number | null;
  user: string;
  object_type: string | null;
  object_id: number | null;
  changes: Record<string, unknown>;
  sensitive: boolean;
  created_at: string;
}

export interface AuditQuery {
  page: number;
  action: string;
}

export function useAuditLog({ page, action }: AuditQuery) {
  return useQuery({
    queryKey: ['audit-log', page, action],
    queryFn: async () => {
      const envelope = await api.get<AuditEntry[]>('admin/settings/audit-log', {
        page,
        per_page: 25,
        ...(action ? { action } : {}),
      });

      return {
        entries: envelope.data,
        meta: envelope.meta?.pagination as PageMeta | undefined,
      };
    },
    // Keeps the previous page on screen while the next loads, so paging
    // does not blank the table and jump the scroll position.
    placeholderData: keepPreviousData,
  });
}

/**
 * Action names present in the log, for the filter.
 *
 * Read from the data rather than a hard-coded list, so the filter never
 * offers an action this install has never performed and never omits one
 * added by a third-party module.
 */
export function useAuditActions() {
  return useQuery({
    queryKey: ['audit-log', 'actions'],
    queryFn: async () =>
      (await api.get<string[]>('admin/settings/audit-log/actions')).data,
    staleTime: 5 * 60_000,
  });
}
