/**
 * Delivery of a reply, by whichever route this host allows.
 */

import type { Api } from './api';
import type { Citation, Transport } from '../types';

export interface ReplyHandlers {
  onStart: (messageId: string) => void;
  onDelta: (text: string) => void;
  onReplace: (text: string) => void;
  onCitations: (citations: Citation[]) => void;
  onDone: () => void;
  onError: (message: string) => void;
}

/**
 * How long to wait for the first byte before giving up on streaming.
 *
 * The server sends a 4 KB comment and a `:probe` marker the instant the
 * connection opens, ahead of retrieval and any model call — so on a host
 * that streams, something arrives in tens of milliseconds. Nothing at all
 * after two and a half seconds means a buffer is holding the response, and
 * waiting longer only delays the fallback.
 *
 * @see docs/06-system-architecture.md §5.1
 */
const PROBE_TIMEOUT_MS = 2500;

/** How often the polling transport asks for more text. */
const POLL_INTERVAL_MS = 260;

/** How long polling waits for a reply before giving up. */
const POLL_TIMEOUT_MS = 90_000;

/**
 * Send a message and deliver the reply.
 *
 * Returns the transport that actually worked, so the caller can remember
 * it for the rest of the session.
 */
export async function send(
  api: Api,
  text: string,
  preferred: Transport,
  handlers: ReplyHandlers,
): Promise<Transport> {
  if (preferred === 'poll') {
    await poll(api, text, handlers);

    return 'poll';
  }

  const streamed = await stream(api, text, handlers);

  if (streamed) {
    return 'sse';
  }

  // The stream produced nothing before the probe deadline. Nothing has
  // been shown to the visitor yet, so the message can be sent again
  // down the other transport without them seeing a false start.
  await poll(api, text, handlers);

  return 'poll';
}

/**
 * Try the streaming transport.
 *
 * @returns false when the host buffered and the caller should fall back.
 */
async function stream(api: Api, text: string, handlers: ReplyHandlers): Promise<boolean> {
  const token = await api.token();
  const controller = new AbortController();

  let opened = false;

  const deadline = window.setTimeout(() => {
    if (!opened) {
      controller.abort();
    }
  }, PROBE_TIMEOUT_MS);

  try {
    const response = await fetch(api.url('/public/chat/stream'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'text/event-stream',
        'X-HVC-Session': token,
      },
      body: JSON.stringify({ message: text, url: window.location.href, title: document.title }),
      signal: controller.signal,
    });

    if (response.status === 401) {
      api.forget();
      window.clearTimeout(deadline);
      handlers.onError('expired');

      return true;
    }

    if (!response.ok || !response.body) {
      window.clearTimeout(deadline);

      return false;
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();

    let buffer = '';

    for (;;) {
      const { done, value } = await reader.read();

      if (done) {
        break;
      }

      // Any byte at all is the signal. The padding comment arrives before
      // the probe marker and before any parseable frame, and it is exactly
      // as good a proof that nothing is buffering.
      if (!opened) {
        opened = true;
        window.clearTimeout(deadline);
      }

      buffer += decoder.decode(value, { stream: true });

      const frames = buffer.split('\n\n');

      buffer = frames.pop() ?? '';

      for (const frame of frames) {
        dispatch(frame, handlers);
      }
    }

    window.clearTimeout(deadline);

    return opened;
  } catch {
    window.clearTimeout(deadline);

    // An abort here is the probe deadline firing, which is the fallback
    // signal rather than an error. A genuine network failure is
    // indistinguishable from it at this point and is handled the same
    // way — by trying the other transport, which will report properly.
    return false;
  }
}

/** Parse one SSE frame and route it. */
function dispatch(frame: string, handlers: ReplyHandlers): void {
  let event = 'message';
  let data = '';

  for (const line of frame.split('\n')) {
    if (line.startsWith(':')) {
      continue;
    }

    if (line.startsWith('event:')) {
      event = line.slice(6).trim();
    } else if (line.startsWith('data:')) {
      data += (data ? '\n' : '') + line.slice(5).replace(/^ /, '');
    }
  }

  if (!data) {
    return;
  }

  let payload: Record<string, unknown>;

  try {
    payload = JSON.parse(data) as Record<string, unknown>;
  } catch {
    return;
  }

  switch (event) {
    case 'start':
      handlers.onStart(String(payload.message_id ?? ''));
      break;
    case 'delta':
      handlers.onDelta(String(payload.text ?? ''));
      break;
    case 'replace':
      handlers.onReplace(String(payload.text ?? ''));
      break;
    case 'citations':
      handlers.onCitations((payload.citations as Citation[]) ?? []);
      break;
    case 'done':
      handlers.onDone();
      break;
    case 'error':
      handlers.onError(String(payload.message ?? ''));
      break;
    default:
      break;
  }
}

/**
 * The polling transport.
 *
 * The reference is generated here rather than taken from the POST's
 * response. That is what lets polling start immediately, in parallel with
 * a request that may itself be buffered — and a buffered POST is the
 * situation this transport exists for, so waiting for its body would
 * reintroduce the exact failure being worked around.
 */
async function poll(api: Api, text: string, handlers: ReplyHandlers): Promise<void> {
  const token = await api.token();
  const reference = uuid();

  let started = false;

  const post = fetch(api.url('/public/chat/message'), {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-HVC-Session': token,
    },
    body: JSON.stringify({
      message: text,
      reference,
      url: window.location.href,
      title: document.title,
    }),
  });

  // The failure is caught but not acted on here: the poll loop is the
  // thing that reports to the visitor, and letting the promise reject
  // unhandled would put a red error in the customer's console.
  post.catch(() => undefined);

  const until = Date.now() + POLL_TIMEOUT_MS;

  let cursor = 0;

  for (;;) {
    if (Date.now() > until) {
      handlers.onError('timeout');

      return;
    }

    await sleep(POLL_INTERVAL_MS);

    const response = await fetch(
      api.url(`/public/chat/poll?message=${reference}&cursor=${cursor}`),
      { headers: { 'X-HVC-Session': token } },
    );

    if (response.status === 401) {
      api.forget();
      handlers.onError('expired');

      return;
    }

    if (!response.ok) {
      continue;
    }

    const body = (await response.json()) as {
      data: {
        text: string;
        cursor: number;
        replaced: boolean;
        complete: boolean;
        pending?: boolean;
        citations: Citation[];
        message_id: string | null;
        error: { code: string; message: string } | null;
      };
    };

    const state = body.data;

    if (state.pending) {
      continue;
    }

    if (!started) {
      started = true;
      handlers.onStart(state.message_id ?? '');
    }

    if (state.replaced) {
      handlers.onReplace(state.text);
    } else if (state.text) {
      handlers.onDelta(state.text);
    }

    cursor = state.cursor;

    if (state.complete) {
      if (state.error) {
        handlers.onError(state.error.message);

        return;
      }

      if (state.citations?.length) {
        handlers.onCitations(state.citations);
      }

      handlers.onDone();

      return;
    }
  }
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => window.setTimeout(resolve, ms));
}

/**
 * A v4 UUID.
 *
 * `crypto.randomUUID` is unavailable on plain HTTP, which a fair number
 * of the sites this widget lands on still are, so the fallback is not
 * theoretical. It only has to be unique within one session's buffers.
 */
function uuid(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }

  const bytes = new Uint8Array(16);

  if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
    crypto.getRandomValues(bytes);
  } else {
    for (let i = 0; i < 16; i += 1) {
      bytes[i] = Math.floor(Math.random() * 256);
    }
  }

  bytes[6] = ((bytes[6] ?? 0) & 0x0f) | 0x40;
  bytes[8] = ((bytes[8] ?? 0) & 0x3f) | 0x80;

  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');

  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
