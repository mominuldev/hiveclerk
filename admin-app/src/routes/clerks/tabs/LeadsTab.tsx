import { EmptyState } from '@/components/ui/EmptyState';
import type { AgentDetail } from '@/api/queries/useAgents';

/**
 * Lead capture and qualification, which land in Sprint 7.
 *
 * The tab exists because the editor's shape is six tabs and hiding one
 * would make the screen change under an operator between releases. What
 * it does not do is show controls that save nothing: a qualification
 * question box wired to an endpoint that does not exist is worse than an
 * empty state, because the operator would fill it in and believe it.
 */
export function LeadsTab({ agent }: { agent: AgentDetail }) {
  return (
    <EmptyState
      bare
      title="Lead capture is not built yet"
      description={`${agent.name} can already be asked for an email in its instructions, and the conversation is stored either way. Qualification questions, scoring rules and the pipeline arrive in the next release — and this tab will hold them.`}
    />
  );
}
