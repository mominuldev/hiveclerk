import { Outlet } from 'react-router';
import { ErrorBoundary } from '@/components/ErrorBoundary';
import { FlaskConical, Library, MessageCircleQuestion, Sparkles } from 'lucide-react';
import { useGaps } from '@/api/queries/useGaps';
import { Tabs } from '@/components/ui/Tabs';

/**
 * The knowledge area.
 *
 * Four tabs rather than one screen with panels: each answers a different
 * question and an operator arrives knowing which one they have. Sources
 * is "what does my clerk know"; Gaps is "what does it not"; Playground is
 * "why did it answer that"; Embedding is "what is this costing and which
 * model produced it".
 *
 * Gaps carries a count in the tab, because the number is the reason to
 * open it. A tab labelled only "Gaps" is one nobody clicks on the day it
 * matters.
 */
export function KnowledgeShell() {
  const { data } = useGaps('open', 1);

  return (
    <div className="space-y-5">
      <div className="border-b border-border">
        <Tabs
          items={[
            {
              label: 'Sources',
              to: '/knowledge/sources',
              icon: <Library size={14} aria-hidden="true" />,
            },
            {
              label: 'Gaps',
              to: '/knowledge/gaps',
              icon: <MessageCircleQuestion size={14} aria-hidden="true" />,
              ...(data?.counts.open ? { count: data.counts.open } : {}),
            },
            {
              label: 'Playground',
              to: '/knowledge/playground',
              icon: <FlaskConical size={14} aria-hidden="true" />,
            },
            {
              label: 'Embedding',
              to: '/knowledge/embedding',
              icon: <Sparkles size={14} aria-hidden="true" />,
            },
          ]}
        />
      </div>

      {/* A second boundary, inside the tab bar rather than around it.
          The shell-level one already keeps the sidebar alive; this keeps
          the tabs alive too, so a broken sub-screen leaves the operator
          one click from a working one instead of routing them back
          through the sidebar. */}
      <ErrorBoundary>
        <Outlet />
      </ErrorBoundary>
    </div>
  );
}
