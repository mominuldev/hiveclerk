/** @jsxImportSource preact */
/**
 * One turn in the transcript.
 */

import { markdown } from '../lib/markdown';
import type { ChatMessage } from '../types';
import type { Strings } from '../lib/i18n';

interface Props {
  message: ChatMessage;
  labels: Strings;
  onRate: (id: string, rating: -1 | 1) => void;
}

export function Bubble({ message, labels, onRate }: Props): preact.JSX.Element {
  const isClerk = message.role === 'clerk';
  const waiting = Boolean(message.streaming) && message.text === '';

  return (
    <div class={`row ${message.role}`}>
      <div class="bubble">
        {message.fromHuman ? <div class="from-human">{labels.fromHuman}</div> : null}

        {waiting ? (
          <div class="typing" aria-label={labels.thinking}>
            <span />
            <span />
            <span />
          </div>
        ) : (
          markdown(message.text)
        )}

        {isClerk && message.citations.length > 0 && (
          <div class="sources">
            <span class="sr-only">{labels.sources}</span>
            {message.citations.map((citation) =>
              citation.url ? (
                <a
                  key={citation.url + citation.heading_path}
                  class="source"
                  href={citation.url}
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  <span class="caret" aria-hidden="true">
                    ▸
                  </span>
                  {citation.heading_path || citation.title}
                </a>
              ) : (
                <span key={citation.title + citation.heading_path} class="source">
                  <span class="caret" aria-hidden="true">
                    ▸
                  </span>
                  {citation.heading_path || citation.title}
                </span>
              ),
            )}
          </div>
        )}

        {/* No rating on a human reply. The thumbs measure how the clerk is
            answering, and letting a colleague's message into that number
            makes the one quality signal in the product unreadable. */}
        {isClerk && !message.fromHuman && !message.streaming && message.text !== '' && (
          <div class="feedback">
            {message.rating ? (
              <span class="note">{labels.rated}</span>
            ) : (
              <>
                <button type="button" aria-label={labels.helpful} onClick={() => onRate(message.id, 1)}>
                  ▲
                </button>
                <button
                  type="button"
                  aria-label={labels.notHelpful}
                  onClick={() => onRate(message.id, -1)}
                >
                  ▼
                </button>
              </>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
