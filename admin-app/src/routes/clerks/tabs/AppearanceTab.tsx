import { Field, Input, Select, Textarea } from '@/components/ui/Field';
import type { AgentDetail, AgentInput } from '@/api/queries/useAgents';

interface TabProps {
  agent: AgentDetail;
  onChange: (patch: AgentInput) => void;
}

/**
 * How the widget looks and what it says around the conversation.
 *
 * The brand colour fills the launcher and the send button — surfaces that
 * carry white text — and is deliberately not used for link text. Sprint 5
 * shipped that split after a perfectly reasonable brand blue rendered
 * citation links at 3.0:1 on the dark surface.
 */
export function AppearanceTab({ agent, onChange }: TabProps) {
  const widget = agent.widget_config as {
    position?: string;
    accent?: string;
    radius?: number;
    theme?: string;
    launcher_label?: string;
    subtitle?: string;
    show_badge?: boolean;
    handoff_message?: string;
  };

  const set = (patch: Record<string, unknown>) =>
    onChange({ widget_config: { ...agent.widget_config, ...patch } });

  return (
    <div className="space-y-6">
      <Field label="Avatar" hint="A square image, 128px or larger. Left empty, the clerk shows its initial.">
        {({ id }) => (
          <Input
            id={id}
            type="url"
            value={agent.avatar_url ?? ''}
            placeholder="https://example.com/ada.png"
            onChange={(event) => onChange({ avatar_url: event.target.value })}
          />
        )}
      </Field>

      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Brand colour" hint="Fills the launcher and the send button.">
          {({ id }) => (
            <div className="flex items-center gap-2">
              <input
                type="color"
                id={id}
                value={widget.accent ?? '#4F46E5'}
                onChange={(event) => set({ accent: event.target.value })}
                className="h-9 w-12 cursor-pointer rounded-lg border border-border bg-surface-sunken"
              />
              <Input
                mono
                value={widget.accent ?? '#4F46E5'}
                aria-label="Brand colour hex"
                onChange={(event) => set({ accent: event.target.value })}
              />
            </div>
          )}
        </Field>

        <Field label="Corner">
          {({ id }) => (
            <Select
              id={id}
              value={widget.position ?? 'bottom-right'}
              onChange={(event) => set({ position: event.target.value })}
            >
              <option value="bottom-right">Bottom right</option>
              <option value="bottom-left">Bottom left</option>
            </Select>
          )}
        </Field>

        <Field label="Colour scheme" hint="Auto follows the visitor's own setting.">
          {({ id }) => (
            <Select
              id={id}
              value={widget.theme ?? 'auto'}
              onChange={(event) => set({ theme: event.target.value })}
            >
              <option value="auto">Auto</option>
              <option value="light">Light</option>
              <option value="dark">Dark</option>
            </Select>
          )}
        </Field>

        <Field label="Corner radius" hint="0 to 32 pixels.">
          {({ id }) => (
            <Input
              id={id}
              type="number"
              min={0}
              max={32}
              value={widget.radius ?? 16}
              onChange={(event) => set({ radius: Number(event.target.value) })}
            />
          )}
        </Field>
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Launcher label" hint="Shown beside the icon. Keep it short.">
          {({ id }) => (
            <Input
              id={id}
              maxLength={40}
              value={widget.launcher_label ?? ''}
              placeholder="Ask us anything"
              onChange={(event) => set({ launcher_label: event.target.value })}
            />
          )}
        </Field>

        <Field label="Subtitle" hint="Under the clerk's name in the panel header.">
          {({ id }) => (
            <Input
              id={id}
              maxLength={60}
              value={widget.subtitle ?? ''}
              placeholder="Usually replies instantly"
              onChange={(event) => set({ subtitle: event.target.value })}
            />
          )}
        </Field>
      </div>

      <Field
        label="When a visitor asks for a person"
        hint="Shown in the chat the moment they ask. It is a promise about what happens next, so it is written by you rather than generated."
      >
        {({ id, describedBy }) => (
          <Textarea
            id={id}
            rows={2}
            value={widget.handoff_message ?? ''}
            aria-describedby={describedBy}
            placeholder="I've passed this to a colleague. They'll reply here."
            onChange={(event) => set({ handoff_message: event.target.value })}
          />
        )}
      </Field>

      <label className="flex items-start gap-3">
        <input
          type="checkbox"
          className="mt-0.5 size-4 accent-[var(--hvc-accent)]"
          checked={widget.show_badge !== false}
          onChange={(event) => set({ show_badge: event.target.checked })}
        />
        <span>
          <span className="block text-sm font-medium text-content">
            Show the Hiveclerk badge
          </span>
          <span className="mt-1 block text-xs leading-relaxed text-content-secondary">
            A small line under the panel. Removing it entirely is part of
            white-label mode, which is a licence setting rather than this one.
          </span>
        </span>
      </label>
    </div>
  );
}
