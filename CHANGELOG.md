# Changelog

All notable changes are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Sprint 3 — Ingestion and the SSE spike

**Goal:** get content into the database as chunks. De-risk streaming early.

#### Added

- **⚑ The SSE spike (R-2, TD-2).** `SseStream` tears down output
  buffering, refuses compression, pads the preamble past a 4 KB buffer
  and detects a departed client between frames. `StreamEnvironment`
  reports what the host does that we cannot switch off. A probe endpoint
  and `tools/sse-probe.mjs` measure, from the receiving end, whether any
  of it worked. **Verdict on the host measured: streaming** — client
  inter-frame gaps matched the server's to the millisecond. Written up
  with the negative control and the five unmeasured hosts in
  `docs/17-sse-spike.md`.
- **A heading-aware chunker** (FR-KB-06) at ~800 tokens with 15%
  overlap. Three rules: never merge across headings, split at the
  largest boundary that fits, and overlap only within a section. Every
  chunk is a literal substring of its document, so `char_start` and
  `char_end` can be trusted to highlight a citation.
- **`TokenEstimator`** that counts CJK at one token per character. The
  usual bytes-over-four rule under-counts Chinese, Japanese and Korean
  by three to four times, and a chunk that far over budget is truncated
  by the embedding endpoint rather than rejected.
- **`HtmlNormaliser`** built on DOM traversal rather than `strip_tags`,
  preserving heading structure, block boundaries and — for crawled pages
  — the absence of navigation chrome.
- **Seven extractors** (FR-KB-01 to 05): WordPress content, WooCommerce
  products (read-only), a web crawler, PDF, DOCX, FAQ with CSV import,
  and raw text. All are generators, so a 900-page manual never has to be
  resident at once.
- **`IngestionService`** with content hashing, so re-indexing an
  unchanged site does almost no work and costs nothing to re-embed;
  pruning, so a deleted page leaves the index; per-document failure
  isolation; live progress; and cancellation.
- REST: eight knowledge endpoints, and a **Knowledge** screen with live
  progress, cancel, re-index, and a chunk inspector that shows the actual
  boundaries retrieval will use.

#### Fixed

- **REST controllers registered by a module were never routed.**
  `RestServer::registerRoutes()` fired its `hiveclerk/rest/register`
  extension hook *after* iterating controllers, so anything a listener
  added was appended and then ignored. Present since Sprint 0 and
  invisible until the knowledge module became the hook's first real
  user — and invisible to `tools/verify-routes.php` too, because
  `rest_get_server()` fires `rest_api_init` a second time and the second
  pass registered what the first had collected. The checker reported
  19/19 routes present while the site returned 404. The hook now fires
  first, and the checker no longer double-fires.
- **`StreamEnvironment` reported a blocker on every host.** The
  removable-buffer check read `ob_get_status()['del']`, which PHP 8
  replaced with a `flags` bitmask. Caught by the probe contradicting it.

#### Security

- **The crawler could reach cloud instance metadata.**
  `wp_safe_remote_get()` refuses loopback and RFC 1918, but not
  link-local — so `169.254.169.254`, which serves a machine's own
  credentials on AWS, GCP and Azure, was allowed through to a real
  connection attempt. Measured, then fixed: every resolved address is
  now checked against `FILTER_FLAG_NO_PRIV_RANGE | NO_RES_RANGE` before
  a socket opens. Pre-flight resolution remains beatable by DNS
  rebinding; that limit is documented in the class.
- Password-protected posts are excluded from indexing unconditionally,
  and pages carrying `noindex` are skipped by the crawler.

#### Decisions worth recording

- **Progress is its own column, not a key in `config`.** `config` is
  what the operator chose and is rewritten whenever they save the form;
  progress is written by a background job every fifteen documents.
  Sharing a column means one overwrites the other, and which one depends
  on timing.
- **A crawl's progress bar is indeterminate.** A link crawl cannot know
  how many pages a site has until it has finished. A bar that reaches
  90% and stalls reads as a hang, and the operator cancels an import
  that was working.
- **Price and stock are product metadata, not indexed text.** Both
  change without the description changing; embedded in the chunk they
  would invalidate its vector and force a re-embed of the whole
  catalogue on any day the shop ran a sale.
- **`ignore_user_abort( true )` on streams.** Tokens already generated
  are already billed, and the usage row is written after the loop.
  Being killed mid-write loses the record and understates spend — the
  same reasoning that made `usage_events.cost` nullable in Sprint 2.

#### Not delivered this sprint

- **The crawl preview screen with a cost estimate** (D11 §7.2, R-3) is
  not built. The backend half exists — `WebCrawlExtractor::estimate()`
  returns a real page count when the site publishes a sitemap — but the
  *cost* half needs embedding prices per token, and `EmbeddingService`
  arrives in Sprint 4. Showing an estimate now would mean inventing the
  number that matters. Carried to Sprint 4, where it can be honest.
- **The FAQ editor UI.** The extractor, the CSV parser and the
  `POST /admin/knowledge/faq/parse` endpoint are done and tested; the
  add-source form covers site content, crawl and raw text only. A FAQ
  source can be created through the API but not yet through the screen.

#### Known gaps

- The SSE matrix has one row. Five shared hosts named in the sprint plan
  were not measured; the tooling and runbook to do it are in
  `docs/17-sse-spike.md` §6.1, and the matrix must be filled before
  Sprint 5 closes.
- The WooCommerce extractor is untested against a live catalogue —
  WooCommerce is not installed on the development site. Its unavailable
  path is exercised; its extraction path is not.
- PDF and DOCX extraction are unit-covered at the boundary only. No
  fixture files are committed yet.
- Crawler pacing, robots handling and URL canonicalisation are unit
  tested and exercised against one live site (`example.com`). Sitemap
  index recursion has not been exercised against a real multi-file
  sitemap.

### Sprint 2 — Providers, metering and the design system

**Goal:** an admin can paste an API key, verify it, and see a live model
list. Every mutation is audited.

#### Added

- **`LlmProviderInterface` and five adapters** — Anthropic, OpenAI, Google
  Gemini, Azure OpenAI and OpenRouter. Three wire protocols, not five:
  Azure and OpenRouter share OpenAI's `/chat/completions` shape, so they
  share its stream parser rather than each carrying a copy to get wrong.
- **A hand-written SSE parser.** Chunk boundaries fall wherever the network
  puts them, routinely mid-frame. A parser that assumes one chunk is one
  frame works in development and silently drops tokens in production.
  Tested against splits placed inside frames, CRLF endings, comment
  keep-alives, and a stream that ends without a terminator.
- **`KeyResolver`** — AES-256-GCM at rest, with three properties that
  matter: nothing decrypts on a read path (the mask is computed once at
  write time), a `HIVECLERK_<PROVIDER>_KEY` constant in `wp-config.php`
  wins over the database, and keys live in their own option so a settings
  export never carries ciphertext.
- **`PricingTable`** with dated-suffix matching, so
  `gpt-5-mini-2026-01-14` prices as `gpt-5-mini` and not as `gpt-5` — the
  longest family match, because the shorter one costs four times more.
  Filterable via `hiveclerk/pricing`, and stamped with the date the
  figures were checked.
- **`UsageEvent` recording** through `AiService`, which is the only way
  the plugin talks to a model. Concentrating it there is what makes
  metering impossible to forget: a retry or a summariser added later
  cannot spend the customer's money without appearing in their report.
- **`AuditLogger`** with redaction at the single door into the log.
  Secret-looking fields are replaced by `[redacted]` while the field
  itself is kept, because "a key was changed" is the record's whole
  point. IPs are stored as a salted hash.
- **`QueueInterface`** with an Action Scheduler driver and a WP-Cron
  fallback. Action Scheduler is not bundled — it ships inside WooCommerce
  and many other plugins, each negotiating which copy loads. The health
  endpoint reports which driver is active and how deep the queue is,
  because the two have genuinely different reliability.
- REST: `GET`/`PUT /admin/settings/providers`, `POST …/verify`,
  `GET …/models`, `DELETE …/{provider}`, `GET /admin/settings/audit-log`
  and `GET /admin/analytics/costs`.
- UI primitives: DataTable, Pagination, Filters, Modal, Drawer, Tabs,
  Field/Input/Select, Badge and Toast.
- **Settings → Providers** with verify-before-save, a live model picker
  showing each model's published price, and a 30-day spend panel.
- **Settings → Audit log** with filtering and a payload drawer.

#### Decisions worth recording

- **An unpriced call records no cost, not a zero.** Migration `M0008`
  makes `usage_events.cost` nullable. Zero is a claim that a call was
  free, which sums into a spend figure that is quietly wrong in the
  direction nobody checks. The summary counts unpriced calls separately
  and the UI says so.
- **Verify lists models rather than sending a completion.** It proves the
  same thing — valid key, reachable account — without spending the
  customer's money to find out whether their key works.
- **A provider that reports its own cost is believed over the table.**
  OpenRouter returns the actual charge, which includes whatever discount
  or routing applied. It is deliberately absent from `PricingTable`.
- **Streaming uses cURL directly, not the WordPress HTTP API.**
  `wp_remote_request()` returns only once the whole body has arrived, so
  a "streamed" reply would sit silent for the full generation.
  `supportsIncrementalStreaming()` reports which path is in play rather
  than promising a stream the host cannot deliver.

#### Fixed

- **`select` rendered white in dark mode.** Not wp-admin this time:
  `tailwind-merge` treats every `bg-*` utility as one conflict group, so
  adding an arbitrary `bg-[url(...)]` caret made `cn()` drop
  `bg-surface-sunken`, and wp-admin's unlayered
  `select { background: #fff … }` won. The caret is now an id-scoped CSS
  class. Measured before: `rgb(255,255,255)`; after: `rgb(15,18,24)`.
- **The test gate had been failing on exit code since Sprint 0.**
  `phpunit.xml.dist` requested a coverage report unconditionally, which
  makes PHPUnit warn and exit non-zero on any machine without Xdebug or
  PCOV — so `composer check` was red regardless of whether the tests
  passed. Coverage is now opt-in.
- **The integration suite pointed at a directory that did not exist.** It
  now exists, with its own bootstrap: the unit bootstrap defines `ABSPATH`
  as a path that does not exist so units can run without WordPress, and
  `wp-load.php` then tries to require `wp-includes` from it. The two
  cannot share a process.
- All five providers expanded at once on a fresh install — five identical
  key forms and no guidance. One card opens; the rest stay collapsed.

#### Verified

- 93 unit tests, 255 assertions. 7 integration tests against a real
  WordPress, asserting against the actual stored bytes: the ciphertext
  does not contain the key, `describe()` has no key field, storing a new
  key clears the old verification, tampered ciphertext reads as
  unconfigured, and `Credentials` refuses to serialise.
- End to end through `rest_do_request` against the live Anthropic API: a
  stored key decrypts and is sent, a 401 surfaces as the provider's own
  wording ("Rejected: API key is invalid."), the failed check is **not**
  recorded as verified, and the audit entry carries
  `{"key_changed":true}` with no key anywhere in the payload.
- A malformed key is rejected with 422 rather than sanitised — a quietly
  corrupted key fails later with an error that points at the provider
  instead of at us. A plain `http://` endpoint is refused outright.
- SEC-04: all 9 routes gated. PHPStan level 8 clean, PHPCS clean.

#### Known gaps

- Only the three distinct wire protocols have recorded-frame tests. Azure
  and OpenRouter are covered transitively through the shared parser, not
  against their own captured responses.
- `verify` and `models` are the only paths exercised against a live
  provider. `complete()` and `stream()` are tested against recorded
  frames; the first real conversation lands in Sprint 5.
- Action Scheduler's driver is untested on this machine — WooCommerce is
  not installed, so `CronQueue` is what actually ran.

### Admin shell — layout fixes and visual revision

Reported from a real screenshot: the sidebar was clipped under the WordPress
admin menu and most text was near-invisible. Four separate causes, all
confirmed by measuring the rendered page with Playwright rather than guessing.

#### Fixed

- **Sidebar overlapped the admin menu.** `#hvc-root` carried `margin-left:
  -20px`, which pulled the app 20px left of `#wpcontent`'s content box and
  underneath the menu. Measured `hvc-root.left = 140` against
  `adminMenu.right = 160`; now flush at 160.
- **wp-admin CSS overrode every token.** wp-admin's stylesheets are
  *unlayered* and Tailwind emits into `@layer`, so unlayered rules won
  regardless of specificity or order. Headings rendered `#1d2327` and links
  `#3858e9` instead of our tokens. Utilities are now emitted `!important`,
  paired with an unlayered `#hvc-root`-scoped reset.
- **App ran light-mode inside a dark wp-admin.** Theme resolution skipped the
  WordPress admin colour scheme, which the design system specifies as the
  middle step between explicit choice and OS preference. Now measured from
  `#adminmenuback`'s computed luminance, so it stays correct for the nine
  built-in schemes and any custom one.
- Notices, screen-meta and the footer no longer intrude into the app frame.

#### Changed — brand surface

- **Honey retired**, replaced by a spectral gradient (indigo → violet → cyan)
  used strictly as a *surface*: logomark, active nav and roster indicators,
  hero card top edge, empty-state mark, upgrade affordance. It never carries
  body text and never signals state, so it cannot be confused with a status
  colour.
- New **hexagon-in-hexagon logomark** — a cell in the hive — with a slow
  gradient drift. The only animated element in the product.
- Ambient radial wash behind the shell, surface top-edge highlights, and
  translucent blurred sidebar and header for depth in dark mode.
- Roster empty state moved into a dashed card; nav rows gained an icon
  colour shift; the licence footer gained a gradient upgrade affordance.

#### Added

- `tools/shot.mjs` — Playwright harness that screenshots and measures the
  live admin page. Mints a short-lived session cookie rather than touching
  the user's password, and supports `--diagnose`, `--theme=` and `--route=`.
  Playwright was already the E2E tool named in the testing strategy.

### Sprint 1 — Data layer and authentication (milestone M0)

**Goal:** tables create and roll back, and the SPA authenticates against a real
endpoint.

#### Added

- Versioned migration runner with `up()`/`down()`, a concurrency lock, and
  failure recovery that leaves the version at the last migration that
  succeeded. **`dbDelta()` is not used at all** — it silently mangles the
  `VARBINARY(256)` quantised-embedding column and the `FULLTEXT` index that
  retrieval depends on. Verified: both survive intact.
- **27 tables** across 7 migrations. Verified 27 → 0 → 27 through a full
  rollback and re-migrate cycle.
- `Schema` as the single source of truth for table names, validating every
  identifier against a hard-coded allowlist before it reaches SQL.
- Domain layer: `Agent`, `Conversation`, `Message`, `KnowledgeSource`, four
  status enums, `Uuid` and `Pagination` value objects. Imports nothing —
  enforced by the domain-purity rule.
- Four repositories behind domain-declared interfaces, with soft delete,
  filtered pagination, and transactional cascade delete.
- REST server, controller base, response envelope and 11 stable error codes.
- `Encryptor` — AES-256-GCM with a key derived from WordPress salts plus a
  per-install salt held separately, so a database dump alone does not expose
  provider keys. Authenticated: tampered ciphertext returns null.
- `RateLimiter` — sliding window over the object cache, first defence against
  SEC-03 cost exhaustion.
- `GET /system/status` and `/system/health`, wired to the dashboard through
  React Query with skeletons and typed error handling.

#### Fixed

- `wpdb::prepare()` returns null on a placeholder mismatch and that null was
  being passed straight to `query()`, which would have sent the literal string
  `"null"` to MySQL. Found by PHPStan; all such calls now go through a
  checked `execute()` helper.
- `Encryptor` did not guard against a zero-length IV, which would have
  produced deterministic ciphertext instead of failing.

#### Verified

- `hiveclerk.noGlobalWpdb` confines all SQL to `src/Database`.
- Every REST route has a real permission callback (SEC-04), checked by
  `tools/verify-routes.php`. Auth enforced end to end: 401 anonymous,
  403 subscriber, 200 administrator.
- A published clerk over its token budget reports `isServing() === false` —
  the SEC-03 guard lives in the domain, not in a caller's memory.
- 44 unit tests, 155 assertions.

#### Known gaps

- `literal-string` inference on `wpdb::prepare()` is suppressed **only** in
  `src/Database`, with the justification recorded in `phpstan.neon.dist`:
  table identifiers cannot be placeholders, `Schema::table()` allowlists every
  one, and `noGlobalWpdb` proves no SQL exists elsewhere. All values still go
  through placeholders.
- Integration tests still run through `wp eval`; the wp-env PHPUnit suite is
  wired in Sprint 2.

### Sprint 0 — Scaffold and CI

**Goal:** an empty plugin that activates cleanly and blocks bad code from
merging. No product features yet, by design.

#### Added

- Plugin bootstrap with PHP and WordPress version guards. `hiveclerk.php`
  stays PHP 5.6-parseable so unsupported hosts get a readable admin notice
  rather than a white-screen parse error.
- PSR-11 dependency-injection container with circular-dependency detection.
  Hand-written rather than pulled from a package: duplicated Composer
  dependencies across plugins are the most common cause of fatal errors in
  the WordPress ecosystem.
- Module registry with a two-phase lifecycle — every module registers its
  services before any module boots, so cross-module dependencies resolve
  regardless of registration order.
- Domain event bus. A throwing listener is logged and skipped rather than
  breaking the request that dispatched the event.
- Seven custom capabilities with a default role map. `shop_manager` gets
  operational access but never settings, which holds the API key.
- Injectable `Clock` so no test depends on the wall clock.
- Settings repository backed by a single non-autoloaded option.
- Admin page mounting a standalone React 19 SPA, with a boot object carrying
  REST root, nonce, capabilities, locale and branding so the shell renders
  without a round-trip.
- Vite manifest reader; no build output path is hard-coded server-side.
- Opt-in uninstall routine. Deactivation removes nothing.
- React 19 + TypeScript + Tailwind 4 SPA shell: hash router, app shell,
  sidebar, **Roster rail**, top bar, and light/dark theming.
- Design tokens for both themes, matching Deliverable 12.
- UI primitives: Button, Card, StatusDot, EmptyState.
- Typed API client with an error class carrying the server's own error code.

#### CI gates

- PHPStan level 8 — clean.
- **Custom rule `hiveclerk.domainPurity`** — fails the build on any WordPress
  function call inside `src/Domain`. This is what makes the SaaS extraction a
  rebinding rather than a rewrite. Verified to fire.
- **Custom rule `hiveclerk.noGlobalWpdb`** — confines `$wpdb` to
  `src/Database`, `src/Infrastructure/Wordpress` and `uninstall.php`.
  Verified to fire.
- PHPCS against WordPress-Core — clean.
- PHPUnit — 12 tests, 15 assertions, passing.
- `tsc --noEmit` — clean.
- **ESLint `no-restricted-imports` blocking all `@wordpress/*` packages.**
  Verified to fire.
- size-limit — admin bundle 94.55 kB gzipped against a 350 kB budget.

#### Known gaps

- Font files are not bundled. `assets/fonts/README.md` documents what to add;
  the UI falls back to system faces until then.
- Database migrations, REST routes and repositories land in Sprint 1.
- All screens except Dashboard are scaffolds that state which sprint builds
  them. No placeholder data is shown anywhere — an invented metric is worse
  than an honest empty state.
