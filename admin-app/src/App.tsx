import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { HashRouter, Navigate, Route, Routes } from 'react-router-dom';
import { AppShell } from '@/components/layout/AppShell';
import { Dashboard } from '@/routes/Dashboard';
import { Placeholder } from '@/routes/Placeholder';
import { Settings } from '@/routes/settings/Settings';
import { Providers } from '@/routes/settings/Providers';
import { AuditLog } from '@/routes/settings/AuditLog';
import { Knowledge } from '@/routes/knowledge/Knowledge';
import { KnowledgeShell } from '@/routes/knowledge/KnowledgeShell';
import { Playground } from '@/routes/knowledge/Playground';
import { EmbeddingSettings } from '@/routes/knowledge/EmbeddingSettings';

/*
 * A hash router avoids rewrite rules and server configuration entirely,
 * which cannot be guaranteed across the hosting landscape we target.
 */

const client = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      retry: (failureCount, error) => {
        // Auth and permission failures never succeed on retry.
        const status = (error as { status?: number }).status;
        if (status === 401 || status === 403 || status === 404) {
          return false;
        }
        return failureCount < 2;
      },
      refetchOnWindowFocus: false,
    },
  },
});

const SCAFFOLDED = [
  {
    path: 'conversations',
    area: 'Conversations',
    sprint: 'Sprint 6',
    summary:
      'Transcripts with inline citations, cost per message, and human takeover.',
  },
  {
    path: 'leads',
    area: 'Leads',
    sprint: 'Sprint 7',
    summary:
      'Pipeline board, attributed score breakdowns, and qualification rules.',
  },
  {
    path: 'integrations',
    area: 'Integrations',
    sprint: 'Sprint 8',
    summary: 'FluentCRM, Groundhogg and HubSpot with field mapping and a sync log.',
  },
  {
    path: 'workflows',
    area: 'Workflows',
    sprint: 'Version 2.0',
    summary:
      'Triggers, conditions, actions and branching. Deliberately out of scope for V1.',
  },
  {
    path: 'analytics',
    area: 'Analytics',
    sprint: 'Sprint 9',
    summary: 'Funnel, topics, per-clerk performance and spend, each with a written finding.',
  },
] as const;

export function App() {
  return (
    <QueryClientProvider client={client}>
      <HashRouter>
        <Routes>
          <Route element={<AppShell />}>
            <Route index element={<Navigate to="/dashboard" replace />} />
            <Route path="dashboard" element={<Dashboard />} />
            <Route path="knowledge" element={<KnowledgeShell />}>
              {/* Redirect rather than an index element, so the tab bar
                  always has an active tab and a bookmarked /knowledge
                  still lands somewhere nameable. */}
              <Route index element={<Navigate to="sources" replace />} />
              <Route path="sources" element={<Knowledge />} />
              <Route path="playground" element={<Playground />} />
              <Route path="embedding" element={<EmbeddingSettings />} />
            </Route>

            <Route path="settings" element={<Settings />}>
              <Route index element={<Navigate to="providers" replace />} />
              <Route path="providers" element={<Providers />} />
              <Route path="audit" element={<AuditLog />} />
            </Route>

            {SCAFFOLDED.map(({ path, area, sprint, summary }) => (
              <Route
                key={path}
                path={path}
                element={
                  <Placeholder area={area} sprint={sprint} summary={summary} />
                }
              />
            ))}

            <Route path="*" element={<Navigate to="/dashboard" replace />} />
          </Route>
        </Routes>
      </HashRouter>
    </QueryClientProvider>
  );
}
