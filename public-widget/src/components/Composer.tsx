/** @jsxImportSource preact */
/**
 * The input row.
 */

import { useRef } from 'preact/hooks';
import { IconSend } from './Icons';
import type { Strings } from '../lib/i18n';

interface Props {
  labels: Strings;
  busy: boolean;
  onSend: (text: string) => void;
}

/** Characters accepted, matching the server's own cap. */
const MAX_CHARS = 2000;

export function Composer({ labels, busy, onSend }: Props): preact.JSX.Element {
  const field = useRef<HTMLTextAreaElement | null>(null);

  const submit = (): void => {
    const node = field.current;

    if (!node) {
      return;
    }

    const text = node.value.trim();

    if (!text || busy) {
      return;
    }

    onSend(text);

    node.value = '';
    node.style.height = 'auto';
  };

  return (
    <div class="composer">
      <div class="field">
        <textarea
          ref={field}
          rows={1}
          maxLength={MAX_CHARS}
          placeholder={labels.placeholder}
          aria-label={labels.placeholder}
          onInput={(event) => {
            // Grow to fit, up to the height the stylesheet caps. Done here
            // rather than with a CSS trick because the panel is a fixed
            // height and the log has to give back exactly what this takes.
            const node = event.currentTarget;

            node.style.height = 'auto';
            node.style.height = `${node.scrollHeight}px`;
          }}
          onKeyDown={(event) => {
            // Enter sends, Shift+Enter breaks the line. Reversing these is
            // the single most complained-about decision in chat widgets;
            // this is the way people expect.
            if (event.key === 'Enter' && !event.shiftKey) {
              event.preventDefault();
              submit();
            }
          }}
        />

        <button
          type="button"
          class="send"
          disabled={busy}
          aria-label={busy ? labels.sending : labels.send}
          onClick={submit}
        >
          <IconSend />
        </button>
      </div>
    </div>
  );
}
