/**
 * Everything the widget asks the server for.
 */

import type { Citation, ChatMessage, WidgetBoot } from '../types';

interface StoredSession {
  token: string;
  conversation: string;
  expires: number;
}

/** Where a session is kept between page views. */
const SESSION_KEY = 'hvc.session';

/** Where the transport verdict is kept. */
const TRANSPORT_KEY = 'hvc.transport';

/**
 * Session storage, defensively.
 *
 * Storage throws rather than returns null in a handful of real
 * situations — Safari's private mode historically, and any browser with
 * cookies blocked for the site. A widget that crashes on a storage read
 * takes the customer's page with it, so every access is guarded and the
 * fallback is simply a session that does not survive navigation.
 */
function readStore(key: string): string | null {
  try {
    return window.sessionStorage.getItem(key);
  } catch {
    return null;
  }
}

function writeStore(key: string, value: string): void {
  try {
    window.sessionStorage.setItem(key, value);
  } catch {
    /* A session that lasts one page view is still a session. */
  }
}

export class Api {
  private session: StoredSession | null = null;

  constructor(private readonly boot: WidgetBoot) {
    this.session = this.restore();
  }

  /** The session token, obtaining one if needed. */
  async token(): Promise<string> {
    if (this.session && this.session.expires > Date.now() + 30_000) {
      return this.session.token;
    }

    const response = await fetch(`${this.boot.rest_url}/public/session`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        agent: this.boot.agent.uuid,
        url: window.location.href,
        title: document.title,
        language: navigator.language,
      }),
    });

    if (!response.ok) {
      throw new Error(`session ${response.status}`);
    }

    const body = (await response.json()) as {
      data: { session: string; conversation: string; expires_at: string | null };
    };

    const session: StoredSession = {
      token: body.data.session,
      conversation: body.data.conversation,
      expires: body.data.expires_at ? Date.parse(body.data.expires_at) : Date.now() + 3_600_000,
    };

    this.session = session;
    writeStore(this.key(), JSON.stringify(session));

    return session.token;
  }

  /** The conversation this session is attached to, if one is open. */
  conversation(): string | null {
    return this.session?.conversation ?? null;
  }

  /** Discard the session. Called when the server says it has expired. */
  forget(): void {
    this.session = null;

    try {
      window.sessionStorage.removeItem(this.key());
    } catch {
      /* Nothing to clean up if storage is unavailable. */
    }
  }

  /** The transport this session settled on, if it has. */
  transport(): 'sse' | 'poll' | null {
    const value = readStore(TRANSPORT_KEY);

    return value === 'poll' || value === 'sse' ? value : null;
  }

  /**
   * Remember that streaming did not work here.
   *
   * Recorded per session, not per message. The detection costs a 2.5
   * second wait, and a host that buffered the first reply will buffer
   * every reply — paying that wait on each message would make the
   * fallback more annoying than the problem.
   */
  rememberTransport(transport: 'sse' | 'poll'): void {
    writeStore(TRANSPORT_KEY, transport);
  }

  /** Restore the transcript after a page navigation. */
  async history(): Promise<ChatMessage[]> {
    if (!this.session) {
      return [];
    }

    const response = await fetch(`${this.boot.rest_url}/public/chat/history`, {
      headers: { 'X-HVC-Session': this.session.token },
    });

    if (!response.ok) {
      if (response.status === 401) {
        this.forget();
      }

      return [];
    }

    const body = (await response.json()) as {
      data: {
        messages: Array<{
          id: string;
          role: 'visitor' | 'clerk';
          text: string;
          citations: Citation[];
          rating: number | null;
        }>;
      };
    };

    return body.data.messages.map((message) => ({
      id: message.id,
      role: message.role,
      text: message.text,
      citations: message.citations ?? [],
      rating: message.rating === 1 ? 1 : message.rating === -1 ? -1 : null,
    }));
  }

  /** Record a thumbs up or down. */
  async rate(messageId: string, rating: -1 | 1): Promise<void> {
    if (!this.session) {
      return;
    }

    await fetch(`${this.boot.rest_url}/public/chat/feedback`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-HVC-Session': this.session.token,
      },
      body: JSON.stringify({ message: messageId, rating }),
    });
  }

  /** Base URL for the chat routes. */
  url(path: string): string {
    return `${this.boot.rest_url}${path}`;
  }

  private key(): string {
    return `${SESSION_KEY}.${this.boot.agent.uuid}`;
  }

  private restore(): StoredSession | null {
    const raw = readStore(this.key());

    if (!raw) {
      return null;
    }

    try {
      const parsed = JSON.parse(raw) as StoredSession;

      return parsed.expires > Date.now() ? parsed : null;
    } catch {
      return null;
    }
  }
}
