import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { Skeleton } from '@/components/ui/Skeleton';
import { BarRow } from '@/components/charts/BarRow';
import { useTopics } from '@/api/queries/useAnalytics';
import { useReportFilters } from './AnalyticsShell';

/**
 * What visitors arrive asking.
 *
 * The label on each row is a question somebody actually typed, not the
 * reduced key the grouping used. A wrong grouping is then visible to the
 * reader rather than asserted as a category — which matters, because the
 * grouping is word-overlap and it will sometimes be wrong.
 */
export function Topics() {
  const filters = useReportFilters();
  const { data, isPending, isError, error, refetch } = useTopics(filters);

  if (isError) {
    return <ErrorNotice error={error} onRetry={() => void refetch()} />;
  }

  if (isPending) {
    return <Skeleton className="h-[320px] w-full rounded-xl" />;
  }

  if (0 === data.topics.length) {
    return (
      <Card>
        <EmptyState
          title="No questions in this period."
          description="This counts the first thing each visitor types — the question they came with, rather than the follow-ups."
        />
      </Card>
    );
  }

  const most = Math.max(...data.topics.map((topic) => topic.count), 1);

  return (
    <Card
      eyebrow="Topics"
      title="The questions visitors arrive with"
      actions={
        data.sampled ? (
          // Said out loud. A list built from a slice of a busy month is
          // useful; one that implies it counted everything is not.
          <span className="text-xs text-content-tertiary">
            Sampled from the most recent conversations
          </span>
        ) : undefined
      }
    >
      <div className="space-y-1">
        {data.topics.map((topic) => (
          <BarRow
            key={topic.label}
            label={topic.label}
            value={`asked ${topic.count}×`}
            fraction={topic.count / most}
          />
        ))}
      </div>

      <p className="mt-5 text-xs leading-relaxed text-content-tertiary">
        Grouped by the words a question contains, so differently-worded
        versions of the same question count together. Each row is labelled
        with one visitor&rsquo;s actual wording.
      </p>
    </Card>
  );
}
