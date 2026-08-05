import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { HashRouter, Navigate, Route, Routes } from 'react-router-dom';
import { AppShell } from '@/components/layout/AppShell';
import { Dashboard } from '@/routes/Dashboard';
import { Placeholder } from '@/routes/Placeholder';
import { Settings } from '@/routes/settings/Settings';
import { Providers } from '@/routes/settings/Providers';
import { AuditLog } from '@/routes/settings/AuditLog';
import { Knowledge } from '@/routes/knowledge/Knowledge';
import { Clerks } from '@/routes/clerks/Clerks';
import { ClerkEditor } from '@/routes/clerks/ClerkEditor';
import { Conversations } from '@/routes/conversations/Conversations';
import { KnowledgeShell } from '@/routes/knowledge/KnowledgeShell';
import { LeadsShell } from '@/routes/leads/LeadsShell';
import { Pipeline } from '@/routes/leads/Pipeline';
import { LeadTable } from '@/routes/leads/LeadTable';
import { ScoringRules } from '@/routes/leads/ScoringRules';
import { Playground } from '@/routes/knowledge/Playground';
import { EmbeddingSettings } from '@/routes/knowledge/EmbeddingSettings';
import { IntegrationsShell } from '@/routes/integrations/IntegrationsShell';
import { Integrations } from '@/routes/integrations/Integrations';
import { SyncLog } from '@/routes/integrations/SyncLog';
import { EmailShell } from '@/routes/email/EmailShell';
import { Sequences } from '@/routes/email/Sequences';
import { SequenceBuilder } from '@/routes/email/SequenceBuilder';
import { EmailLog } from '@/routes/email/EmailLog';
import { AnalyticsShell } from '@/routes/analytics/AnalyticsShell';
import { AnalyticsOverview } from '@/routes/analytics/Overview';
import { ClerkReport } from '@/routes/analytics/ClerkReport';
import { Funnel } from '@/routes/analytics/Funnel';
import { Topics } from '@/routes/analytics/Topics';
import { Costs } from '@/routes/analytics/Costs';
import { Gaps } from '@/routes/knowledge/Gaps';
import { LicenceSettings } from '@/routes/settings/Licence';
import { Branding } from '@/routes/settings/Branding';
import { Wizard } from '@/routes/onboarding/Wizard';

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
    path: 'workflows',
    area: 'Workflows',
    sprint: 'Version 2.0',
    summary:
      'Triggers, conditions, actions and branching. Deliberately out of scope for V1.',
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
            <Route path="clerks" element={<Clerks />} />
            <Route path="clerks/:uuid" element={<ClerkEditor />} />
            <Route path="conversations" element={<Conversations />} />

            <Route path="leads" element={<LeadsShell />}>
              {/* Redirect rather than an index element, so the tab bar
                  always has an active tab and a bookmarked /leads still
                  lands somewhere nameable. */}
              <Route index element={<Navigate to="pipeline" replace />} />
              <Route path="pipeline" element={<Pipeline />} />
              <Route path="table" element={<LeadTable />} />
              <Route path="scoring" element={<ScoringRules />} />
            </Route>
            <Route path="integrations" element={<IntegrationsShell />}>
              {/* Redirect rather than an index element, so the tab bar
                  always has an active tab and a bookmarked /integrations
                  still lands somewhere nameable. */}
              <Route index element={<Navigate to="connectors" replace />} />
              <Route path="connectors" element={<Integrations />} />
              <Route path="log" element={<SyncLog />} />
            </Route>

            <Route path="email" element={<EmailShell />}>
              <Route index element={<Navigate to="sequences" replace />} />
              <Route path="sequences" element={<Sequences />} />
              <Route path="log" element={<EmailLog />} />
            </Route>
            {/* Outside the shell: the builder is a full-screen task, and
                a tab bar above it would offer to navigate away from
                unsaved copy. */}
            <Route path="email/sequences/:uuid" element={<SequenceBuilder />} />

            <Route path="knowledge" element={<KnowledgeShell />}>
              {/* Redirect rather than an index element, so the tab bar
                  always has an active tab and a bookmarked /knowledge
                  still lands somewhere nameable. */}
              <Route index element={<Navigate to="sources" replace />} />
              <Route path="sources" element={<Knowledge />} />
              <Route path="gaps" element={<Gaps />} />
              <Route path="playground" element={<Playground />} />
              <Route path="embedding" element={<EmbeddingSettings />} />
            </Route>

            <Route path="analytics" element={<AnalyticsShell />}>
              {/* Redirect rather than an index element, so the tab bar
                  always has an active tab and a bookmarked /analytics
                  still lands somewhere nameable. */}
              <Route index element={<Navigate to="overview" replace />} />
              <Route path="overview" element={<AnalyticsOverview />} />
              <Route path="clerks" element={<ClerkReport />} />
              <Route path="funnel" element={<Funnel />} />
              <Route path="topics" element={<Topics />} />
              <Route path="costs" element={<Costs />} />
            </Route>

            {/* Inside the shell rather than outside it: an operator who
                skipped setup and came back needs the way out that the
                sidebar provides, and a full-screen flow with no exit is
                a flow people leave by closing the tab. */}
            <Route path="onboarding" element={<Wizard />} />

            <Route path="settings" element={<Settings />}>
              <Route index element={<Navigate to="providers" replace />} />
              <Route path="providers" element={<Providers />} />
              <Route path="licence" element={<LicenceSettings />} />
              <Route path="branding" element={<Branding />} />
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
