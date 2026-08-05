/**
 * Widget entry point.
 *
 * Mounts into a shadow root attached to an element appended to `<body>`.
 * The shadow boundary is the whole isolation story: the customer's theme
 * cannot restyle the panel, and the panel cannot restyle the customer's
 * page. Both directions matter — a chat widget that inherits a theme's
 * `button { text-transform: uppercase }` looks broken, and one that leaks
 * a `p { margin: 0 }` breaks the site it is a guest on.
 */

import { render, h } from 'preact';
import { Widget } from './app';
import { STYLES } from './styles';
import type { WidgetBoot } from './types';

declare global {
  interface Window {
    HVC_WIDGET?: WidgetBoot;
  }
}

/** The element id, so a double enqueue cannot mount two widgets. */
const HOST_ID = 'hvc-widget-root';

function mount(): void {
  const boot = window.HVC_WIDGET;

  if (!boot?.agent || document.getElementById(HOST_ID)) {
    return;
  }

  const host = document.createElement('div');

  host.id = HOST_ID;
  host.setAttribute('data-position', boot.agent.widget_config.position);
  host.setAttribute('data-theme', resolveTheme(boot.agent.widget_config.theme));

  const root = host.attachShadow({ mode: 'open' });
  const style = document.createElement('style');

  style.textContent = STYLES;
  root.appendChild(style);

  const accent = boot.agent.widget_config.accent;

  // Set on the host rather than compiled into the stylesheet: the brand is
  // per-clerk, and a stylesheet built per clerk could not be cached.
  //
  // Deliberately `--hvc-brand` rather than `--hvc-accent`. The configured
  // colour fills the launcher and the send button, where the text on it is
  // white; it does not colour links, because a brand picked against a white
  // page routinely fails contrast on the dark surface. See tokens.ts.
  host.style.setProperty('--hvc-brand', accent);
  host.style.setProperty('--hvc-radius', `${boot.agent.widget_config.radius}px`);

  const mountPoint = document.createElement('div');

  root.appendChild(mountPoint);
  document.body.appendChild(host);

  render(h(Widget, { boot, host }), mountPoint);

  if (boot.agent.widget_config.theme === 'auto' && window.matchMedia) {
    // Follow the visitor's system preference while the page is open. A
    // widget that picked a theme at load and kept it looks wrong for the
    // rest of the evening on a machine that switches at sunset.
    window
      .matchMedia('(prefers-color-scheme: dark)')
      .addEventListener('change', (event) =>
        host.setAttribute('data-theme', event.matches ? 'dark' : 'light'),
      );
  }
}

/** The theme to start in. */
function resolveTheme(configured: 'auto' | 'light' | 'dark'): string {
  if (configured !== 'auto') {
    return configured;
  }

  return window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mount, { once: true });
} else {
  mount();
}
