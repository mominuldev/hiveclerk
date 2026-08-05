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
 * Where the visitor identifier is kept.
 *
 * localStorage rather than sessionStorage, and it is the only thing in
 * the widget that outlives a tab. "Visited pricing twice" is a scoring
 * rule, and a per-tab identifier would make every visit the first one.
 *
 * It is a random uuid the server minted and nothing else — no
 * fingerprint, no cookie, nothing derived from the device. A visitor who
 * clears it is a new visitor, which is the correct outcome for a plugin
 * whose promise is that the data stays on the customer's own server.
 */
const VISITOR_KEY = 'hvc.visitor';

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

function readLocal(key: string): string | null {
  try {
    return window.localStorage.getItem(key);
  } catch {
    return null;
  }
}

function writeLocal(key: string, value: string): void {
  try {
    window.localStorage.setItem(key, value);
  } catch {
    /* Without storage the visitor is new on every page. Still works. */
  }
}

/**
 * Where a consent decision is remembered.
 *
 * localStorage, not sessionStorage: re-asking on every page view would
 * make the gate feel like a cookie banner that never learns, and a
 * visitor who cleared their storage genuinely is somebody the site has
 * no record of agreeing.
 *
 * Keyed by origin rather than by clerk, which localStorage does for us.
 * Consent is given to the site's data handling, not to a particular
 * clerk, and asking again because a different clerk serves the pricing
 * page would be asking the same question twice.
 */
const CONSENT_KEY = 'hvc.consent';

/** Whether this visitor has already accepted the site's consent notice. */
export function accepted(): boolean {
  return readLocal(CONSENT_KEY) === '1';
}

/** Record that they accepted. */
export function remember(): void {
  writeLocal(CONSENT_KEY, '1');
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
        // Sent so the conversation carries the visit history that led to
        // it. Without this a lead's timeline starts at "lead captured".
        visitor: readLocal(VISITOR_KEY),
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

  /**
   * Ask for a person (FR-WGT-07).
   *
   * Returns whether the request was accepted. Repeating it is safe: the
   * server treats a second ask as the same ask, so a visitor pressing the
   * button twice does not email the site owner twice.
   */
  async handoff(): Promise<boolean> {
    if (!this.session) {
      return false;
    }

    const response = await fetch(`${this.boot.rest_url}/public/chat/handoff`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-HVC-Session': this.session.token,
      },
      body: JSON.stringify({ url: window.location.href }),
    });

    if (response.status === 401) {
      this.forget();
    }

    return response.ok;
  }

  /** Restore the transcript after a page navigation. */
  async history(): Promise<ChatMessage[]> {
    return (await this.transcript()).messages;
  }

  /**
   * The transcript and what state the conversation is in.
   *
   * The status rides along with the messages because the widget needs
   * both at exactly the same moments — on open, and on every poll while a
   * colleague is answering — and two round trips for one screen state is
   * one more than the visitor's connection deserves.
   */
  async transcript(): Promise<{
    messages: ChatMessage[];
    awaitingHuman: boolean;
    humanActive: boolean;
  }> {
    if (!this.session) {
      return { messages: [], awaitingHuman: false, humanActive: false };
    }

    const response = await fetch(`${this.boot.rest_url}/public/chat/history`, {
      headers: { 'X-HVC-Session': this.session.token },
    });

    if (!response.ok) {
      if (response.status === 401) {
        this.forget();
      }

      return { messages: [], awaitingHuman: false, humanActive: false };
    }

    const body = (await response.json()) as {
      data: {
        messages: Array<{
          id: string;
          role: 'visitor' | 'clerk';
          from_human?: boolean;
          text: string;
          citations: Citation[];
          rating: number | null;
        }>;
        awaiting_human?: boolean;
        status?: string;
      };
    };

    return {
      messages: body.data.messages.map((message) => ({
        id: message.id,
        role: message.role,
        text: message.text,
        citations: message.citations ?? [],
        fromHuman: message.from_human === true,
        rating: message.rating === 1 ? 1 : message.rating === -1 ? -1 : null,
      })),
      awaitingHuman: body.data.awaiting_human === true,
      humanActive: body.data.status === 'handoff_active',
    };
  }

  /**
   * Tell the server this page was seen (FR-LED-07).
   *
   * Fire and forget, and silent on failure. Telemetry that surfaces an
   * error to a visitor reading a blog post is worse than telemetry that
   * is missing, and every page-context scoring rule already treats an
   * absent count as zero.
   */
  async pageView(): Promise<void> {
    try {
      const response = await fetch(`${this.boot.rest_url}/public/events`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          type: 'page_view',
          visitor: readLocal(VISITOR_KEY),
          url: window.location.href,
          title: document.title,
          language: navigator.language,
        }),
      });

      if (response.status !== 200) {
        return;
      }

      const body = (await response.json()) as { data?: { visitor?: string } };

      if (body.data?.visitor) {
        writeLocal(VISITOR_KEY, body.data.visitor);
      }
    } catch {
      /* No network, no page view. Nothing on screen changes. */
    }
  }

  /**
   * Submit the in-chat capture form (FR-LED-01).
   *
   * The response says only whether it was accepted. A score, a stage or a
   * lead id echoed back to the browser would be the customer's own
   * commercial assessment of the person reading it.
   */
  async capture(fields: {
    email?: string;
    first_name?: string;
    phone?: string;
    company?: string;
    consent?: boolean;
  }): Promise<boolean> {
    const token = await this.token();

    const response = await fetch(`${this.boot.rest_url}/public/leads`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-HVC-Session': token,
      },
      body: JSON.stringify(fields),
    });

    if (response.status === 401) {
      this.forget();
    }

    return response.ok;
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
