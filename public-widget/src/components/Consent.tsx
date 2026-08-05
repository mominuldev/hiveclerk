/** @jsxImportSource preact */
/**
 * The site-wide consent gate (FR-SYS-04, D11 §11).
 */

import type { Strings } from '../lib/i18n';

interface Props {
  labels: Strings;
  text: string;
  onAccept: () => void;
  onDecline: () => void;
}

/**
 * What a visitor sees before the widget records anything at all.
 *
 * This is a gate, not a banner. It replaces the transcript and the
 * composer rather than sitting above them, because a consent notice a
 * visitor can type past is a notice they did not give. The distinction
 * matters more than it looks: by the time somebody has typed a question,
 * the question itself — often containing their name, their order number
 * or their complaint — is the personal data at issue.
 *
 * Declining is a real option with a real outcome. It closes the panel and
 * says nothing was recorded, which is true: with the gate on, the widget
 * makes no request whatsoever until it is passed, including the page-view
 * ping every other install sends on load.
 */
export function Consent({ labels, text, onAccept, onDecline }: Props): preact.JSX.Element {
  return (
    <div class="consent" role="group" aria-label={labels.consentTitle}>
      <p class="consent-text">{text}</p>

      <div class="consent-actions">
        <button type="button" class="primary" onClick={onAccept}>
          {labels.consentAccept}
        </button>

        {/*
          Styled as an equal to "I agree" rather than as a link below it.
          A decline control that has to be hunted for is the same dark
          pattern as a capture card with no way out.
        */}
        <button type="button" class="secondary" onClick={onDecline}>
          {labels.consentDecline}
        </button>
      </div>
    </div>
  );
}
