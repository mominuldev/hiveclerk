import { create } from 'zustand';
import type { DutyStatus } from '@/components/ui/StatusDot';

export interface Clerk {
  uuid: string;
  name: string;
  role: string;
  status: DutyStatus;
}

interface RosterState {
  /** Selected clerk, or null for "everyone". */
  selected: string | null;
  select: (uuid: string | null) => void;
}

/**
 * The Roster selection is a persistent filter, not navigation.
 *
 * Picking a clerk narrows whichever screen you are on rather than taking you
 * somewhere else, and the choice survives moving between screens. Server
 * state (the clerks themselves) belongs to React Query; only this ephemeral
 * UI selection lives here.
 */
export const useRoster = create<RosterState>((set) => ({
  selected: null,
  select: (uuid) => set({ selected: uuid }),
}));
