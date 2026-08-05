import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { Skeleton } from '@/components/ui/Skeleton';
import { Logomark } from '@/components/brand/Logomark';
import { toast } from '@/components/ui/Toast';
import {
  useCompleteStep,
  useFinishOnboarding,
  useOnboarding,
} from '@/api/queries/useOnboarding';
import { StepRail } from './StepRail';
import { StepKnowledge, StepModel, StepRole } from './steps';
import { StepLook, StepPublish } from './finalSteps';

/**
 * The five-step setup wizard (D11 §12, FR-ONB-01/05).
 *
 * Every step drives the endpoint that already exists for the thing it is
 * doing — the provider route verifies the key, the agents route hires the
 * clerk, the knowledge route creates the sources — and posts a marker
 * here so the flow is resumable. Reloading mid-setup returns to the same
 * step with the clerk that was already hired intact.
 *
 * Skipping is always available and never disguised as a secondary
 * "Continue". An operator who wants to look around first should be able
 * to, and a flow that traps them is a flow they leave by closing the tab.
 */
export function Wizard() {
  const navigate = useNavigate();
  const { data, isPending, isError, error, refetch } = useOnboarding();
  const complete = useCompleteStep();
  const finish = useFinishOnboarding();
  const [override, setOverride] = useState<number | null>(null);

  if (isError) {
    return <ErrorNotice error={error} onRetry={() => void refetch()} />;
  }

  if (isPending) {
    return <Skeleton className="h-[420px] w-full rounded-xl" />;
  }

  if ('completed' === data.status || 'skipped' === data.status) {
    return (
      <Card>
        <EmptyState
          title={
            'completed' === data.status
              ? 'Setup is done.'
              : 'Setup was skipped.'
          }
          description="Everything it would have configured is still editable from the screens it set up."
          action={
            <div className="flex gap-2">
              <Button variant="primary" onClick={() => navigate('/dashboard')}>
                Go to the dashboard
              </Button>
              <Button
                loading={finish.isPending}
                onClick={() =>
                  finish.mutate('restart', {
                    onSuccess: () => setOverride(1),
                    onError: (mutationError) => toast.error(mutationError.message),
                  })
                }
              >
                Run setup again
              </Button>
            </div>
          }
        />
      </Card>
    );
  }

  const step = override ?? data.current_step;
  const done = Object.keys(data.steps).map(Number);

  const advance = (payload?: {
    agent?: string;
    sources?: string[];
    choice?: string;
  }) => {
    complete.mutate(
      { step, ...payload },
      {
        onSuccess: (state) => {
          if (step >= 5) {
            finish.mutate('complete', {
              onSuccess: () => {
                toast.success('Setup finished.');
                navigate('/dashboard');
              },
            });

            return;
          }

          setOverride(Math.min(5, Math.max(step + 1, state.current_step)));
        },
        onError: (mutationError) => toast.error(mutationError.message),
      }
    );
  };

  const shared = {
    onDone: advance,
    agentUuid: data.agent,
    busy: complete.isPending || finish.isPending,
  };

  return (
    <div className="mx-auto max-w-4xl space-y-8 py-4">
      <div className="flex items-center justify-between gap-4">
        <span className="flex items-center gap-2.5">
          <Logomark size={28} className="rounded-lg" />
          <span className="text-sm font-medium text-content">Setup</span>
        </span>

        <Button
          variant="ghost"
          size="sm"
          loading={finish.isPending}
          onClick={() =>
            finish.mutate('skip', {
              onSuccess: () => navigate('/dashboard'),
              onError: (mutationError) => toast.error(mutationError.message),
            })
          }
        >
          Skip setup
        </Button>
      </div>

      <StepRail
        labels={data.labels}
        current={step}
        done={done}
        onStep={setOverride}
      />

      <div className="pt-2">
        {1 === step && <StepModel {...shared} />}
        {2 === step && <StepRole {...shared} />}
        {3 === step && <StepKnowledge {...shared} />}
        {4 === step && <StepLook {...shared} />}
        {5 === step && <StepPublish {...shared} />}
      </div>
    </div>
  );
}
