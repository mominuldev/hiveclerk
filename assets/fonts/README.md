# Fonts — not yet bundled

The design system (Deliverable 12 §3) specifies three self-hosted faces:

| Role | Face | Weights |
|---|---|---|
| Display | **Archivo Expanded** | 600, 700 |
| UI / body | **Inter** | 400, 500, 600 |
| Data | **JetBrains Mono** | 400, 500 |

**These files are not in the repository yet.** `tokens.css` declares each face
with a system fallback, so the app renders correctly today — it just does not
yet carry its intended typographic personality.

## Why self-hosted rather than a CDN

WordPress.org forbids loading assets from external services without explicit
user consent, so Google Fonts is not an option. Self-hosting also removes a
render-blocking third-party request.

## Adding them

1. Download the variable `woff2` for each face:
   - Archivo / Archivo Expanded — <https://fonts.google.com/specimen/Archivo> (OFL)
   - Inter — <https://github.com/rsms/inter/releases> (OFL)
   - JetBrains Mono — <https://github.com/JetBrains/JetBrainsMono/releases> (OFL)
2. Subset to Latin + Latin-Extended with
   [`glyphhanger`](https://github.com/zachleat/glyphhanger) or
   [`fonttools`](https://github.com/fonttools/fonttools). Target total: **≤ 180 KB**.
3. Place the files here as `archivo.woff2`, `inter.woff2`, `jetbrains-mono.woff2`.
4. Add the `@font-face` block to `admin-app/src/styles/tokens.css` with
   `font-display: swap` and the correct `unicode-range`.

## The widget loads none of these

`public-widget` uses the system UI stack deliberately. A 180 KB font download
on a customer's storefront would blow the 50 ms LCP budget in NFR-01. The
typographic personality stays in the admin, where there is no such constraint.

All three faces are SIL Open Font Licence, so bundling them in a GPL plugin is
permitted. Include the OFL licence text alongside the files.
