/** @jsxImportSource preact */
/**
 * The widget.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'preact/hooks';
import { Api, accepted, remember } from './lib/api';
import { send as deliver } from './lib/transport';
import { strings } from './lib/i18n';
import { Bubble } from './components/Bubble';
import { Capture } from './components/Capture';
import { Composer } from './components/Composer';
import { Consent } from './components/Consent';
import { IconChat, IconClose, IconMinimise } from './components/Icons';
import type { ChatMessage, Transport, WidgetBoot } from './types';

interface Props {
  boot: WidgetBoot;
  host: HTMLElement;
}

export function Widget({ boot, host }: Props): preact.JSX.Element {
  const labels = useMemo(() => strings(boot.agent.locale), [boot.agent.locale]);
  const api = useMemo(() => new Api(boot), [boot]);

  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [awaitingHuman, setAwaitingHuman] = useState(false);
  const [humanActive, setHumanActive] = useState(false);
  const [captured, setCaptured] = useState(false);
  const [dismissed, setDismissed] = useState(false);

  /*
   * The site-wide consent gate. Read from storage on the first render
   * rather than in an effect, because an effect runs after the page-view
   * ping below would already have fired — and a telemetry row written
   * before the visitor agreed is the row the gate exists to prevent.
   */
  const consentRequired = boot.consent?.required ?? false;
  const [consented, setConsented] = useState(() => !consentRequired || accepted());
  const [declined, setDeclined] = useState(false);

  const log = useRef<HTMLDivElement | null>(null);
  const launcher = useRef<HTMLButtonElement | null>(null);
  const panel = useRef<HTMLDivElement | null>(null);
  const transport = useRef<Transport>(api.transport() ?? 'sse');

  // The greeting is a rendered message rather than a stored one. Storing
  // it would bill the visitor's first exchange for a turn nobody wrote and
  // put a clerk's own words into the prompt as history.
  const greeting = boot.agent.greeting?.trim();

  const visible = useMemo<ChatMessage[]>(() => {
    if (!greeting || messages.length > 0) {
      return messages;
    }

    return [{ id: 'greeting', role: 'clerk', text: greeting, citations: [], rating: null }];
  }, [greeting, messages]);

  /*
   * One page view, reported once, whether or not the panel is ever
   * opened. This is what makes "visited pricing twice" a thing a scoring
   * rule can count, and it is the only request the widget makes on a page
   * nobody chats on.
   */
  useEffect(() => {
    if (!consented) {
      return;
    }

    void api.pageView();
  }, [api, consented]);

  /* Restore a transcript when the panel is opened on a later page view. */
  useEffect(() => {
    if (!open || !consented || messages.length > 0 || !api.conversation()) {
      return;
    }

    void api.transcript().then((restored) => {
      if (restored.messages.length > 0) {
        setMessages(restored.messages);
      }

      setAwaitingHuman(restored.awaitingHuman);
      setHumanActive(restored.humanActive);
    });
  }, [open, consented, messages.length, api]);

  /*
   * While a colleague has the conversation, the transcript is re-read on a
   * timer. There is no push channel to a widget sitting on a cached page,
   * and a human reply nobody sees until the visitor reloads is a reply
   * that did not happen. Only while the panel is open and only while a
   * person is actually involved — this is the whole cost of the feature.
   */
  useEffect(() => {
    if (!open || !awaitingHuman) {
      return;
    }

    const timer = window.setInterval(() => {
      void api.transcript().then((latest) => {
        if (latest.messages.length > 0) {
          setMessages(latest.messages);
        }

        setAwaitingHuman(latest.awaitingHuman);
        setHumanActive(latest.humanActive);
      });
    }, 8000);

    return () => window.clearInterval(timer);
  }, [open, awaitingHuman, api]);

  /* Keep the newest turn in view as it grows. */
  useEffect(() => {
    const node = log.current;

    if (node) {
      node.scrollTop = node.scrollHeight;
    }
  }, [visible]);

  /* Escape closes, and focus goes back where it came from. */
  useEffect(() => {
    if (!open) {
      return;
    }

    const onKey = (event: KeyboardEvent): void => {
      if (event.key === 'Escape') {
        setOpen(false);
        launcher.current?.focus();

        return;
      }

      if (event.key !== 'Tab') {
        return;
      }

      // Tab is trapped inside the panel. Without this the next Tab from
      // the send button lands somewhere on the customer's page with the
      // dialog still open, and a keyboard user has no way back in.
      const focusable = panel.current?.querySelectorAll<HTMLElement>('button, textarea, a[href]');

      if (!focusable || focusable.length === 0) {
        return;
      }

      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      const active = host.shadowRoot?.activeElement;

      if (event.shiftKey && active === first) {
        event.preventDefault();
        last?.focus();
      } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first?.focus();
      }
    };

    host.addEventListener('keydown', onKey);

    return () => host.removeEventListener('keydown', onKey);
  }, [open, host]);

  /* Move focus into the panel when it opens. */
  useEffect(() => {
    if (open) {
      panel.current?.querySelector('textarea')?.focus();
    }
  }, [open]);

  /*
   * The card appears once the visitor has said as much as the operator
   * configured, and never while a colleague is answering — somebody who
   * has just asked for a person does not need a form asking for their
   * address. Dismissing it ends it for this page view: "Not now" that
   * comes back two messages later is not a dismissal.
   */
  const visitorTurns = messages.filter((message) => message.role === 'visitor').length;

  const showCapture =
    boot.capture?.enabled === true &&
    !captured &&
    !dismissed &&
    !awaitingHuman &&
    !busy &&
    visitorTurns >= (boot.capture.ask_after ?? 2);

  const rate = useCallback(
    (id: string, rating: -1 | 1) => {
      setMessages((current) =>
        current.map((message) => (message.id === id ? { ...message, rating } : message)),
      );

      void api.rate(id, rating);
    },
    [api],
  );

  const submit = useCallback(
    (text: string) => {
      setError(null);
      setBusy(true);

      const pendingId = `pending-${Date.now()}`;

      setMessages((current) => [
        ...current,
        { id: `visitor-${Date.now()}`, role: 'visitor', text, citations: [], rating: null },
        { id: pendingId, role: 'clerk', text: '', citations: [], streaming: true, rating: null },
      ]);

      // The reply is mutated in place by id. Rebuilding the array on every
      // delta would be correct and would also re-render the whole
      // transcript sixty times a second on a long answer.
      const patch = (change: Partial<ChatMessage>): void => {
        setMessages((current) =>
          current.map((message) => (message.id === pendingId ? { ...message, ...change } : message)),
        );
      };

      let text_ = '';

      void deliver(api, text, transport.current, {
        onStart: () => undefined,
        onDelta: (chunk) => {
          text_ += chunk;
          patch({ text: text_ });
        },
        onReplace: (replacement) => {
          text_ = replacement;
          patch({ text: text_ });
        },
        onCitations: (citations) => patch({ citations }),
        onDone: (payload) => {
          patch({ streaming: false });
          setBusy(false);

          if (payload?.awaiting_human === true) {
            // The clerk did not answer because a colleague has this
            // conversation. Nothing was generated, so the empty pending
            // bubble is removed rather than left as a blank reply.
            setAwaitingHuman(true);
            setMessages((current) => current.filter((message) => message.id !== pendingId));
          }
        },
        onError: (message) => {
          patch({ streaming: false, failed: true });
          setBusy(false);
          setError(message === 'expired' ? labels.expired : labels.offline);
        },
      })
        .then((used) => {
          transport.current = used;
          api.rememberTransport(used);
        })
        .catch(() => {
          patch({ streaming: false, failed: true });
          setBusy(false);
          setError(labels.offline);
        });
    },
    [api, labels],
  );

  if (!open) {
    const label = boot.agent.widget_config.launcher;

    return (
      <button
        ref={launcher}
        type="button"
        class={`launcher${label ? '' : ' icon-only'}`}
        aria-label={labels.open}
        aria-expanded={false}
        onClick={() => setOpen(true)}
      >
        <IconChat />
        {label ? <span>{label}</span> : null}
      </button>
    );
  }

  return (
    <div class="panel" role="dialog" aria-label={boot.agent.name} ref={panel}>
      <div class="header">
        {boot.agent.avatar_url ? (
          <img class="avatar" src={boot.agent.avatar_url} alt="" width="34" height="34" />
        ) : (
          <div class="avatar" aria-hidden="true">
            {boot.agent.name.slice(0, 1).toUpperCase()}
          </div>
        )}

        <div class="identity">
          <div class="name">{boot.agent.name}</div>
          <div class="status">{boot.agent.widget_config.subtitle || labels.subtitle}</div>
        </div>

        <button
          type="button"
          class="icon-button"
          aria-label={labels.minimise}
          onClick={() => {
            setOpen(false);
            launcher.current?.focus();
          }}
        >
          <IconMinimise />
        </button>

        <button
          type="button"
          class="icon-button"
          aria-label={labels.close}
          onClick={() => {
            setOpen(false);
            launcher.current?.focus();
          }}
        >
          <IconClose />
        </button>
      </div>

      {!consented ? (
        <div class="log" ref={log}>
          {declined ? (
            <div class="notice">{labels.consentDeclined}</div>
          ) : (
            <Consent
              labels={labels}
              text={boot.consent?.text ?? ''}
              onAccept={() => {
                remember();
                setConsented(true);
              }}
              onDecline={() => {
                setDeclined(true);
                setOpen(false);
                launcher.current?.focus();
              }}
            />
          )}
        </div>
      ) : (
      <div class="log" ref={log} role="log" aria-live="polite" aria-atomic="false">
        {visible.map((message) => (
          <Bubble key={message.id} message={message} labels={labels} onRate={rate} />
        ))}

        {awaitingHuman ? (
          <div class="notice">{humanActive ? labels.humanHere : labels.waitingHuman}</div>
        ) : null}

        {captured ? <div class="notice">{labels.captureThanks}</div> : null}

        {showCapture ? (
          <Capture
            labels={labels}
            consent={boot.capture?.consent ?? null}
            onSubmit={async (email, consent) => {
              const accepted = await api.capture({ email, consent });

              if (accepted) {
                setCaptured(true);
              }

              return accepted;
            }}
            onDismiss={() => setDismissed(true)}
          />
        ) : null}

        {error ? <div class="error">{error}</div> : null}
      </div>
      )}

      {consented && boot.capabilities.handoff && !awaitingHuman ? (
        <button
          type="button"
          class="handoff"
          onClick={() => {
            // Optimistic, and deliberately so: the visitor has just asked
            // for a person and the one thing they must not see is the
            // button they pressed doing nothing while a request flies.
            setAwaitingHuman(true);

            void api.handoff().then((accepted) => {
              if (!accepted) {
                setAwaitingHuman(false);
                setError(labels.offline);

                return;
              }

              void api.transcript().then((latest) => {
                if (latest.messages.length > 0) {
                  setMessages(latest.messages);
                }
              });
            });
          }}
        >
          {labels.askHuman}
        </button>
      ) : null}

      {consented ? <Composer labels={labels} busy={busy} onSend={submit} /> : null}

      {boot.agent.branding.show_badge ? (
        <div class="badge">{boot.agent.branding.label}</div>
      ) : null}
    </div>
  );
}
