import type { DutyStatus } from '@/components/ui/StatusDot';
import type { AgentSummary } from '@/api/queries/useAgents';

/**
 * How a clerk's stored status reads on the roster.
 *
 * A published clerk whose budget is spent shows as needing attention
 * rather than as on duty. It is technically published and practically
 * mute, and "on duty" next to a clerk that is answering with its fallback
 * message is the roster telling the operator something untrue.
 */
export function dutyStatus(agent: Pick<AgentSummary, 'status' | 'budget'>): DutyStatus {
  if (agent.budget.blocking) {
    return 'error';
  }

  switch (agent.status) {
    case 'published':
      return 'on_duty';
    case 'paused':
    case 'archived':
      return 'paused';
    default:
      return 'draft';
  }
}
