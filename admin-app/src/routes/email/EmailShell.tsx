import { Outlet } from 'react-router-dom';
import { Mail, Send } from 'lucide-react';
import { Tabs } from '@/components/ui/Tabs';

/**
 * The email area (FR-EML-01…08).
 *
 * Sequences are what an operator builds; the log is what actually went
 * out. Keeping them apart matters because they answer opposite questions
 * — one is "what should happen", the other is "what did", and a screen
 * that mixed them would make it impossible to tell a plan from a fact.
 */
export function EmailShell() {
  return (
    <div className="space-y-5">
      <div className="border-b border-border">
        <Tabs
          items={[
            {
              label: 'Sequences',
              to: '/email/sequences',
              icon: <Mail size={14} aria-hidden="true" />,
            },
            {
              label: 'Sent',
              to: '/email/log',
              icon: <Send size={14} aria-hidden="true" />,
            },
          ]}
        />
      </div>

      <Outlet />
    </div>
  );
}
