import { useNavigate, Outlet, useLocation } from 'react-router';
import { ErrorBoundary } from '@/components/ErrorBoundary';
import { Sidebar } from './Sidebar';
import { TopBar } from './TopBar';
import { ToastViewport } from '@/components/ui/Toast';
import { dutyStatus } from '@/routes/clerks/status';
import { useAgents } from '@/api/queries/useAgents';
import type { Clerk } from '@/stores/useRoster';

const PAGES: Record<string, { title: string; subtitle: string }> = {
  '/dashboard': {
    title: 'Dashboard',
    subtitle: 'How your clerks are performing',
  },
  '/clerks': {
    title: 'AI Employees',
    subtitle: 'Who is on duty, and what they cost',
  },
  '/conversations': {
    title: 'Conversations',
    subtitle: 'Everything visitors have said',
  },
  '/leads': { title: 'Leads', subtitle: 'Captured, scored and routed' },
  '/email': { title: 'Email', subtitle: 'Follow-up that stops when they reply' },
  '/knowledge': {
    title: 'Knowledge',
    subtitle: 'What your clerks can answer from',
  },
  '/integrations': { title: 'Integrations', subtitle: 'Where leads go next' },
  '/workflows': { title: 'Workflows', subtitle: 'Automation without code' },
  '/analytics': { title: 'Analytics', subtitle: 'Funnel, topics and spend' },
  '/onboarding': {
    title: 'Setup',
    subtitle: 'Five steps to a clerk on duty',
  },
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
  const navigate = useNavigate();

  // The rail is on every screen, so this query is the app's most-shared
  // piece of server state. React Query owns it; the rail's *selection*
  // lives in Zustand, because that is ephemeral UI and nothing else.
  const roster = useAgents();

  const clerks: Clerk[] =
    roster.data?.agents.map((agent) => ({
      uuid: agent.uuid,
      name: agent.name,
      role: agent.role_label,
      status: dutyStatus(agent),
    })) ?? [];
  const page = Object.entries(PAGES).find(([path]) =>
    pathname.startsWith(path)
  )?.[1] ?? { title: 'Dashboard', subtitle: 'How your clerks are performing' };

  return (
    <div className="flex h-[calc(100vh-32px)] overflow-hidden text-content">
      <Sidebar clerks={clerks} onHire={() => void navigate('/clerks')} />

      <div className="flex min-w-0 flex-1 flex-col">
        <TopBar title={page.title} subtitle={page.subtitle} />
        <main className="min-h-0 flex-1 overflow-y-auto">
          <div className="mx-auto w-full max-w-[var(--hvc-content-max)] px-6 py-6">
            {/*
              Inside the shell rather than around it, so a screen that
              throws leaves the sidebar, the roster and the top bar
              standing. A boundary wrapping the whole app would catch the
              same error and still leave the operator looking at a page
              with no way out of it but the browser's back button.

              Keyed on the path: navigating to another screen clears the
              error, because the failure belonged to the screen that
              threw and not to the session.
            */}
            <ErrorBoundary resetKey={pathname}>
              <Outlet />
            </ErrorBoundary>
          </div>
        </main>
      </div>

      <ToastViewport />
    </div>
  );
}
