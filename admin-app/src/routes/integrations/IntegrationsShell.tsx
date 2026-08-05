import { Outlet } from 'react-router-dom';
import { ErrorBoundary } from '@/components/ErrorBoundary';
import { History, Plug } from 'lucide-react';
import { Tabs } from '@/components/ui/Tabs';

/**
 * The integrations area (D11 §8).
 *
 * Two views: the grid of connectors, and the log of what each one
 * actually did. Routed rather than in-page state, because "look at the
 * sync log" is a sentence somebody says over a support ticket and a link
 * is a better answer than instructions.
 */
export function IntegrationsShell() {
  return (
    <div className="space-y-5">
      <div className="border-b border-border">
        <Tabs
          items={[
            {
              label: 'Connectors',
              to: '/integrations/connectors',
              icon: <Plug size={14} aria-hidden="true" />,
            },
            {
              label: 'Sync log',
              to: '/integrations/log',
              icon: <History size={14} aria-hidden="true" />,
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
