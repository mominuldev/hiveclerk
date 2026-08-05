import { useNavigate } from 'react-router-dom';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';

interface PlaceholderProps {
  area: string;
  sprint: string;
  summary: string;
}

/**
 * A scaffolded screen that states what it will become and when.
 *
 * Sprint 1 ships the shell and the data layer, not the features. Saying so
 * plainly beats a fake dashboard that implies work which has not happened.
 */
export function Placeholder({ area, sprint, summary }: PlaceholderProps) {
  const navigate = useNavigate();

  return (
    <Card feature>
      <div className="mb-1 flex items-center justify-center">
        <span className="rounded-full border border-border bg-surface-sunken px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.12em] text-content-tertiary">
          {sprint}
        </span>
      </div>

      <EmptyState
        title={`${area} isn't built yet`}
        description={summary}
        action={
          <Button size="sm" onClick={() => navigate('/dashboard')}>
            Back to dashboard
          </Button>
        }
      />
    </Card>
  );
}
