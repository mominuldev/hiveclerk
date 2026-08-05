import type { DutyStatus } from '@/components/ui/StatusDot';
import type { AgentSummary } from '@/api/queries/useAgents';

/**
 * The stored lifecycle status alone, translated to a duty status.
 *
 * Separate from `dutyStatus` because the analytics reports carry a clerk
 * summary with no budget on it, and the two vocabularies are not the same
 * one: `AgentStatus` is a lifecycle (`published`), `DutyStatus` is what an
 * operator sees (`on_duty`). Every payload carrying a stored status must
 * pass through here — handing one straight to StatusDot misses its lookup
 * table and throws, and with no error boundary in the app that blanks the
 * whole admin, not just the panel.
 */
export function storedDutyStatus(status: AgentSummary['status']): DutyStatus {
  switch (status) {
    case 'published':
      return 'on_duty';
    case 'paused':
    case 'archived':
      return 'paused';
    default:
      return 'draft';
  }
}

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

  return storedDutyStatus(agent.status);
}
