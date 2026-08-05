/**
 * The widget's stylesheet, as a string.
 *
 * Kept in TypeScript rather than a `.css` file so the build emits exactly
 * one artefact. A separate stylesheet would be a second request the theme
 * cannot preload and — more importantly — could not be adopted into the
 * shadow root without either inlining it anyway or waiting for a network
 * round trip before the panel could paint.
 *
 * Everything is scoped by the shadow boundary, so the selectors can be
 * short and unprefixed: nothing here can reach the customer's page, and
 * nothing on their page can reach in. That isolation is the reason the
 * widget uses a shadow root at all — a chat panel that inherits a theme's
 * `button` styles is a support ticket in every theme.
 */

import { TOKENS } from './tokens';

export const STYLES = `
${TOKENS}

:host {
  --hvc-radius: 16px;
  all: initial;
  position: fixed;
  bottom: 20px;
  z-index: 2147483000;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
  font-size: 15px;
  line-height: 1.5;
  color: var(--hvc-text);
}

:host([data-position='bottom-right']) { right: 20px; }
:host([data-position='bottom-left'])  { left: 20px; }

*, *::before, *::after { box-sizing: border-box; }

button {
  font: inherit;
  color: inherit;
  background: none;
  border: 0;
  margin: 0;
  cursor: pointer;
}

:focus-visible {
  outline: 2px solid var(--hvc-focus);
  outline-offset: 2px;
}

/* ---------------------------------------------------------- launcher */

.launcher {
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: 56px;
  min-width: 56px;
  padding: 0 18px;
  border-radius: 999px;
  background: var(--hvc-brand);
  color: #FFFFFF;
  box-shadow: var(--hvc-shadow);
  font-weight: 600;
  transition: transform 150ms ease;
}

.launcher:hover { transform: translateY(-2px); }
.launcher.icon-only { padding: 0; justify-content: center; }

/* ------------------------------------------------------------- panel */

.panel {
  display: flex;
  flex-direction: column;
  width: 380px;
  max-width: calc(100vw - 40px);
  height: 560px;
  max-height: calc(100vh - 120px);
  background: var(--hvc-surface);
  border: 1px solid var(--hvc-border);
  border-radius: var(--hvc-radius);
  box-shadow: var(--hvc-shadow);
  overflow: hidden;
}

.header {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 14px;
  border-bottom: 1px solid var(--hvc-border);
  background: var(--hvc-surface);
}

.avatar {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  background: var(--hvc-brand);
  color: #FFFFFF;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 14px;
  flex: none;
  object-fit: cover;
}

.identity { flex: 1; min-width: 0; }
.name { font-weight: 650; font-size: 15px; }
.status { font-size: 12px; color: var(--hvc-text-secondary); }

.icon-button {
  width: 44px;
  height: 44px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 10px;
  color: var(--hvc-text-secondary);
}

.icon-button:hover { background: var(--hvc-surface-sunken); color: var(--hvc-text); }

/* ---------------------------------------------------------- messages */

.log {
  flex: 1;
  overflow-y: auto;
  padding: 16px 14px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  background: var(--hvc-surface);
}

.row { display: flex; }
.row.visitor { justify-content: flex-end; }

.bubble {
  max-width: 84%;
  padding: 10px 13px;
  border-radius: 14px;
  background: var(--hvc-surface-sunken);
  overflow-wrap: anywhere;
}

.row.visitor .bubble {
  background: var(--hvc-bubble-visitor);
  border-bottom-right-radius: 4px;
}

.row.clerk .bubble { border-bottom-left-radius: 4px; }

.bubble p { margin: 0 0 8px; }
.bubble p:last-child { margin-bottom: 0; }
.bubble ul, .bubble ol { margin: 0 0 8px; padding-left: 20px; }
.bubble li { margin-bottom: 2px; }
.bubble code {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.9em;
  padding: 1px 4px;
  border-radius: 4px;
  background: var(--hvc-border);
}
.bubble a { color: var(--hvc-accent); text-decoration: underline; }

/* --------------------------------------------------------- citations */

.sources { margin-top: 8px; border-top: 1px solid var(--hvc-border); padding-top: 6px; }

.source {
  display: block;
  width: 100%;
  text-align: left;
  font-size: 12.5px;
  color: var(--hvc-text-secondary);
  padding: 4px 0;
  text-decoration: none;
}

.source:hover { color: var(--hvc-accent); }
.source .caret { color: var(--hvc-accent); margin-right: 4px; }

.feedback { display: flex; gap: 4px; margin-top: 6px; }

.feedback button {
  font-size: 12px;
  color: var(--hvc-text-tertiary);
  padding: 4px 6px;
  border-radius: 6px;
  min-height: 28px;
}

.feedback button:hover { background: var(--hvc-surface-sunken); color: var(--hvc-text); }
.feedback button[aria-pressed='true'] { color: var(--hvc-accent); }
.feedback .note { color: var(--hvc-text-tertiary); padding: 4px 6px; font-size: 12px; }

/* ----------------------------------------------------------- typing */

.typing { display: flex; gap: 4px; padding: 4px 2px; }

.typing span {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--hvc-text-tertiary);
  animation: pulse 1.2s infinite ease-in-out;
}

.typing span:nth-child(2) { animation-delay: 0.15s; }
.typing span:nth-child(3) { animation-delay: 0.3s; }

@keyframes pulse {
  0%, 60%, 100% { opacity: 0.3; }
  30% { opacity: 1; }
}

/* --------------------------------------------------------- composer */

.composer {
  border-top: 1px solid var(--hvc-border);
  padding: 10px 12px;
  background: var(--hvc-surface);
}

.field {
  display: flex;
  align-items: flex-end;
  gap: 8px;
  border: 1px solid var(--hvc-border);
  border-radius: 12px;
  padding: 6px 6px 6px 12px;
  background: var(--hvc-surface);
}

.field:focus-within { border-color: var(--hvc-accent); }

.field textarea {
  flex: 1;
  border: 0;
  outline: none;
  resize: none;
  background: transparent;
  color: var(--hvc-text);
  font: inherit;
  max-height: 96px;
  padding: 6px 0;
}

.field textarea::placeholder { color: var(--hvc-text-tertiary); }

.send {
  width: 44px;
  height: 44px;
  flex: none;
  border-radius: 10px;
  background: var(--hvc-brand);
  color: #FFFFFF;
  display: flex;
  align-items: center;
  justify-content: center;
}

.send[disabled] { opacity: 0.4; cursor: not-allowed; }

.badge {
  text-align: center;
  font-size: 11px;
  color: var(--hvc-text-tertiary);
  padding-top: 8px;
}

.error {
  font-size: 12.5px;
  color: var(--hvc-text-secondary);
  padding: 6px 2px 0;
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
  border: 0;
}

@media (prefers-reduced-motion: reduce) {
  * { animation: none !important; transition: none !important; }
}

@media (max-width: 480px) {
  .panel {
    width: calc(100vw - 24px);
    height: calc(100vh - 100px);
  }
}
`;
