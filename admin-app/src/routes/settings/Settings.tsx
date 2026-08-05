import { Outlet } from 'react-router-dom';
import { KeyRound, ScrollText, Shield, Sparkles } from 'lucide-react';
import { Tabs } from '@/components/ui/Tabs';

/**
 * The settings area.
 *
 * Providers and the audit log are real in Sprint 2. Privacy and branding
 * are listed but not linked — showing a tab that opens an empty screen
 * would be a worse lie than showing one that says when it arrives.
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
              label: 'Audit log',
              to: '/settings/audit',
              icon: <ScrollText size={14} aria-hidden="true" />,
            },
          ]}
        />
      </div>

      <Outlet />

      <p className="flex items-center gap-1.5 text-xs text-content-tertiary">
        <Shield size={12} aria-hidden="true" />
        Privacy, branding and licence settings arrive in Sprint 9.
        <KeyRound size={12} aria-hidden="true" className="ml-1" />
        Keys are stored encrypted and are never returned by the API.
      </p>
    </div>
  );
}
