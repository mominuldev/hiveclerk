import { NavLink } from 'react-router';
import {
  BarChart3,
  BookOpen,
  LayoutDashboard,
  Mail,
  MessagesSquare,
  Plug,
  Settings,
  UserRoundCheck,
  Users,
  Workflow,
} from 'lucide-react';
import { RosterRail } from './RosterRail';
import { Logomark, Wordmark } from '@/components/brand/Logomark';
import { boot } from '@/boot';
import type { Clerk } from '@/stores/useRoster';
import { cn } from '@/lib/cn';

const NAV = [
  { to: '/dashboard', label: 'Dashboard', icon: LayoutDashboard },
  { to: '/clerks', label: 'AI Employees', icon: Users },
  { to: '/conversations', label: 'Conversations', icon: MessagesSquare },
  { to: '/leads', label: 'Leads', icon: UserRoundCheck },
  { to: '/email', label: 'Email', icon: Mail },
  { to: '/knowledge', label: 'Knowledge', icon: BookOpen },
  { to: '/integrations', label: 'Integrations', icon: Plug },
  { to: '/workflows', label: 'Workflows', icon: Workflow },
  { to: '/analytics', label: 'Analytics', icon: BarChart3 },
  { to: '/settings', label: 'Settings', icon: Settings },
] as const;

interface SidebarProps {
  clerks: Clerk[];
  onHire?: () => void;
}

export function Sidebar({ clerks, onHire }: SidebarProps) {
  const { branding, licence } = boot();

  return (
    <aside
      className="relative flex h-full w-[var(--hvc-sidebar-width)] shrink-0 flex-col border-r border-border bg-surface/70 backdrop-blur-xl"
      aria-label="Main"
    >
      <div className="flex h-[var(--hvc-header-height)] items-center gap-2.5 px-4">
        <Logomark />
        <Wordmark name={branding.productName} />
      </div>

      <div className="hvc-hairline-x mx-3 h-px" />

      <nav aria-label="Sections" className="px-2.5 pt-3">
        <ul className="space-y-0.5">
          {NAV.map(({ to, label, icon: Icon }) => (
            <li key={to}>
              <NavLink
                to={to}
                className={({ isActive }) =>
                  cn(
                    'group relative flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm',
                    'transition-colors duration-[var(--hvc-duration-fast)]',
                    isActive
                      ? 'bg-surface-hover font-medium text-content'
                      : 'text-content-secondary hover:bg-surface-hover hover:text-content'
                  )
                }
              >
                {({ isActive }) => (
                  <>
                    {/* Spectral indicator: the one structural use of the
                        brand surface outside the mark itself. */}
                    <span
                      aria-hidden="true"
                      className={cn(
                        'hvc-gradient-brand absolute left-0 top-1/2 w-[3px] -translate-y-1/2 rounded-full transition-all duration-[var(--hvc-duration-base)]',
                        isActive ? 'h-5 opacity-100' : 'h-0 opacity-0'
                      )}
                    />
                    <Icon
                      size={16}
                      aria-hidden="true"
                      className={cn(
                        'shrink-0 transition-colors',
                        isActive ? 'text-accent' : 'text-content-tertiary group-hover:text-content-secondary'
                      )}
                    />
                    <span className="flex-1">{label}</span>
                  </>
                )}
              </NavLink>
            </li>
          ))}
        </ul>
      </nav>

      <div className="hvc-hairline-x mx-3 my-3 h-px" />

      <RosterRail clerks={clerks} {...(onHire ? { onHire } : {})} />

      <div className="mt-auto px-4 py-3.5">
        <div className="hvc-hairline-x mb-3 h-px" />

        <NavLink
          to="/settings/licence"
          className={cn(
            'flex items-center justify-between gap-2 rounded-lg px-1.5 py-1',
            'transition-colors hover:bg-surface-hover',
            'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent'
          )}
        >
          <span className="text-xs text-content-tertiary">
            <span className="text-content-secondary">{licence.tier_label}</span>
            {' · '}
            {licence.sites} {licence.sites === 1 ? 'site' : 'sites'}
          </span>

          {/* The tier bought and the tier in force differ once a licence
              lapses, and the second is the one that explains why a screen
              is refusing. */}
          {licence.tier !== licence.effective_tier ? (
            <span className="text-xs font-semibold text-[var(--hvc-warning-ink)]">
              {licence.status_label}
            </span>
          ) : (
            licence.effective_tier === 'free' && (
              <span className="hvc-gradient-text text-xs font-semibold">
                Upgrade
              </span>
            )
          )}
        </NavLink>
      </div>
    </aside>
  );
}
