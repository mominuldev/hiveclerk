import { Outlet } from 'react-router-dom';
import { ErrorBoundary } from '@/components/ErrorBoundary';
import { Columns3, SlidersHorizontal, Table2 } from 'lucide-react';
import { Tabs } from '@/components/ui/Tabs';

/**
 * The leads area (D11 §6).
 *
 * Three routed views over one dataset, each answering a different
 * question: Pipeline is "what is in play", Table is "who is there", and
 * Scoring is "why does anyone have the number they have". Routed rather
 * than in-page state because each is something an operator sends a
 * colleague a link to.
 *
 * No heading of its own — the app shell's header already names the
 * section, and a screen that says "Leads" twice above the fold is a
 * screen that has stopped reading itself.
 */
export function LeadsShell() {
  return (
    <div className="space-y-5">
      <div className="border-b border-border">
        <Tabs
          items={[
            {
              label: 'Pipeline',
              to: '/leads/pipeline',
              icon: <Columns3 size={14} aria-hidden="true" />,
            },
            {
              label: 'Table',
              to: '/leads/table',
              icon: <Table2 size={14} aria-hidden="true" />,
            },
            {
              label: 'Scoring',
              to: '/leads/scoring',
              icon: <SlidersHorizontal size={14} aria-hidden="true" />,
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
