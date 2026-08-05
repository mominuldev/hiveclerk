import { Loader2 } from 'lucide-react';
import type { IngestionProgress } from '@/api/queries/useKnowledge';

interface SourceProgressProps {
  progress: Partial<IngestionProgress>;
}

const STAGES: Record<string, string> = {
  queued: 'Waiting for the queue',
  starting: 'Starting',
  extracting: 'Reading content',
  pruning: 'Removing deleted pages',
  done: 'Finished',
  cancelled: 'Stopped',
  error: 'Failed',
};

/**
 * What an import is doing right now.
 *
 * Two shapes, chosen by whether the total is known. A crawl cannot know
 * how many pages a site has until it has finished finding them, so it
 * gets a count that climbs rather than a bar. A bar that reaches ninety
 * percent and stays there reads as a hang, and the operator cancels an
 * import that was working.
 */
export function SourceProgress({ progress }: SourceProgressProps) {
  const stage = STAGES[progress.stage ?? 'queued'] ?? 'Working';
  const processed = progress.processed ?? 0;
  const percent = progress.percent ?? null;

  return (
    <div className="space-y-1.5">
      <div className="flex items-center gap-2 text-xs text-content-secondary">
        <Loader2 size={12} className="shrink-0 animate-spin" aria-hidden="true" />
        <span className="font-medium text-content">{stage}</span>
        {percent === null ? (
          <span>
            {processed.toLocaleString()}{' '}
            {processed === 1 ? 'item' : 'items'} so far
          </span>
        ) : (
          <span>
            {processed.toLocaleString()} of{' '}
            {(progress.total ?? 0).toLocaleString()}
          </span>
        )}
      </div>

      {percent === null ? (
        // An indeterminate bar. It says "working" without claiming to
        // know how much is left, which is the honest state here.
        <div className="h-1 overflow-hidden rounded-full bg-surface-sunken">
          <div className="hvc-gradient-brand hvc-pulse h-full w-1/3 rounded-full" />
        </div>
      ) : (
        <div className="h-1 overflow-hidden rounded-full bg-surface-sunken">
          <div
            className="hvc-gradient-brand h-full rounded-full transition-[width] duration-500"
            style={{ width: `${percent}%` }}
          />
        </div>
      )}

      {progress.current && (
        <p className="truncate text-[11px] text-content-tertiary">
          {progress.current}
        </p>
      )}
    </div>
  );
}
