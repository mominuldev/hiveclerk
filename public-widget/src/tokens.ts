/**
 * The widget's design tokens.
 *
 * The only file in `public-widget/` that contains a colour literal, which
 * is the same rule the admin follows through `tokens.css`. The widget
 * cannot import that file — it ships as one self-contained bundle with no
 * stylesheet request — so the values are duplicated here deliberately and
 * the subset is small: a chat panel needs a surface, a text colour, a
 * border and an accent, not the full palette.
 *
 * Values are taken from Deliverable 12 §2.
 *
 * ## Two accents, not one
 *
 * `--hvc-brand` is the colour the customer chose. It is used only where it
 * fills a shape that carries white text — the launcher, the send button,
 * the avatar — so the customer owns that contrast decision the same way
 * they own it on the rest of their site.
 *
 * `--hvc-accent` is ours, and it changes with the theme. It is used for
 * text: links, the citation caret, the focus ring. Letting the configured
 * colour reach those was measured to be a real defect — a customer accent
 * of #2B4ACB renders citation links at about 3:1 on the dark surface,
 * under the 4.5:1 body-text floor, and the widget has no way to know that
 * their brand colour was chosen against a white page.
 */

export const TOKENS = `
:host {
  --hvc-brand: #2B4ACB;
  --hvc-surface: #FFFFFF;
  --hvc-surface-sunken: #F5F6F8;
  --hvc-border: #E3E6EB;
  --hvc-text: #101319;
  --hvc-text-secondary: #545C6B;
  --hvc-text-tertiary: #6B7280;
  --hvc-text-inverse: #FFFFFF;
  --hvc-accent: #2B4ACB;
  --hvc-bubble-visitor: #EEF2FF;
  --hvc-shadow: 0 8px 24px rgb(16 19 25 / 0.14), 0 2px 6px rgb(16 19 25 / 0.08);
  --hvc-focus: #2B4ACB;
}

:host([data-theme='dark']) {
  --hvc-surface: #16191F;
  --hvc-surface-sunken: #0E1014;
  --hvc-border: #262B33;
  --hvc-text: #ECEEF2;
  --hvc-text-secondary: #9BA3B0;
  --hvc-text-tertiary: #868E9C;
  --hvc-text-inverse: #0E1014;
  --hvc-accent: #5A78F0;
  --hvc-bubble-visitor: rgb(90 120 240 / 0.16);
  --hvc-shadow: 0 8px 24px rgb(0 0 0 / 0.5), 0 2px 6px rgb(0 0 0 / 0.4);
  --hvc-focus: #93A8F5;
}
`;
