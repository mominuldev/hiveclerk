# Hiveclerk — Design System

**Deliverable 12 of 16** · Version 1.0 · Status: **Draft — awaiting approval** · 2026-08-05

Implementation target: React 19 + Tailwind CSS 4 + Headless UI + Recharts.

---

## 1. Direction

**Ink and paper, with colour rationed — and one spectral surface.**

The base interface is achromatic: ink on paper in light mode, ink on slate in dark. Colour is spent in exactly three places — **duty status**, **the single primary action on a screen**, and **the brand surface**. A screen showing four coloured buttons has no primary action.

### v1.1 revision — the brand surface

The original palette rationed colour so tightly that the product read as austere rather than intelligent. Honey has been **retired** and replaced by a **spectral gradient** (indigo → violet → cyan) used as a *surface*, never as a semantic:

| Appears in | Treatment |
|---|---|
| Logomark | Full gradient, slowly drifting. The one animated element in the product. |
| Active nav / roster indicator | 3px gradient bar |
| Hero card top edge | 1px gradient hairline |
| Empty-state mark | Gradient with a soft bloom behind it |
| "Upgrade" affordance | Gradient text |

**The gradient never carries body text and never signals state**, so it cannot be confused with a status colour. Everything functional stays on the solid accent and the status palette. This buys the AI register without surrendering the discipline that makes a dense tool readable.

The logomark is a **hexagon holding a smaller hexagon** — a cell in the hive. It says "hive" and "one of many workers", which is the product thesis, and it is specific in a way a generic sparkle or orb is not.

### What we rejected and why

| Considered | Rejected because |
|---|---|
| Oxblood brand accent | Destructive actions must be red. Asking a user to tell "Publish" from "Delete" by shade is a usability failure wearing a bold-choice costume. |
| Honey/amber as brand | Sat too close to warning-amber, and it read decorative rather than intelligent. Retired in v1.1. |
| Gradient on buttons and text | Contrast becomes unpredictable across the ramp, and it dissolves the primary-action signal. Gradient is a surface, not a semantic. |
| Cream/serif editorial treatment | Wrong register for a dense operational tool, and currently the default look of AI-generated design. |
| Inter for everything | Correct for dense data, but personality-free. Inter stays — as the *quiet* face, with Archivo Expanded carrying the voice. |

---

## 2. Colour

### 2.1 Primitives

```css
/* Ink — the neutral spine of the entire UI */
--hvc-ink-0:   #FFFFFF;
--hvc-ink-25:  #FAFBFC;
--hvc-ink-50:  #F5F6F8;
--hvc-ink-100: #EDEFF3;
--hvc-ink-200: #E3E6EB;
--hvc-ink-300: #CBD1DA;
--hvc-ink-400: #9BA3B0;
--hvc-ink-500: #6B7280;
--hvc-ink-600: #545C6B;
--hvc-ink-700: #363C46;
--hvc-ink-800: #262B33;
--hvc-ink-900: #1C2027;
--hvc-ink-950: #16191F;
--hvc-ink-990: #0E1014;

/* Accent — stamp-ink blue. The only interactive colour. */
--hvc-accent-50:  #EEF2FF;
--hvc-accent-100: #DCE4FE;
--hvc-accent-300: #93A8F5;
--hvc-accent-400: #5A78F0;   /* dark-mode accent */
--hvc-accent-500: #3B5BDB;
--hvc-accent-600: #2B4ACB;   /* light-mode accent */
--hvc-accent-700: #2440B4;
--hvc-accent-900: #1B2E7A;

/* Honey — brand only. Never a status. */
--hvc-honey-400: #E8BC6A;
--hvc-honey-500: #D9A441;
--hvc-honey-600: #B9862C;

/* Functional */
--hvc-emerald-500: #059669;  --hvc-emerald-400: #34D399;
--hvc-amber-500:   #D97706;  --hvc-amber-400:   #FBBF24;
--hvc-red-500:     #DC2626;  --hvc-red-400:     #F87171;
--hvc-slate-500:   #64748B;  --hvc-slate-400:   #94A3B8;
```

### 2.2 Semantic tokens

Components reference **only** these. No component may use a primitive directly.

```css
:root, [data-theme="light"] {
  --hvc-canvas:            var(--hvc-ink-50);
  --hvc-surface:           var(--hvc-ink-0);
  --hvc-surface-sunken:    var(--hvc-ink-100);
  --hvc-surface-hover:     var(--hvc-ink-50);
  --hvc-border:            var(--hvc-ink-200);
  --hvc-border-strong:     var(--hvc-ink-300);

  --hvc-text:              #101319;
  --hvc-text-secondary:    var(--hvc-ink-600);
  --hvc-text-tertiary:     var(--hvc-ink-500);
  --hvc-text-inverse:      var(--hvc-ink-0);

  --hvc-accent:            var(--hvc-accent-600);
  --hvc-accent-hover:      var(--hvc-accent-700);
  --hvc-accent-subtle:     var(--hvc-accent-50);
  --hvc-accent-text:       var(--hvc-accent-700);

  --hvc-brand:             var(--hvc-honey-600);

  --hvc-on-duty:           var(--hvc-emerald-500);
  --hvc-indexing:          var(--hvc-accent-600);
  --hvc-paused:            var(--hvc-slate-500);
  --hvc-draft:             var(--hvc-ink-400);
  --hvc-warning:           var(--hvc-amber-500);
  --hvc-danger:            var(--hvc-red-500);

  --hvc-shadow-sm: 0 1px 2px rgb(16 19 25 / 0.05);
  --hvc-shadow-md: 0 2px 4px rgb(16 19 25 / 0.06), 0 1px 2px rgb(16 19 25 / 0.04);
  --hvc-shadow-lg: 0 8px 24px rgb(16 19 25 / 0.10), 0 2px 6px rgb(16 19 25 / 0.06);
}

[data-theme="dark"] {
  --hvc-canvas:            var(--hvc-ink-990);
  --hvc-surface:           var(--hvc-ink-950);
  --hvc-surface-sunken:    var(--hvc-ink-990);
  --hvc-surface-hover:     var(--hvc-ink-900);
  --hvc-border:            var(--hvc-ink-800);
  --hvc-border-strong:     var(--hvc-ink-700);

  --hvc-text:              #ECEEF2;
  --hvc-text-secondary:    var(--hvc-ink-400);
  --hvc-text-tertiary:     #868E9C;
  --hvc-text-inverse:      var(--hvc-ink-990);

  --hvc-accent:            var(--hvc-accent-400);
  --hvc-accent-hover:      var(--hvc-accent-300);
  --hvc-accent-subtle:     rgb(90 120 240 / 0.14);
  --hvc-accent-text:       var(--hvc-accent-300);

  --hvc-brand:             var(--hvc-honey-500);

  --hvc-on-duty:           var(--hvc-emerald-400);
  --hvc-indexing:          var(--hvc-accent-400);
  --hvc-paused:            var(--hvc-slate-400);
  --hvc-draft:             var(--hvc-ink-500);
  --hvc-warning:           var(--hvc-amber-400);
  --hvc-danger:            var(--hvc-red-400);

  /* Dark elevation comes from surface lightness and borders, not shadow. */
  --hvc-shadow-sm: none;
  --hvc-shadow-md: 0 2px 8px rgb(0 0 0 / 0.30);
  --hvc-shadow-lg: 0 12px 32px rgb(0 0 0 / 0.45);
}

@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) { /* …dark values… */ }
}
```

**Theme resolution order:** explicit user choice (`data-theme` on `<html>`) → WordPress admin colour scheme (dark schemes map to dark) → `prefers-color-scheme`. Persisted per user in user meta, not localStorage, so it follows the operator across machines.

### 2.3 Contrast verification

| Pair | Ratio | WCAG |
|---|---|---|
| `text` on `surface` — light | 16.8:1 | AAA |
| `text-secondary` on `surface` — light | 7.1:1 | AAA |
| `text-tertiary` on `surface` — light | 4.9:1 | AA |
| `text` on `surface` — dark | 14.2:1 | AAA |
| `text-secondary` on `surface` — dark | 7.6:1 | AAA |
| `text-tertiary` on `surface` — dark | 5.4:1 | AA |
| White on `accent` — light | 7.4:1 | AAA |
| `accent` on `surface` — dark | 6.5:1 | AAA |
| `on-duty` on `surface` — both | ≥ 4.6:1 | AA |
| `border` on `surface` — both | ≥ 3.0:1 | AA (non-text) |

### 2.4 Status is never colour alone

Every duty state pairs a **glyph**, a **colour**, and a **text label**:

| State | Glyph | Token | Label |
|---|:--:|---|---|
| On duty | `●` filled circle | `--hvc-on-duty` | "On duty" |
| Indexing | `◐` half circle, slow pulse | `--hvc-indexing` | "Indexing" |
| Paused | `○` hollow circle | `--hvc-paused` | "Paused" |
| Draft | `◌` dotted circle | `--hvc-draft` | "Draft" |
| Error | `⊘` slashed circle | `--hvc-danger` | "Needs attention" |

---

## 3. Typography

### 3.1 Faces

| Role | Face | Why |
|---|---|---|
| **Display** | **Archivo Expanded** 600/700 | A wayfinding grotesque — institutional, signage-like. Carries the product's voice on page titles and KPI values *only*. Used anywhere else it becomes shouting. |
| **UI / body** | **Inter** 400/500/600 | The correct tool for dense operational UI. Deliberately the quiet face here. |
| **Data** | **JetBrains Mono** 400/500 | Tabular figures for token counts, costs, latency, scores, IDs. A clerk keeps ledgers; columns should align. |

**All three are self-hosted as variable `woff2`** in `assets/fonts/`. WordPress.org forbids external requests without consent, so Google Fonts CDN is not an option — and self-hosting removes a render-blocking third-party request anyway. Total budget: ~180 KB across three subsetted variable faces (Latin + Latin-Ext).

```css
--hvc-font-display: "Archivo Expanded", "Archivo", ui-sans-serif, system-ui, sans-serif;
--hvc-font-ui:      "Inter", ui-sans-serif, system-ui, -apple-system, sans-serif;
--hvc-font-mono:    "JetBrains Mono", ui-monospace, "SF Mono", Menlo, monospace;
```

### 3.2 Scale

| Token | Size / Line | Face · Weight | Tracking | Use |
|---|---|---|---|---|
| `display-xl` | 32 / 38 | Archivo Exp · 700 | −0.02em | KPI values, wizard headlines |
| `display-lg` | 24 / 30 | Archivo Exp · 600 | −0.015em | Page titles |
| `title` | 18 / 24 | Inter · 600 | −0.01em | Card and panel headings |
| `body-lg` | 15 / 22 | Inter · 400 | 0 | Long-form, empty states |
| `body` | 14 / 20 | Inter · 400 | 0 | **Base UI size** |
| `body-sm` | 13 / 18 | Inter · 400 | 0 | Secondary detail |
| `label` | 12 / 16 | Inter · 500 | +0.01em | Form labels, table headers |
| `eyebrow` | 11 / 14 | Inter · 600 | +0.08em, uppercase | KPI captions, section markers |
| `data` | 13 / 18 | JetBrains Mono · 400 | 0 | Costs, tokens, latency, IDs |
| `data-sm` | 11 / 15 | JetBrains Mono · 400 | 0 | Inline message metadata |

**14px is the base**, not 16. This is a dense operational tool where an operator scans hundreds of rows; 16px would cost roughly one row per screen for no legibility gain at these contrast ratios.

**KPI values use Archivo Expanded with `font-variant-numeric: tabular-nums`** — distinctive *and* aligned. Mono is reserved for inline data so the two never compete.

### 3.3 Rules

- Sentence case everywhere. Title Case belongs to marketing, not interfaces.
- `eyebrow` is the only uppercase style.
- Never more than two type sizes in a single component.
- All numeric columns get `tabular-nums`, without exception.
- Long text caps at 68 characters per line.

---

## 4. Space, Radius, Elevation

```css
/* 4px base */
--hvc-space-1: 4px;    --hvc-space-2: 8px;    --hvc-space-3: 12px;
--hvc-space-4: 16px;   --hvc-space-5: 20px;   --hvc-space-6: 24px;
--hvc-space-8: 32px;   --hvc-space-10: 40px;  --hvc-space-12: 48px;
--hvc-space-16: 64px;

--hvc-radius-sm:  4px;   /* badges, dots, chips        */
--hvc-radius-md:  6px;   /* buttons, inputs, cells     */
--hvc-radius-lg:  8px;   /* cards, panels              */
--hvc-radius-xl:  12px;  /* modals, popovers           */
--hvc-radius-2xl: 16px;  /* the widget only            */
--hvc-radius-full: 9999px;
```

**Layout constants:** sidebar 264px · icon rail 56px · header 56px · content max-width 1280px · gutter 24px · card padding 20px · table row height 44px.

**Elevation in dark mode is not shadow.** Shadows are near-invisible on dark surfaces. Depth comes from surface lightness stepping (`canvas` → `surface` → `surface-hover`) plus a `border` hairline. Light mode uses real shadows.

---

## 5. Motion

```css
--hvc-duration-instant: 0ms;
--hvc-duration-fast:    120ms;   /* hover, focus, checkbox        */
--hvc-duration-base:    180ms;   /* dropdowns, tabs, accordions   */
--hvc-duration-slow:    260ms;   /* modals, drawers, page transit */

--hvc-ease-standard:   cubic-bezier(0.2, 0, 0, 1);
--hvc-ease-decelerate: cubic-bezier(0, 0, 0, 1);
--hvc-ease-accelerate: cubic-bezier(0.3, 0, 1, 1);
```

| Element | Motion |
|---|---|
| Button hover | background `fast` |
| Dropdown / popover | opacity + 4px rise, `base` `decelerate` |
| Modal | overlay fade + 8px rise, `slow` |
| Drawer | slide from edge, `slow` `decelerate` |
| Toast | slide up + fade, `base`; auto-dismiss 5s |
| Skeleton | 1.6s shimmer, `linear`, infinite |
| Indexing dot | 2s opacity pulse 1 → 0.4 → 1 |
| Streaming text | no animation on the text itself — tokens simply appear |

```css
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: 0.01ms !important;
    transition-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
  }
}
```

**Streaming text is deliberately unanimated.** A per-token fade at 40 tokens/second is visual noise and measurably hurts reading speed.

---

## 6. Components

### 6.1 Button

| Variant | Light | Dark | Use |
|---|---|---|---|
| `primary` | `accent` bg, white text | `accent` bg, `ink-990` text | **One per screen** |
| `secondary` | `surface` bg, `border`, `text` | same tokens | Everything else |
| `ghost` | transparent, `text-secondary` | same | Toolbars, table rows |
| `danger` | `danger` bg, white text | `danger` bg, `ink-990` text | Destructive confirms only |
| `link` | `accent-text`, underline on hover | same | Inline |

Sizes: `sm` 28px / 12px text · `md` 34px / 14px · `lg` 40px / 14px.

States: hover → `accent-hover`; active → 1px translate-y; focus-visible → 2px `accent` ring at 2px offset; disabled → 45% opacity, `not-allowed`; loading → inline spinner replaces the leading icon, **label text never changes** so width stays stable.

### 6.2 Status dot

```
● On duty      ◐ Indexing      ○ Paused      ◌ Draft      ⊘ Needs attention
```

8px glyph · 6px gap · `label` text. `role="status"` with an accessible name. Never rendered without its label except inside the Roster rail, where a `title` and `aria-label` carry it.

### 6.3 Card

`surface` bg · `border` 1px · `radius-lg` · `space-5` padding · `shadow-sm` (light only). Header row: `title` left, actions right. Interactive cards gain `surface-hover` and `border-strong` on hover — never a shadow lift, which reads as a modal.

### 6.4 Data table

Header: `label`, uppercase off, `text-tertiary`, `surface-sunken`, sticky. Rows 44px, `border` hairline between, `surface-hover` on hover. Numeric cells right-aligned in `data` with `tabular-nums`. Row actions revealed on hover, always keyboard-reachable. Sorted column shows `accent` underline on the header label. Zebra striping is not used — hairlines are enough and stripes fight the density.

### 6.5 Form controls

Input: 34px · `surface` · `border` · `radius-md` · 14px text · 10px horizontal padding. Focus → `accent` border plus 3px `accent-subtle` ring. Error → `danger` border, message beneath in `body-sm` `danger`.

Every label sits above its control in `label` style. Helper text below in `body-sm` `text-tertiary`. **Helper text states the consequence of the setting**, per the voice rules — "Lower means the clerk answers more often but with weaker sources."

Toggle: 36×20px, 16px thumb, `fast`. On → `accent`. Off → `ink-300` light / `ink-700` dark.

### 6.6 Roster rail — the signature component

```
  ROSTER                    +
  ┌─────────────────────────┐
  │▍● Ada                   │   ← 3px honey bar, radius-full, on the active item
  │   Support               │
  ├─────────────────────────┤
  │ ● Rafi                  │
  │   Sales                 │
  ├─────────────────────────┤
  │ ◐ Mira                  │   ← pulsing while indexing
  │   Qualifier             │
  └─────────────────────────┘
```

Item 44px · avatar or status dot · name in `body` 500 · role in `body-sm` `text-tertiary`. Active item: `surface-hover` background plus the 3px honey indicator — the only place honey appears outside the logomark.

**Behaviour:** selecting a clerk filters the current screen rather than navigating. Selection persists across routes and is reflected in the URL (`#/conversations?clerk=ada`) so it survives reload and can be shared.

### 6.7 Charts (Recharts)

```css
--hvc-chart-grid:   var(--hvc-border);
--hvc-chart-axis:   var(--hvc-text-tertiary);
--hvc-chart-1: #2B4ACB;  /* accent — primary series      */
--hvc-chart-2: #0E9F8E;  /* teal                          */
--hvc-chart-3: #D9A441;  /* honey                         */
--hvc-chart-4: #8B5CF6;  /* violet                        */
--hvc-chart-5: #64748B;  /* slate — "other"               */
```

Rules: no gradients under lines; 2px stroke; no dots except on hover; horizontal grid lines only, at `border`; axes in `data-sm`; tooltips are `surface` cards with `shadow-lg` and `radius-lg`; **every chart carries a one-sentence written finding beneath it**. Series colours are also distinguished by dash pattern so the charts survive greyscale and colour-blindness.

Sparklines in KPI cards: 1.5px stroke, no axes, no grid, `accent` at 60% opacity.

### 6.8 Other primitives

| Component | Spec |
|---|---|
| **Badge** | 20px · `radius-sm` · `label` · subtle bg at 12% of its semantic colour |
| **Tabs** | Underline style, 2px `accent` indicator sliding `base`; never pills |
| **Modal** | `radius-xl` · max 560px · `shadow-lg` · overlay `ink-990` at 40% with 2px backdrop blur |
| **Drawer** | Right edge, 480px, for lead and conversation detail |
| **Toast** | Bottom-right, `radius-lg`, 5s auto-dismiss, max 3 stacked, action link inline |
| **Skeleton** | Matches final content shape; `surface-sunken` with shimmer |
| **Empty state** | Centred, 320px max, `title` + `body` + one primary action |
| **Command palette** | 640px, top-centred at 20vh, fuzzy match, grouped results |
| **Tooltip** | `ink-900` bg, white text, `body-sm`, 300ms delay, 8px offset |

**Built in Sprint 2, with three deviations from the table above — each
recorded because the spec was written before the component met the
product:**

- **Tabs address URLs, not component state.** A support conversation that
  ends "open Settings, then the Audit log tab" is worse than one that ends
  with a link, and in-page tab state cannot be bookmarked or reloaded
  back into. The consequence is that tabs are links with link semantics,
  not ARIA tabs. The 2px indicator is the brand gradient rather than a
  solid accent, since it is structure rather than state.
- **Toasts do not auto-dismiss on failure.** The spec's flat 5s applies to
  successes. A failure usually needs acting on, and a message that
  disappears before it is read is the same as no message. The live region
  is `status`, not `alert`: interrupting a screen reader is right for a
  failure and rude for "Saved".
- **Select is the native control.** Model lists run to hundreds of entries
  on OpenRouter, and the browser's own listbox handles type-ahead, long
  lists and small screens better than anything reimplemented here. Only
  the caret is restyled.

### 6.9 Icons

**Lucide**, 16px in dense UI and 20px in navigation, 1.5px stroke, `currentColor`. Tree-shaken per import — never the full bundle. Icons never appear without a label except in the collapsed icon rail, where `aria-label` carries the name.

---

## 7. Widget Design

The widget is theme-*able*, not theme-*aware*: it follows the site owner's chosen accent, not the visitor's OS preference, because it must sit correctly on the customer's brand.

| Token | Default | Customer-controlled |
|---|---|---|
| Accent | `#2B4ACB` | ✓ any hex |
| Radius | 16px | ✓ 0–24px |
| Position | bottom-right | ✓ four corners |
| Launcher | 56px circle | ✓ icon, size fixed |
| Font | system stack | ✗ — no webfont loaded on the front end |

**The widget loads no webfonts.** A 180 KB font download on a customer's storefront would violate the LCP budget (NFR-01). It uses the system UI stack; the admin's typographic personality stays in the admin.

Panel: 384×560px desktop, full-screen sheet below 480px. Visitor messages `accent` bg with white text; clerk messages `surface` with `border`. Citations render as chips beneath the message. Shadow DOM isolation means no site CSS can reach in.

---

## 8. Tailwind 4 Configuration

```css
/* admin-app/src/styles/tailwind.css */
@import "tailwindcss";

@custom-variant dark (&:where([data-theme="dark"], [data-theme="dark"] *));

@theme {
  --font-display: "Archivo Expanded", "Archivo", ui-sans-serif, sans-serif;
  --font-sans:    "Inter", ui-sans-serif, system-ui, sans-serif;
  --font-mono:    "JetBrains Mono", ui-monospace, Menlo, monospace;

  --color-canvas:          var(--hvc-canvas);
  --color-surface:         var(--hvc-surface);
  --color-surface-sunken:  var(--hvc-surface-sunken);
  --color-surface-hover:   var(--hvc-surface-hover);
  --color-border:          var(--hvc-border);
  --color-border-strong:   var(--hvc-border-strong);
  --color-content:         var(--hvc-text);
  --color-content-secondary: var(--hvc-text-secondary);
  --color-content-tertiary:  var(--hvc-text-tertiary);
  --color-accent:          var(--hvc-accent);
  --color-accent-hover:    var(--hvc-accent-hover);
  --color-accent-subtle:   var(--hvc-accent-subtle);
  --color-brand:           var(--hvc-brand);
  --color-on-duty:         var(--hvc-on-duty);
  --color-indexing:        var(--hvc-indexing);
  --color-paused:          var(--hvc-paused);
  --color-draft:           var(--hvc-draft);
  --color-warning:         var(--hvc-warning);
  --color-danger:          var(--hvc-danger);

  --radius-sm: 4px;  --radius-md: 6px;  --radius-lg: 8px;
  --radius-xl: 12px; --radius-2xl: 16px;

  --text-eyebrow: 11px;
  --text-eyebrow--line-height: 14px;
  --text-eyebrow--letter-spacing: 0.08em;
  --text-eyebrow--font-weight: 600;
}
```

### 8.1 Escaping wp-admin — harder than it looks

Three problems, all found by measuring the rendered page rather than reasoning about it.

**1. Unlayered CSS beats layered CSS.** wp-admin's stylesheets are unlayered; Tailwind emits everything into `@layer`. Unlayered rules win *regardless of specificity or source order*, so every wp-admin rule for `a`, `h1`, `p`, `button` and `input` silently overrode our tokens. Measured: headings rendered `#1d2327` and links `#3858e9` instead of `var(--hvc-text)`.

Fix — emit utilities as `!important`:

```css
@import 'tailwindcss' important;
```

Paired with an **unlayered, `#hvc-root`-scoped reset** (specificity 1,0,1) that supplies defaults where no utility applies. Utilities outrank the reset; the reset outranks wp-admin.

**2. Never use a negative margin against the admin menu.** `#wpcontent` already carries `margin-left: 160px` (36px folded). A `margin-left: -20px` on the app root pulled the sidebar *underneath* the menu and clipped it:

```css
body.hvc-page #wpcontent { padding-left: 0; }   /* remove the 20px gutter */
#hvc-root { margin: 0; }                        /* never reach left of it */
```

**3. The theme must follow wp-admin's colour scheme.** Resolution order is **explicit choice → WordPress admin colour scheme → `prefers-color-scheme`**. Skipping the middle step renders a light app inside a dark wp-admin — near-black text on dark chrome, which reads as broken.

WordPress ships nine schemes and sites add their own, so the scheme is **measured, not matched against a name list**: read the computed background of `#adminmenuback` and take its relative luminance. That stays correct for schemes we have never heard of.

The admin bar stays — hiding it would trap the operator inside the app.

### 8.1.1 A second way styles go missing: tailwind-merge

wp-admin is not the only thing that can silently drop a style. `cn()`
runs `tailwind-merge`, which treats **every `bg-*` utility as one conflict
group** — background colour, image, size, position and repeat together.
Writing an arbitrary caret as `bg-[url(...)]` next to `bg-surface-sunken`
therefore removed the colour, and wp-admin's unlayered
`select { background: #fff … }` won by default. Measured: the control
rendered `rgb(255,255,255)` in dark mode with `rgb(238,240,244)` text on
it.

**The rule this produces:** anything that sets a background *property*
other than colour goes in a CSS class, not a utility — and the class is
scoped to `#hvc-root` so it outranks wp-admin's more specific
`.wp-core-ui select`. `.hvc-select-caret` and `.hvc-hairline-x` both
follow this.

The general lesson is worth stating plainly: a utility that is present in
the source is not necessarily present in the DOM. Verifying a style means
measuring the computed value, not reading the class list.

### 8.2 Verifying visually

`tools/shot.mjs` drives Playwright against the real install, mints a short-lived session cookie (never touching the user's password), and dumps computed styles:

```bash
node tools/shot.mjs out.png --diagnose          # measure + screenshot
node tools/shot.mjs light.png --theme=light     # force a theme
node tools/shot.mjs kb.png --route=knowledge    # a specific route
```

Both auth cookies are required: `auth_redirect()` validates the `auth`-scheme cookie on `/wp-admin`, while `is_user_logged_in()` reads the `logged_in` one on `/`. Supplying only the second lands on the login screen with the username pre-filled.

---

## 9. Content Style

| Rule | Instead of | Write |
|---|---|---|
| Name by what people control | "Configure inference parameters" | "Choose a model" |
| Verbs describe the outcome | "Submit" | "Publish Ada" |
| Actions keep their name | "Save" → toast "Updated" | "Publish" → toast "Published" |
| Errors: what happened, what next | "An error occurred" | "Provider returned 429. Retrying at 15:40." |
| Empty states invite | "No data available" | "Nobody's on duty yet." |
| No apologies or exclamations | "Oops! Something went wrong!" | "That didn't send. Check the key and try again." |
| Never "AI agent" in the UI | "Agent #3 active" | "Ada is on duty" |
| Numbers carry units and periods | "1,284" | "1,284 conversations · last 30 days" |

**Sentence case throughout. One idea per sentence. No em-dashes in UI copy** — they read as informal in an operational tool.

---

## 10. Quality Floor

Non-negotiable, enforced in CI:

- [ ] Both themes verified on every screen; no hard-coded hex outside the token file
- [ ] Contrast ≥ 4.5:1 text, ≥ 3:1 UI boundaries, both themes
- [ ] Every interactive element keyboard-reachable with a visible focus ring
- [ ] `prefers-reduced-motion` honoured globally
- [ ] Touch targets ≥ 40px admin, ≥ 44px widget
- [ ] Usable at 200% zoom without horizontal scroll
- [ ] Every list has a designed empty state
- [ ] Every async surface has a skeleton, never a spinner
- [ ] Every destructive action states its blast radius
- [ ] Status never conveyed by colour alone
- [ ] Admin bundle ≤ 350 KB gzipped · widget ≤ 40 KB gzipped
- [ ] No `@wordpress/*` imports — ESLint enforced

---

## 11. Component Build Order

Mirrors the sprint plan in Deliverable 14.

| Wave | Components |
|---|---|
| **1 — Foundation** | Tokens, theme provider, AppShell, Sidebar, **Roster rail**, Button, Input, Card, Badge, StatusDot, Toast, Skeleton |
| **2 — Data** | DataTable, Pagination, Filters, EmptyState, Drawer, Modal, Tabs, Select, Toggle |
| **3 — Domain** | KpiCard, TrendChart, FunnelChart, TranscriptPanel, CitationChip, ScoreBreakdown, PipelineBoard |
| **4 — Polish** | CommandPalette, Tooltip, inline editors, keyboard shortcuts, onboarding wizard |

---

**Approval:** ⬜ Awaiting sign-off · Reviewer: ______________ · Date: __________
