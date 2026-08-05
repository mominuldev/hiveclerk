# CLAUDE.md

Working guide for this repository. Derived from `docs/` (deliverables 1–17) and
the WordPress coding standards this plugin is held to. Read this before writing
code; read the specific deliverable before designing anything.

---

## 1. What this is

**Hiveclerk** — a commercial WordPress AI Employee Platform by Decent Themes.
A hive of AI clerks that answer from your own content, capture leads, and sync
them onward. Self-hosted: the customer's data never leaves their server.

Phase 5 (implementation) is in progress. Sprints 0–3 are complete; the sprint
plan is `docs/14-sprint-plan.md` and every sprint's outcome is recorded in
`CHANGELOG.md` — including what was *not* delivered.

---

## 2. Locked identifiers — never invent a variant

| Concern | Value |
|---|---|
| Product name | Hiveclerk |
| WordPress.org slug | `hiveclerk` |
| PHP namespace | `Hiveclerk\` |
| Text domain | `hiveclerk` |
| Table prefix | `{$wpdb->prefix}hvc_` |
| REST namespace | `hiveclerk/v1` |
| CSS / JS prefix | `hvc-` |
| Option prefix | `hiveclerk_` |
| Hook prefix | `hiveclerk/…` (slash-separated) |

Hooks are namespaced with slashes — `hiveclerk/lead/captured` — not
underscores. PHPCS's `ValidHookName` is excluded for `src/` on that basis.

---

## 3. Commands

```bash
composer check        # phpcs + phpstan L8 + phpunit (unit + integration)
composer lint         # phpcs                    composer lint:fix  → phpcbf
composer analyse      # phpstan --memory-limit=1G
composer test:unit    # phpunit
composer test:integration   # phpunit -c phpunit-integration.xml.dist (needs a real WP)

npm run check         # tsc --noEmit + eslint + vite build + size-limit
npm run typecheck     # tsc --noEmit
npm run build         # tsc --noEmit && vite build → assets/admin/

wp eval-file tools/verify-routes.php    # asserts every REST route is capability-gated
node tools/shot.mjs --route=knowledge   # Playwright screenshot + measurement of live admin
node tools/sse-probe.mjs                # measures streaming from the receiving end
```

`assets/` **is committed** — WordPress.org distributes source ZIPs with no build
step. Rebuild and commit it whenever the SPA changes.

---

## 4. Layering — the rule that makes the V3 SaaS a rebinding, not a rewrite

```
PRESENTATION   admin-app/ (React 19)   ·   public-widget/ (Preact)
API            src/Api/, src/Modules/*/Http/  — nonce, capability, validation, rate limit
APPLICATION    src/Modules/*/Services/        — orchestration, transactions, events
DOMAIN         src/Domain/                    — entities, value objects, ports. IMPORTS NOTHING.
PERSISTENCE    src/Database/                  — the only layer that touches $wpdb
INFRASTRUCTURE src/Infrastructure/, src/Ai/   — provider adapters, queue, HTTP, crypto
```

Dependencies point downward only. Collaborators arrive through constructor
injection from the PSR-11 container in `src/Core/Container/`. Nothing calls a
global.

Two custom PHPStan rules enforce this and **both are verified to fire**:

- `hiveclerk.domainPurity` — no WordPress function may be called inside
  `src/Domain/`. Not `apply_filters`, not `__()`, nothing.
- `hiveclerk.noGlobalWpdb` — `$wpdb` exists only in `src/Database/`,
  `src/Infrastructure/Wordpress/` and `uninstall.php`.

If a rule fires, the fix is the code, not the rule.

---

## 5. Conventions that are already load-bearing

### PHP

- `declare( strict_types=1 );` on every file. PSR-4 filenames, PSR-12 method
  names (camelCase — PHPCS's `ValidFunctionName` and `ValidVariableName` are
  excluded for `src/` on that basis).
- WordPress-Core brace and spacing style: `array()` long form is *not* required
  (short syntax is allowed) but spaces inside parentheses are:
  `foo( $bar )`, `array( 'a' => 1 )`.
- Every class, method and property carries a docblock. Comments explain **why**,
  never what — the existing code is the reference for tone. A comment that
  restates the line below it is noise; a comment that records the failure a
  design avoids is the point.
- Enums for closed sets (`SourceStatus`, `ProviderId`, `UsageKind`). Readonly
  promoted constructor properties for value objects.
- `final class` unless designed for extension.

### Database

- `Schema::table( Schema::CHUNKS )` is the **only** way a table name reaches
  SQL. `Schema::all()` is a hard-coded allowlist and `table()` throws on
  anything else — that is what makes "no user input in an identifier position"
  checkable by reading one file.
- Every value goes through `$wpdb->prepare()`. `prepare()` returns `null` on a
  placeholder mismatch, so use `AbstractRepository::execute()`, which checks
  before it queries.
- Sortable columns are whitelisted (`sortableColumns()`), never escaped — an
  identifier cannot be parameterised.
- **`dbDelta()` is not used anywhere.** It mangles `VARBINARY(256)` and the
  `FULLTEXT` index retrieval depends on. Migrations are a versioned runner
  (`src/Database/Migrator.php`) with `up()`/`down()`, run on `admin_init`, not
  on activation.
- No database-level foreign keys — deliberate (`docs/07 §1.1`). Integrity is
  enforced in repositories.
- Timestamps are `DATETIME` in **UTC** (`gmdate( 'Y-m-d H:i:s' )`), never
  `TIMESTAMP`. Money is `DECIMAL(12,6)`. **Unknown money is `NULL`, never `0`** —
  a zero sums into a spend figure that is wrong in the direction nobody audits.

### Background work

Anything slow is a job, never a request. Jobs implement `JobInterface`, declare
their own `hook()`, process a **bounded batch and re-enqueue themselves** if
work remains, and never exceed ~20 seconds. `QueueInterface` has an Action
Scheduler driver and a WP-Cron fallback; Action Scheduler is *not* bundled, so
every call to it is `function_exists()`-guarded.

### Front end

- React 19 + TypeScript + Tailwind 4 + React Query + Zustand. Hash router.
- **No `@wordpress/*` packages at all** — ESLint `no-restricted-imports` fails
  the build. Verified to fire.
- React Query owns all server state. Zustand owns only ephemeral UI state.
- No hard-coded hex outside `admin-app/src/styles/tokens.css`. Both themes are
  verified for any UI, and wp-admin's *unlayered* CSS beats Tailwind's `@layer`
  — utilities are emitted `!important` with a `#hvc-root`-scoped reset.
- Keyboard reachable, visible focus, `prefers-reduced-motion` honoured.
- Never `dangerouslySetInnerHTML` on model output.

---

## 6. Security — non-negotiable

### Sanitize on the way in, escape on the way out, validate always

Sanitizing is not validating. `sanitize_text_field()` on a malformed API key
produces a *quietly corrupted* key that fails later with an error pointing at
the provider instead of at us. Reject it with 422 instead.

**Input, at the boundary only:**

| Data | Function |
|---|---|
| Plain single-line text | `sanitize_text_field()` |
| Multi-line text (FAQ answers, raw sources) | `sanitize_textarea_field()` — the single-line version silently flattens |
| Array/option keys | `sanitize_key()` |
| Email | `sanitize_email()` |
| URL for storage/redirect | `esc_url_raw()` |
| Integers | `absint()` / `(int)` |
| Filenames | `sanitize_file_name()` |
| Nested config arrays | recurse, key-by-key — see `SourceController::clean()` |

Register `sanitize_callback` in the route's `args` **and** re-clean anything
that lands in a JSON column, because a JSON column is read back by code that
builds queries and HTTP requests from it.

**Output:**

| Context | Function |
|---|---|
| HTML text | `esc_html()` / `esc_html__()` |
| Attribute | `esc_attr()` / `esc_attr__()` |
| URL in markup | `esc_url()` |
| Inline JS value | `wp_json_encode()` — never string concatenation |
| Textarea contents | `esc_textarea()` |
| Limited markup | `wp_kses( $html, $allowed )` / `wp_kses_post()` |

Escape **late**, at the point of output, even when the value was sanitized on
input. Server-rendered PHP in this plugin is confined to `AdminPage.php` and
`templates/` — the SPA is the presentation layer, and it receives JSON that it
must not inject as HTML.

**Every REST route:**

- Has a real `permission_callback`. Never `__return_true`.
  `tools/verify-routes.php` asserts this across all routes and it is part of the
  gate (SEC-04).
- Uses `AbstractController::requires( Capabilities::… )`, which returns 401 for
  anonymous and 403 for insufficient capability.
- Admin routes rely on WordPress cookie auth + `X-WP-Nonce`; public widget
  routes will use signed HMAC session tokens carrying no PII.
- Anything that can cost the customer money is rate limited through
  `RateLimiter` (SEC-03 cost exhaustion is cheaper to execute than a DoS and
  hurts more).

**Capabilities** — seven custom ones in `Capabilities.php`, mapped to roles on
activation. `shop_manager` gets operational access but never `manage_settings`,
which holds the API key.

**Secrets** — AES-256-GCM at rest via `Encryptor`, key derived from WordPress
salts plus a per-install salt held separately. Keys never leave the server: the
SPA gets a masked display value and a boolean. `Credentials::__sleep()` throws
so a key can never reach a transient, a job payload or a debug log.

**Outbound HTTP** — TLS with certificate verification enforced. Any URL that
originates from a customer is resolved and checked against
`FILTER_FLAG_NO_PRIV_RANGE | NO_RES_RANGE` before a socket opens:
`wp_safe_remote_get()` blocks loopback and RFC 1918 but **not** link-local, and
`169.254.169.254` serves cloud instance credentials.

**Prompt injection** — retrieved chunks and visitor input are data, never
instruction. They are delimiter-isolated and explicitly declared untrusted in
the prompt. Model output is filtered before display. See `docs/15` §Prompt
Injection.

**Audit** — every configuration mutation goes through `AuditLogger`, which
redacts at the single door into the log: secret-looking fields are replaced by
`[redacted]` while the field name is kept, because "a key was changed" is the
record's whole point. IPs are stored as a salted hash.

---

## 7. Definition of Done (from `docs/14` §1)

- [ ] Acceptance criteria met and demonstrated
- [ ] Unit tests written; service-layer coverage ≥ 70%
- [ ] PHPStan L8, PHPCS, domain-purity rule, `tsc --noEmit` all green
- [ ] Both light and dark themes verified for any UI
- [ ] Keyboard reachable with visible focus; `prefers-reduced-motion` honoured
- [ ] Strings passed through the text domain
- [ ] Capability + nonce check on any new endpoint
- [ ] No hard-coded hex outside the token file
- [ ] Reviewed and merged; **CHANGELOG updated**

### Performance budgets (`docs/06` §14)

| Path | Budget |
|---|---|
| Widget JS gzipped | ≤ 40 KB |
| Time to first token | ≤ 1.5 s p95 |
| Retrieval | ≤ 300 ms p95 at 10k chunks |
| Admin REST p95 | ≤ 400 ms |
| Admin bundle | ≤ 350 KB gzipped |
| Peak memory per request | ≤ 96 MB |

---

## 8. Writing the CHANGELOG

`CHANGELOG.md` is Keep a Changelog, newest sprint first, and it is written to be
read by someone deciding whether to trust the code. Each sprint entry carries:

- **Added / Fixed / Security** — what changed, and *why it mattered*
- **Decisions worth recording** — the trade-off and the failure it avoids
- **Verified** — what was actually measured, with numbers
- **Not delivered this sprint** — named, with the reason and where it went
- **Known gaps** — what is untested and what could still be wrong

A bug entry says what broke, why it was invisible, and what caught it. Do not
claim a measurement that was not taken; an honest gap is worth more than a
confident sentence.

---

## 9. Documentation map

| # | Read it before |
|---|---|
| `01-prd.md` | changing scope or a requirement ID |
| `06-system-architecture.md` | anything structural — TD-1…TD-6, caching, budgets |
| `07-database-schema.md` | any migration or column |
| `09-api-specification.md` | any REST route, envelope, error code, port interface |
| `10-folder-architecture.md` | placing a new file |
| `11-wireframes.md` | any screen |
| `12-design-system.md` | any token, component or interaction |
| `14-sprint-plan.md` | picking up work |
| `15-testing-strategy.md` | testing or security review |
| `17-sse-spike.md` | anything touching streaming |

Requirement IDs (`FR-KB-07`, `NFR-03`, `SEC-04`, `TD-1`, `R-2`) are stable and
appear in commits, docblocks and the CHANGELOG. Use them.

---

## 10. House style for the product's own voice

Errors state what happened, what it means, and what happens next. No apology,
no vagueness. Empty states are invitations, not dead ends. Never show an
invented metric — an honest empty state beats a plausible number. Cost is shown
before commitment, never after.
