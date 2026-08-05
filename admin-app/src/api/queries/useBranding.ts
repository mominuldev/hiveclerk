import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/api/client';

export interface BrandingSettings {
  white_label: boolean;
  product_name: string;
  hide_badge: boolean;
  badge_label: string;
  badge_url: string;
  logo_url: string;
  accent: string;
  support_url: string;
}

export interface BrandingState {
  /** What the operator saved, whether or not their tier covers it. */
  settings: BrandingSettings;
  /** What visitors and this admin will actually see. */
  effective: {
    productName: string;
    whiteLabel: boolean;
    logoUrl: string | null;
    accent: string | null;
    supportUrl: string | null;
    showBadge: boolean;
  };
  entitlements: { white_label: boolean; remove_badge: boolean };
}

export function useBranding() {
  return useQuery({
    queryKey: ['branding'],
    queryFn: async () => (await api.get<BrandingState>('admin/settings/branding')).data,
  });
}

/**
 * Save branding preferences.
 *
 * Saving is never refused on tier — the server stores what was chosen and
 * reports what is in force. So the mutation cannot fail with a 402, and
 * the screen's job is to render the difference rather than to prevent the
 * save.
 */
export function useSaveBranding() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: async (input: Partial<BrandingSettings>) =>
      (await api.put<BrandingState>('admin/settings/branding', input)).data,
    onSuccess: (state) => {
      client.setQueryData(['branding'], state);
    },
  });
}
