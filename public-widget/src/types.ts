/**
 * The shapes the widget receives from the server.
 *
 * Written by hand rather than generated, and kept minimal on purpose: the
 * widget must degrade rather than break when the API adds a field, so
 * every optional value here is one the runtime is expected to cope with
 * being absent.
 */

export interface WidgetTheme {
  position: 'bottom-right' | 'bottom-left';
  accent: string;
  radius: number;
  theme: 'auto' | 'light' | 'dark';
  launcher: string;
  subtitle: string;
}

export interface WidgetAgent {
  uuid: string;
  name: string;
  avatar_url: string | null;
  greeting: string | null;
  widget_config: WidgetTheme;
  locale: string;
  branding: { show_badge: boolean; label: string };
}

export interface WidgetBoot {
  agent: WidgetAgent;
  capabilities: { streaming: boolean; handoff: boolean; feedback: boolean };
  consent: { required: boolean; text: string | null };
  /**
   * Lead capture, when the operator has turned it on.
   *
   * Optional because a widget built against this release has to keep
   * working against a server that predates it — and because a payload
   * cached for five minutes may have been built before the setting
   * changed.
   */
  capture?: { enabled: boolean; ask_after: number; consent: string | null };
  rest_url: string;
  version: string;
}

export interface Citation {
  title: string;
  url: string | null;
  heading_path: string;
  excerpt: string;
  score: number;
}

export interface ChatMessage {
  id: string;
  role: 'visitor' | 'clerk';
  text: string;
  citations: Citation[];
  /** Written by a person who took the conversation over, not by the clerk. */
  fromHuman?: boolean;
  /** True while tokens are still arriving. */
  streaming?: boolean;
  /** Set when the exchange failed; the widget offers a retry. */
  failed?: boolean;
  rating?: -1 | 1 | null;
}

/** How the reply is being delivered. Persisted for the whole session. */
export type Transport = 'sse' | 'poll';
