import { Outlet } from 'react-router-dom';
import { FlaskConical, Library, Sparkles } from 'lucide-react';
import { Tabs } from '@/components/ui/Tabs';

/**
 * The knowledge area.
 *
 * Three tabs rather than one screen with panels: each answers a different
 * question and an operator arrives knowing which one they have. Sources
 * is "what does my clerk know"; Playground is "why did it answer that";
 * Embedding is "what is this costing and which model produced it".
 */
export function KnowledgeShell() {
  return (
    <div className="space-y-5">
      <div className="border-b border-border">
        <Tabs
          items={[
            {
              label: 'Sources',
              to: '/knowledge/sources',
              icon: <Library size={14} aria-hidden="true" />,
            },
            {
              label: 'Playground',
              to: '/knowledge/playground',
              icon: <FlaskConical size={14} aria-hidden="true" />,
            },
            {
              label: 'Embedding',
              to: '/knowledge/embedding',
              icon: <Sparkles size={14} aria-hidden="true" />,
            },
          ]}
        />
      </div>

      <Outlet />
    </div>
  );
}
