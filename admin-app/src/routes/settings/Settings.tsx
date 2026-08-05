import { Outlet } from 'react-router';
import { ErrorBoundary } from '@/components/ErrorBoundary';
import { Activity, KeyRound, Lock, Palette, ScrollText, Shield, Sparkles } from 'lucide-react';
import { Tabs } from '@/components/ui/Tabs';

/**
 * The settings area.
 */
export function Settings() {
  return (
    <div className="space-y-5">
      <div className="border-b border-border">
        <Tabs
          items={[
            {
              label: 'Providers',
              to: '/settings/providers',
              icon: <Sparkles size={14} aria-hidden="true" />,
            },
            {
              label: 'Licence',
              to: '/settings/licence',
              icon: <Shield size={14} aria-hidden="true" />,
            },
            {
              label: 'Branding',
              to: '/settings/branding',
              icon: <Palette size={14} aria-hidden="true" />,
            },
            {
              label: 'Privacy',
              to: '/settings/privacy',
              icon: <Lock size={14} aria-hidden="true" />,
            },
            {
              label: 'System',
              to: '/settings/system',
              icon: <Activity size={14} aria-hidden="true" />,
            },
            {
              label: 'Audit log',
              to: '/settings/audit',
              icon: <ScrollText size={14} aria-hidden="true" />,
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

      <p className="flex items-center gap-1.5 text-xs text-content-tertiary">
        <KeyRound size={12} aria-hidden="true" />
        Provider and licence keys are stored encrypted and are never returned
        by the API.
      </p>
    </div>
  );
}
