# Hiveclerk — Architecture Documentation

**A hive of AI clerks for your website.**
A commercial WordPress AI Employee Platform by **Decent Themes**.

> **No implementation begins until all 16 deliverables are reviewed and approved.**

---

## Locked Identifiers

Agreed 2026-08-05. These propagate through every subsequent document and all code.

| Concern | Value |
|---|---|
| Product name | Hiveclerk |
| WordPress.org slug | `hiveclerk` |
| PHP namespace | `Hiveclerk\` |
| Text domain | `hiveclerk` |
| Database table prefix | `wp_hvc_` |
| REST namespace | `hiveclerk/v1` |
| CSS / JS prefix | `hvc-` |
| Option prefix | `hiveclerk_` |
| SaaS domains | `hiveclerk.com`, `app.hiveclerk.com` |

**Name verification.** 36 candidates screened across 4 rounds. `Hiveclerk` returns no exact-match commercial entity on web search and 0 results on WordPress.org plugin search. The original working name *AgentHive AI* was rejected — the WP.org slug was free, but 4+ AI-agent companies use the name, including one asserting ™ in the same category. **This is not formal trademark clearance;** a USPTO/EUIPO Class 9 + 42 search is recommended before commercial launch.

---

## Deliverable Status

### Phase 1 — Product & Market
| # | Document | Status |
|---|---|---|
| 1 | [Product Requirement Document](01-prd.md) | ✅ Draft complete |
| 2 | [Market Positioning Analysis](02-market-positioning.md) | ✅ Draft complete |
| 3 | [Competitor Analysis](03-competitor-analysis.md) | ✅ Draft complete |
| 4 | [Feature Matrix](04-feature-matrix.md) | ✅ Draft complete |
| 5 | [User Personas](05-user-personas.md) | ✅ Draft complete |

**Gate 1:** ✅ Approved

### Phase 2 — Technical Architecture
| # | Document | Status |
|---|---|---|
| 6 | [System Architecture](06-system-architecture.md) | ✅ Draft complete |
| 7 | [Database Schema](07-database-schema.md) | ✅ Draft complete |
| 8 | [ERD Diagram](08-erd.md) | ✅ Draft complete |
| 9 | [API Specification](09-api-specification.md) | ✅ Draft complete |
| 10 | [Plugin Folder Architecture](10-folder-architecture.md) | ✅ Draft complete |

**Gate 2:** ✅ Approved

### Phase 3 — UI/UX
| # | Document | Status |
|---|---|---|
| 11 | [UI/UX Wireframes](11-wireframes.md) | ✅ Draft complete |
| 12 | [Design System](12-design-system.md) | ✅ Draft complete |

**Gate 3:** ✅ Approved

### Phase 4 — Delivery
| # | Document | Status |
|---|---|---|
| 13 | [Development Roadmap](13-development-roadmap.md) | ✅ Draft complete |
| 14 | [Sprint Plan](14-sprint-plan.md) | ✅ Draft complete |
| 15 | [Testing Strategy & Security Review](15-testing-strategy.md) | ✅ Draft complete |
| 16 | [Monetization Strategy](16-monetization-strategy.md) | ✅ Draft complete |

**Gate 4:** ⬜ Awaiting approval — **the final gate before coding**

### Spike results

Written during implementation, when a risk turned out to need an answer
before the work depending on it could be trusted.

| # | Document | Result |
|---|---|---|
| 17 | [SSE Host-Compatibility Spike](17-sse-spike.md) | TD-2 confirmed with three amendments · R-2 open on 5 of 6 hosts |

### Phase 5 — Implementation

| Sprint | Goal | Status |
|---|---|---|
| **0** | Scaffold + CI | ✅ **Complete — all gates green** |
| **1** | Migrations, repositories, REST, auth | ✅ **Complete — M0 reached** |
| **2** | Provider adapters, design system, settings | ✅ **Complete** |
| **3** | Ingestion pipeline + SSE host spike | ✅ **Complete — TD-2 confirmed** |
| **4** | Vector store + retrieval (**M1 gate**) | ⚠️ **Complete — M1 met on latency and memory; recall measured for quantisation only** |
| 5 | Chat + widget (**M2 gate**) | ⬜ Next |
| 6–10 | See [Sprint Plan](14-sprint-plan.md) | ⬜ |

**Sprint 0 verification** — run `composer check` and `npm run check`:

| Gate | Result |
|---|---|
| PHPStan level 8 | ✅ clean |
| `hiveclerk.domainPurity` custom rule | ✅ clean · **verified to fire** on a probe |
| `hiveclerk.noGlobalWpdb` custom rule | ✅ clean · **verified to fire** on a probe |
| PHPCS (WordPress-Core) | ✅ clean |
| PHPUnit | ✅ 12 tests, 15 assertions |
| `tsc --noEmit` | ✅ clean |
| ESLint `@wordpress/*` block | ✅ clean · **verified to fire** on a probe |
| size-limit | ✅ 94.55 kB gzipped / 350 kB budget |
| Activation on WP 7.0.2 + PHP 8.4.7 | ✅ clean, capabilities granted |
| Deactivate → reactivate | ✅ lossless (settings survive) |

**Sprint 1 verification (M0)**

| Gate | Result |
|---|---|
| Migrations | ✅ 27/27 tables · rollback 27 → 0 → 27 clean |
| `VARBINARY(256)` + `FULLTEXT` survive | ✅ intact (why `dbDelta` is unused) |
| Repositories | ✅ CRUD, JSON round-trip, filters, cascade delete, soft delete |
| REST auth | ✅ 401 anonymous · 403 subscriber · 200 admin |
| SEC-04 route gating | ✅ `wp eval-file tools/verify-routes.php` |
| SEC-03 budget guard | ✅ over-budget clerk reports `isServing() === false` |
| Encryptor | ✅ round-trip, tamper rejected, random IV, masked display |
| PHPUnit | ✅ 44 tests, 155 assertions |
| PHPStan L8 + PHPCS + `tsc` + ESLint | ✅ clean |
| size-limit | ✅ 98.55 kB gzipped / 350 kB |

**Sprint 2 verification (providers and metering)**

| Gate | Result |
|---|---|
| Five provider adapters | ✅ 3 wire protocols; Azure and OpenRouter share OpenAI's parser |
| SSE parser across chunk boundaries | ✅ mid-frame splits, CRLF, comments, missing terminator |
| Key at rest | ✅ ciphertext contains no key · `describe()` has no key field · tamper → unconfigured |
| `Credentials` serialisation | ✅ refuses (`__sleep()` throws) |
| Live provider round trip | ✅ 401 from Anthropic surfaces as its own wording; not recorded as verified |
| Audit redaction | ✅ 10 secret field-name shapes, nested payloads; field kept, value replaced |
| Input validation | ✅ malformed key 422 (rejected, not sanitised) · plain `http://` endpoint refused |
| SEC-04 route gating | ✅ 9/9 routes gated |
| Migration `M0008` | ✅ `usage_events.cost` nullable; 27 tables unchanged |
| PHPUnit | ✅ 93 unit (255 assertions) + 7 integration |
| PHPStan L8 + PHPCS + `tsc` + ESLint | ✅ clean |
| size-limit | ✅ 125.57 kB gzipped / 350 kB |
| Both themes rendered and measured | ✅ Playwright, no wp-admin bleed |

**Sprint 4 verification (M1 — retrieval)**

Measured with `wp eval-file tools/retrieval-bench.php 1000,10000,50000 30`
on PHP 8.4.7 / MySQL 8, **without** a persistent object cache.

| Gate | Result |
|---|---|
| **M1 · recall@5 ≥ 0.90 at 10k** | ⚠️ **1.000 for quantisation** — end-to-end recall against real questions is **not yet measured** (no embedding key on this site) |
| **M1 · ≤ 300 ms p95 at 10k** | ✅ 35 ms warm · 34 ms on a fresh request · 128 ms cold build |
| **M1 · ≤ 96 MB peak** | ✅ 89 MB at 10k |
| Stage split at 10k | ✅ stage 1 6.9 ms · stage 2 25.1 ms · fusion under 1 ms |
| float32 BLOB round trip | ✅ max drift 1.49 × 10⁻⁸ against regenerated vectors |
| Coarse pass keeps the true nearest neighbour | ✅ property test, planted neighbour in a 400-vector corpus |
| Adversarial uniform corpus (reported, not gated) | 0.920 @ 1k · 0.800 @ 10k · 0.660 @ 50k |
| 50,000 chunks without Redis | 🔴 index uncacheable above ~16k chunks → 1.1 s per search |
| SEC-04 route gating | ✅ 22/22 routes gated |
| PHPUnit | ✅ 184 unit (1,144 assertions) + 7 integration |
| PHPStan L8 + PHPCS + `tsc` + ESLint | ✅ clean |
| size-limit | ✅ 132.46 kB gzipped / 350 kB |
| Playground + Embedding in both themes | ✅ Playwright, incl. the keyword-only degradation path |

---

## Decisions Required Before Sprint 0

| # | Decision | Recommendation | Source |
|---|---|---|---|
| 1 | **Team size** — plan is ~236 engineer-days against ~176 capacity (34% over) | Add a third engineer from Sprint 3, or extend to 13 sprints | [D14 §13](14-sprint-plan.md) |
| 2 | Managed tier price **$49/mo** (not $39) | Approve — $39 yields 74% GM at full quota | [D16 §11](16-monetization-strategy.md) |
| 3 | **Q-1:** no managed key on Free; 25-conversation trial credit instead | Approve — ~$2,600 one-off vs. ~$5,200/month | [D16 §5](16-monetization-strategy.md) |
| 4 | **Q-2:** annual only, no lifetime; 40% first-year promo for 500 | Approve | [D16 §6](16-monetization-strategy.md) |
| 5 | **Q-5:** Merchant of Record + self-hosted licence API; reject Freemius | Approve | [D16 §7](16-monetization-strategy.md) |
| 6 | External penetration test before launch (~$3–6k) | Recommended — SEC-01 and SEC-03 are novel AI attack classes | [D15 §14](15-testing-strategy.md) |

---

## Open Questions — answered by working assumption

Phase 2 proceeded on these assumptions. **All remain cheap to reverse.** Confirm or correct before Phase 4.

| # | Question | Working assumption | Blocks |
|---|---|---|---|
| Q-1 | Free tier with a managed key on low quota? | BYO-key in V1; Managed tier V1.x. Key-custody abstraction ships in V1 either way. | Deliverable 16 |
| Q-2 | Annual-only, or lifetime launch promo? | Annual-only. No architectural impact. | Deliverable 16 |
| Q-3 | WooCommerce context in V1 or V2? | **Read-only product indexing in V1**; cart/order/transactional deferred to V2. | Deliverable 13 |
| Q-4 | Which CRM first? | **FluentCRM** (local, no OAuth), then Groundhogg, then HubSpot. | Deliverable 14 |
| Q-5 | Licensing infrastructure? | Self-hosted behind `LicenceService`; swappable. | Sprint 1 |

## Technical Decisions — resolved in Deliverable 6

| # | Decision | Resolution |
|---|---|---|
| TD-1 | Vector storage and search | **Binary-quantized two-stage retrieval** over MySQL BLOBs, behind `VectorStoreInterface` |
| TD-2 | Streaming transport | **SSE with probe-frame detection** and automatic polling fallback |
| TD-3 | Background processing | **Action Scheduler**, bounded self-re-enqueueing batches |
| TD-4 | Admin SPA routing | **Hash router**, single admin page, boot-object hydration |
| TD-5 | Model key custody | **Both** — encrypted site key now, managed gateway adapter ready |
| TD-6 | Embedding provider | **Pluggable**, provider/model/dimension pinned per source |

---

## Target Stack

**Backend** — WordPress 6.6+, PHP 8.3+ (local: 8.4.7), MySQL 8+, REST API, Action Scheduler, Composer, PSR-12, dependency injection, repository + service layer patterns.

**Admin SPA** — React 19, TypeScript, Vite, Tailwind CSS 4, React Query, React Router, Zustand, React Hook Form, Zod, Recharts, Headless UI.

> **Constraint:** no `@wordpress/components`, `@wordpress/data`, `@wordpress/element`, or any Gutenberg package. The admin is a standalone SPA mounted in a WordPress admin page.
