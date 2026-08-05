import { Outlet, useLocation } from 'react-router-dom';
import { Sidebar } from './Sidebar';
import { TopBar } from './TopBar';
import { ToastViewport } from '@/components/ui/Toast';
import type { Clerk } from '@/stores/useRoster';

/**
 * Roster data arrives from the API in Sprint 6. Until then the rail renders
 * its real empty state rather than placeholder staff — showing invented
 * clerks would make the shell look finished when it is not.
 */
const CLERKS: Clerk[] = [];

const PAGES: Record<string, { title: string; subtitle: string }> = {
  '/dashboard': {
    title: 'Dashboard',
    subtitle: 'How your clerks are performing',
  },
  '/conversations': {
    title: 'Conversations',
    subtitle: 'Everything visitors have said',
  },
  '/leads': { title: 'Leads', subtitle: 'Captured, scored and routed' },
  '/knowledge': {
    title: 'Knowledge',
    subtitle: 'What your clerks can answer from',
  },
  '/integrations': { title: 'Integrations', subtitle: 'Where leads go next' },
  '/workflows': { title: 'Workflows', subtitle: 'Automation without code' },
  '/analytics': { title: 'Analytics', subtitle: 'Funnel, topics and spend' },
  '/settings/providers': {
    title: 'Settings',
    subtitle: 'Which model answers, and what it costs',
  },
  '/settings/audit': {
    title: 'Settings',
    subtitle: 'Every configuration change, and who made it',
  },
  '/settings': { title: 'Settings', subtitle: 'Providers, licence and privacy' },
};

export function AppShell() {
  const { pathname } = useLocation();
  const page = Object.entries(PAGES).find(([path]) =>
    pathname.startsWith(path)
  )?.[1] ?? { title: 'Dashboard', subtitle: 'How your clerks are performing' };

  return (
    <div className="flex h-[calc(100vh-32px)] overflow-hidden text-content">
      <Sidebar clerks={CLERKS} />

      <div className="flex min-w-0 flex-1 flex-col">
        <TopBar title={page.title} subtitle={page.subtitle} />
        <main className="min-h-0 flex-1 overflow-y-auto">
          <div className="mx-auto w-full max-w-[var(--hvc-content-max)] px-6 py-6">
            <Outlet />
          </div>
        </main>
      </div>

      <ToastViewport />
    </div>
  );
}
