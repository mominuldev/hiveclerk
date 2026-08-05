import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/api/client';

export type OnboardingStatus =
  | 'not_started'
  | 'in_progress'
  | 'completed'
  | 'skipped';

export interface OnboardingState {
  status: OnboardingStatus;
  current_step: number;
  steps: Record<string, { done_at: string; data: Record<string, unknown> }>;
  agent: string | null;
  sources: string[];
  started_at: string | null;
  completed_at: string | null;
  skipped_at: string | null;
  labels: Record<string, string>;
  /** Read from the site itself, not from the wizard's own record. */
  site: { has_clerk: boolean; has_source: boolean };
}

export interface DetectedSource {
  key: string;
  label: string;
  source_type: string;
  post_type?: string;
  url?: string;
  count?: number;
  chunks?: number;
  sampled?: number;
  estimated_usd: number | null;
  recommended: boolean;
}

export interface DetectionResult {
  suggestions: DetectedSource[];
  sitemap: DetectedSource | null;
  currency: string;
}

export function useOnboarding() {
  return useQuery({
    queryKey: ['onboarding'],
    queryFn: async () =>
      (await api.get<OnboardingState>('admin/onboarding/state')).data,
    // Setup state changes only when this session changes it, and every
    // mutation below writes the answer straight into the cache.
    staleTime: Infinity,
  });
}

function useOnboardingMutation<TInput>(
  request: (input: TInput) => Promise<OnboardingState>
) {
  const client = useQueryClient();

  return useMutation({
    mutationFn: request,
    onSuccess: (state) => {
      client.setQueryData(['onboarding'], state);
    },
  });
}

export function useCompleteStep() {
  return useOnboardingMutation(
    async (input: {
      step: number;
      agent?: string;
      sources?: string[];
      choice?: string;
    }) =>
      (
        await api.post<OnboardingState>(`admin/onboarding/step/${input.step}`, {
          ...(input.agent ? { agent: input.agent } : {}),
          ...(input.sources ? { sources: input.sources } : {}),
          ...(input.choice ? { choice: input.choice } : {}),
        })
      ).data
  );
}

export function useFinishOnboarding() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: async (action: 'complete' | 'skip' | 'restart') =>
      (await api.post<OnboardingState>(`admin/onboarding/${action}`)).data,
    onSuccess: (state) => {
      client.setQueryData(['onboarding'], state);
      // Finishing setup usually means a clerk was hired and sources were
      // queued, and every screen that counts either is now wrong.
      void client.invalidateQueries({ queryKey: ['agents'] });
      void client.invalidateQueries({ queryKey: ['knowledge'] });
      void client.invalidateQueries({ queryKey: ['system'] });
    },
  });
}

/**
 * Ask the site what it has worth indexing (FR-ONB-04).
 *
 * A mutation rather than a query because it is a POST that samples the
 * database, and because the wizard runs it when the operator reaches step
 * three rather than when the app boots.
 */
export function useDetectSources() {
  return useMutation({
    mutationFn: async () =>
      (await api.post<DetectionResult>('admin/onboarding/detect')).data,
  });
}
