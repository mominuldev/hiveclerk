import { Outlet } from 'react-router-dom';
import { KeyRound, Palette, ScrollText, Shield, Sparkles } from 'lucide-react';
import { Tabs } from '@/components/ui/Tabs';

/**
 * The settings area.
 *
 * Privacy is still absent rather than listed: showing a tab that opens an
 * empty screen is a worse lie than showing none and saying when it
 * arrives.
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
              label: 'Audit log',
              to: '/settings/audit',
              icon: <ScrollText size={14} aria-hidden="true" />,
            },
          ]}
        />
      </div>

      <Outlet />

      <p className="flex items-center gap-1.5 text-xs text-content-tertiary">
        <KeyRound size={12} aria-hidden="true" />
        Provider and licence keys are stored encrypted and are never returned
        by the API. Privacy settings arrive in Sprint 10.
      </p>
    </div>
  );
}
