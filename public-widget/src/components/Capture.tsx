/** @jsxImportSource preact */
/**
 * The in-chat capture card (D11 §13.1).
 */

import { useState } from 'preact/hooks';
import type { Strings } from '../lib/i18n';

interface Props {
  labels: Strings;
  consent: string | null;
  onSubmit: (email: string, consent: boolean) => Promise<boolean>;
  onDismiss: () => void;
}

/**
 * One field, two buttons, and a way out.
 *
 * "Not now" is styled as an equal, not as a whisper below the primary
 * action. A capture prompt that cannot be dismissed is a dark pattern,
 * and the addresses it collects are the ones people type to make it go
 * away — which then score, notify a salesperson, and bounce.
 *
 * The card is deliberately not a modal and does not steal focus. It
 * appears in the transcript after a reply, where the visitor can ignore
 * it and keep typing.
 */
export function Capture({ labels, consent, onSubmit, onDismiss }: Props): preact.JSX.Element {
  const [email, setEmail] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [agreed, setAgreed] = useState(false);

  const submit = (event: Event): void => {
    event.preventDefault();

    const value = email.trim();

    // Checked here as well as on the server, because the round trip is
    // the slow way to learn about a missing @ and the visitor is looking
    // straight at the field.
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
      setError(labels.captureInvalid);

      return;
    }

    setBusy(true);
    setError(null);

    void onSubmit(value, agreed)
      .then((accepted) => {
        setBusy(false);

        // The parent decides what replaces this card, because it also
        // has to stop offering it again on the next reply.
        if (!accepted) {
          setError(labels.offline);
        }
      })
      .catch(() => {
        setBusy(false);
        setError(labels.offline);
      });
  };

  return (
    <form class="capture" onSubmit={submit}>
      <div class="capture-title">{labels.captureTitle}</div>

      <input
        class="capture-input"
        type="email"
        inputMode="email"
        autocomplete="email"
        aria-label={labels.captureTitle}
        placeholder={labels.captureEmail}
        value={email}
        disabled={busy}
        onInput={(event) => setEmail((event.target as HTMLInputElement).value)}
      />

      {consent ? (
        <label class="capture-consent">
          <input
            type="checkbox"
            checked={agreed}
            disabled={busy}
            onChange={(event) => setAgreed((event.target as HTMLInputElement).checked)}
          />
          <span>{consent}</span>
        </label>
      ) : null}

      {error ? (
        <div class="capture-error" role="alert">
          {error}
        </div>
      ) : null}

      <div class="capture-actions">
        <button type="submit" class="capture-send" disabled={busy}>
          {busy ? labels.sending : labels.captureSend}
        </button>
        <button type="button" class="capture-dismiss" onClick={onDismiss} disabled={busy}>
          {labels.captureDismiss}
        </button>
      </div>
    </form>
  );
}
