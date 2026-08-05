/**
 * Widget strings.
 *
 * A table rather than inline literals, and it is scaffolding rather than a
 * finished translation layer. WordPress's own JS translation machinery is
 * `wp.i18n`, which arrives as a `@wordpress/*` package — forbidden here
 * for the same reasons it is forbidden in the admin. So the strings are
 * collected in one place now, the accessor takes the locale the server
 * sent, and swapping the lookup for server-provided translations later
 * touches this file and nothing else.
 */

export interface Strings {
  open: string;
  close: string;
  minimise: string;
  placeholder: string;
  send: string;
  sending: string;
  thinking: string;
  sources: string;
  helpful: string;
  notHelpful: string;
  rated: string;
  retry: string;
  offline: string;
  expired: string;
  subtitle: string;
}

const EN: Strings = {
  open: 'Open chat',
  close: 'Close chat',
  minimise: 'Minimise',
  placeholder: 'Ask anything…',
  send: 'Send',
  sending: 'Sending',
  thinking: 'Thinking',
  sources: 'Sources',
  helpful: 'This helped',
  notHelpful: "This didn't help",
  rated: 'Thanks — noted.',
  retry: 'Try again',
  offline: "That didn't send. Check your connection and try again.",
  expired: 'This conversation timed out. Reload the page to start a new one.',
  subtitle: 'Usually replies instantly',
};

/**
 * The string table for a locale.
 *
 * Falls back to English for anything unknown, which is every locale today.
 * Returning English is the honest behaviour: a widget that renders empty
 * labels because a translation is missing is worse than one that renders
 * the wrong language.
 */
export function strings(_locale: string): Strings {
  return EN;
}
