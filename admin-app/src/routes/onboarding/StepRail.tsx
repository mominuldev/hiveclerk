import { Check } from 'lucide-react';
import { cn } from '@/lib/cn';

interface StepRailProps {
  labels: Record<string, string>;
  current: number;
  done: number[];
  onStep: (step: number) => void;
}

/**
 * The five dots across the top of the wizard (D11 §12).
 *
 * Finished steps are clickable and unfinished ones are not. Jumping
 * forward past a step whose answer the next one depends on produces a
 * screen that cannot explain why it is empty, and "why is this blank"
 * is the question that ends a setup flow.
 */
export function StepRail({ labels, current, done, onStep }: StepRailProps) {
  const steps = Object.entries(labels).map(([key, label]) => ({
    number: Number(key),
    label,
  }));

  return (
    <ol className="flex items-center justify-center gap-1" aria-label="Setup steps">
      {steps.map((step, index) => {
        const complete = done.includes(step.number);
        const active = step.number === current;
        const reachable = complete || active;

        return (
          <li key={step.number} className="flex items-center gap-1">
            <button
              type="button"
              disabled={!reachable}
              aria-current={active ? 'step' : undefined}
              onClick={() => reachable && onStep(step.number)}
              className={cn(
                'flex items-center gap-2 rounded-lg px-2.5 py-1.5 text-sm',
                'transition-colors duration-[var(--hvc-duration-fast)]',
                'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
                'disabled:cursor-default',
                active && 'font-medium text-content',
                !active && complete && 'text-content-secondary hover:text-content',
                !active && !complete && 'text-content-tertiary'
              )}
            >
              <span
                aria-hidden="true"
                className={cn(
                  'flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold',
                  active && 'hvc-gradient-brand text-white',
                  !active && complete && 'bg-accent-subtle text-accent-text',
                  !active && !complete && 'border border-border text-content-tertiary'
                )}
              >
                {complete && !active ? <Check size={11} /> : step.number}
              </span>
              <span className="hidden sm:inline">{step.label}</span>
            </button>

            {index < steps.length - 1 && (
              <span
                aria-hidden="true"
                className={cn(
                  'h-px w-6 rounded-full',
                  complete ? 'bg-accent' : 'bg-border'
                )}
              />
            )}
          </li>
        );
      })}
    </ol>
  );
}
