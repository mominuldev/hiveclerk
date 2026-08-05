# Hiveclerk — Sprint Plan

**Deliverable 14 of 16** · Version 1.0 · Status: **Draft — awaiting approval** · 2026-08-05

11 sprints (0–10) · 2 weeks each · ~16 engineer-days per sprint after ceremonies and 15% buffer.

**Roles:** `BE` PHP/WordPress · `FE` React/TypeScript · `DS` Design · `QA` Product/QA

---

## 1. Working Agreement

### Definition of Ready
A story enters a sprint only when it has: a linked requirement ID, acceptance criteria, a wireframe reference if it has UI, and a test approach.

### Definition of Done
- [ ] Acceptance criteria met and demonstrated
- [ ] Unit tests written; service-layer coverage ≥ 70%
- [ ] PHPStan L8, PHPCS, domain-purity rule, `tsc --noEmit` all green
- [ ] Both light and dark themes verified for any UI
- [ ] Keyboard reachable with visible focus; `prefers-reduced-motion` honoured
- [ ] Strings passed through the text domain
- [ ] Capability + nonce check on any new endpoint
- [ ] No hard-coded hex outside the token file
- [ ] Reviewed and merged; CHANGELOG updated

### Ceremonies
Planning 2h at sprint start · daily 15m · demo 1h and retro 45m at sprint end · backlog refinement 1h mid-sprint. **Demo is to a real design partner from Sprint 5 onward**, not internal-only.

---

## 2. Sprint 0 — Scaffold and CI

**Goal:** an empty plugin that activates cleanly and blocks bad code from merging.

| Story | Req | Owner | Days |
|---|---|---|---|
| Repository, branch strategy, PR template, CHANGELOG | — | BE | 0.5 |
| Plugin bootstrap, header, version and requirement guards | NFR-06 | BE | 1 |
| PSR-11 container + service providers + module registry | — | BE | 2 |
| Composer, PHP-Scoper prefixing, autoload | — | BE | 1.5 |
| CI: PHPStan L8, PHPCS/WPCS, PHPUnit, domain-purity rule | NFR-13 | BE | 2 |
| Vite + React 19 + TS + Tailwind 4 build pipeline | — | FE | 2 |
| CI: `tsc`, ESLint with `no-restricted-imports` for `@wordpress/*`, size-limit | — | FE | 1.5 |
| SPA mount point, boot object, hash router shell | TD-4 | FE | 2 |
| Design tokens as CSS variables, both themes, theme provider | D12 §2 | DS+FE | 2 |
| Local dev environment docs (wp-env) | — | QA | 1 |

**Exit:** `composer install && npm run build` produces a plugin that activates on PHP 8.3 and 8.4 with a routable, themed empty SPA. CI blocks merges on any failure.

---

## 3. Sprint 1 — Data layer and authentication

**Goal:** M0 walking skeleton.

| Story | Req | Owner | Days |
|---|---|---|---|
| Versioned migration runner with rollback | D7 §1.2 | BE | 2 |
| All 27 table migrations | D7 | BE | 2.5 |
| `AbstractRepository`, query builder, prepare-safe helpers | — | BE | 2 |
| Repositories: Agent, Conversation, Message, Source | — | BE | 2 |
| REST server, controller base, response envelope, error codes | D9 §1 | BE | 2 |
| Middleware: nonce, capability, validation, rate limit | FR-SYS-06 | BE | 2 |
| Custom capabilities + role mapping on activation | FR-SYS-02 | BE | 1 |
| `Encryptor` (AES-256-GCM) + key derivation | FR-SYS-03 | BE | 1.5 |
| API client, React Query setup, error handling, toasts | — | FE | 2 |
| AppShell: sidebar, header, **Roster rail**, command palette skeleton | D11 §2 | FE | 3 |
| Button, Input, Card, Badge, StatusDot, Skeleton, Toast | D12 §6 | FE+DS | 3 |

**Exit:** M0. Tables create and roll back, SPA authenticates against a real endpoint, CI green.

---

## 4. Sprint 2 — Providers and design system

**Goal:** talk to a model; look like the design system.

| Story | Req | Owner | Days |
|---|---|---|---|
| `LlmProviderInterface` + `AiService` + `PricingTable` | D9 §5 | BE | 2 |
| Anthropic adapter incl. streaming parser | FR-ONB-02 | BE | 2 |
| OpenAI adapter incl. streaming parser | FR-ONB-02 | BE | 1.5 |
| Google, Azure, OpenRouter adapters | FR-ONB-02 | BE | 2 |
| `KeyResolver`, encrypted storage, masked read-back, verify endpoint | FR-ONB-03, FR-SYS-03 | BE | 2 |
| `UsageEvent` recording + cost calculation | FR-ANL-04 | BE | 1.5 |
| Action Scheduler integration + `QueueInterface` + job base | TD-3 | BE | 2 |
| DataTable, Pagination, Filters, EmptyState, Modal, Drawer, Tabs | D12 §6 | FE | 3.5 |
| Settings → Providers screen with verify and model picker | D11 §11 | FE | 2.5 |
| Audit log service + writes on all config mutations | FR-SYS-05 | BE | 1 |

**Exit:** an admin can paste an API key, verify it, and see a live model list. Every mutation is audited.

---

## 5. Sprint 3 — Ingestion and the SSE spike

**Goal:** get content into the database as chunks. **De-risk streaming early.**

| Story | Req | Owner | Days |
|---|---|---|---|
| ⚑ **SSE host-compatibility spike** across 5 shared hosts | R-2, TD-2 | BE | 2 |
| `IngestionService` + extractor interface + job pipeline | FR-KB-08 | BE | 2 |
| WP content extractor: posts, pages, CPTs, taxonomy filters | FR-KB-01 | BE | 2 |
| WooCommerce product extractor (read-only, Q-3) | FR-KB-01 | BE | 1.5 |
| Web crawler: sitemap, recursive, robots.txt, rate limiting, caps | FR-KB-02 | BE | 3 |
| PDF and DOCX extractors | FR-KB-03 | BE | 2 |
| FAQ editor + CSV import + raw text source | FR-KB-04, 05 | BE | 1.5 |
| Heading-aware chunker with overlap + content hashing | FR-KB-06 | BE | 2 |
| Knowledge sources list with live progress, cancel, retry | D11 §7.1 | FE | 3 |
| Crawl preview screen with cost estimate | D11 §7.2, R-3 | FE+BE | 2 |

**Exit:** a customer's site indexes into `chunks` in the background with visible progress. **The SSE spike result is written up and the transport decision is confirmed or changed.**

---

## 6. Sprint 4 — Retrieval ⚑ M1 gate

**Goal:** prove the architecture's riskiest bet.

| Story | Req | Owner | Days |
|---|---|---|---|
| `EmbeddingService` with batching, retry, provider pinning | FR-KB-07, TD-6 | BE | 2 |
| Binary quantiser + popcount Hamming implementation | D6 §4 | BE | 2 |
| `MysqlBlobVectorStore` behind `VectorStoreInterface` | TD-1 | BE | 2.5 |
| Matrix cache: object cache with transient fallback + invalidation | D6 §12 | BE | 1.5 |
| Two-stage retrieval + exact cosine + RRF fusion with FULLTEXT | D6 §4 | BE | 2.5 |
| **Evaluation harness: 200-question set, recall@k, latency** | M1 | QA+BE | 2 |
| **Benchmarks at 1k / 10k / 50k chunks** | NFR-03, NFR-05 | BE | 1.5 |
| Retrieval playground UI with stage timings and threshold line | FR-KB-12, D11 §7.4 | FE | 2.5 |
| Knowledge source detail: documents and chunks browser | FR-KB-10 | FE | 2 |

**Exit — M1.** Recall@5 ≥ 0.90 · ≤ 300 ms p95 at 10k · ≤ 96 MB peak. **If missed, trigger the Deliverable 13 §5 ladder before Sprint 5 begins.**

---

## 7. Sprint 5 — Chat and widget · M2 gate

**Goal:** a real conversation on a real site.

| Story | Req | Owner | Days |
|---|---|---|---|
| `PromptBuilder` with untrusted-content delimiter isolation | D6 §9, D15 | BE | 2 |
| `ChatService`: history, retrieval, budget check, persistence | FR-WGT-02 | BE | 2.5 |
| `GuardrailService`: input/output validation, banned topics, confidence | FR-CLK-06 | BE | 2 |
| SSE streaming endpoint with probe frame and flush handling | TD-2 | BE | 2 |
| Polling fallback endpoints | TD-2 | BE | 1.5 |
| Session issue and validation, HMAC tokens, rate limiting | D9 §1.1 | BE | 1.5 |
| Widget: Preact app, shadow DOM, launcher, panel, composer | FR-WGT-01, 10 | FE | 3 |
| Widget: SSE + polling transports with automatic detection | FR-WGT-02 | FE | 2.5 |
| Widget: theming, citations, Markdown, a11y, i18n scaffolding | FR-WGT-03, 06, 09 | FE | 3 |
| Bundle-size enforcement ≤ 40 KB | NFR-01 | FE | 1 |

**Exit — M2.** Streamed grounded reply with citations on 4/5 hosts, ≤ 40 KB, ≤ 50 ms LCP, ≤ 1.5 s first token.

---

## 8. Sprint 6 — Clerks and conversations admin

**Goal:** operators can configure and supervise their staff.

| Story | Req | Owner | Days |
|---|---|---|---|
| `AgentService`, CRUD, publish/pause, duplicate, budget guard | FR-CLK-01, 03, 09 | BE | 2 |
| Role preset library with written instructions | FR-CLK-05 | BE+QA | 1.5 |
| Display rules evaluation (URL, device, role, geo) | FR-CLK-07 | BE | 2 |
| Test console endpoint with full diagnostics | FR-CLK-08 | BE | 1.5 |
| Conversation list, filters, transcript, citations, cost | FR-CNV-01, 02 | BE+FE | 3 |
| Human handoff: request, notify, flag | FR-WGT-07 | BE+FE | 2 |
| Admin takeover and reply as human | FR-CNV-03 | BE+FE | 2.5 |
| Tags, stars, internal notes | FR-CNV-04 | FE | 1.5 |
| Clerk editor: all six tabs + permanent test console | D11 §4.2 | FE | 4 |
| Retention policy + nightly purge job | FR-CNV-07 | BE | 1.5 |

**Exit:** a clerk can be created, configured, tested, published, paused, and supervised end to end.

---

## 9. Sprint 7 — Leads and scoring

**Goal:** the revenue mechanism.

| Story | Req | Owner | Days |
|---|---|---|---|
| Lead extraction from conversation, dedup by email hash | FR-LED-01, 08 | BE | 2.5 |
| Configurable qualification questions per clerk | FR-LED-02 | BE+FE | 2 |
| Rule engine: field, keyword, page-context, engagement rules | FR-LED-03 | BE | 2.5 |
| AI score adjustment with written rationale | FR-LED-04 | BE | 2 |
| Append-only score events + materialised total | D7 §5.2 | BE | 1 |
| Pipeline stages CRUD + drag-and-drop board | FR-LED-05 | FE | 3 |
| Lead detail: timeline, score breakdown, conversation link | FR-LED-06 | FE | 3 |
| Visitor identification and session stitching | FR-LED-07 | BE | 2 |
| Threshold notifications by email and Slack | FR-LED-09 | BE | 1.5 |
| Lead CSV export | FR-LED-10 | BE | 1 |

**Exit:** a visitor conversation produces a scored lead in a pipeline with a fully attributed breakdown.

---

## 10. Sprint 8 — CRM and email

**Goal:** leads leave the building.

| Story | Req | Owner | Days |
|---|---|---|---|
| `CrmConnectorInterface`, registry, field mapper, retry policy | FR-CRM-01, 08 | BE | 2 |
| FluentCRM connector (Q-4: first) | FR-CRM-02 | BE | 1.5 |
| Groundhogg connector | FR-CRM-03 | BE | 1.5 |
| HubSpot connector with OAuth 2.0 | FR-CRM-04 | BE | 2.5 |
| OAuth service, token refresh, expiry handling | FR-CRM-04 | BE | 1.5 |
| Outbound webhook + Slack connectors | FR-CRM-09 | BE | 1.5 |
| Integrations grid, connect flow, field mapping UI, sync log | D11 §8 | FE | 3.5 |
| Sequence engine: steps, delays, enrolment, exit conditions | FR-EML-01, 02, 04 | BE | 3 |
| Email send via `wp_mail`, suppression list, `List-Unsubscribe` | FR-EML-05, 06 | BE | 2 |
| AI email copy generation with human approval gate | FR-EML-03 | BE+FE | 2 |
| Sequence builder UI + email log | D11 | FE | 3 |

**Exit:** a qualified lead syncs to FluentCRM and HubSpot and enters a follow-up sequence.

---

## 11. Sprint 9 — Analytics, onboarding, licensing

**Goal:** prove value, reduce time-to-value, take money.

| Story | Req | Owner | Days |
|---|---|---|---|
| Daily rollup job + `analytics_daily` | D7 §8.2 | BE | 2 |
| Dashboard endpoint: KPIs, series, alerts | FR-ANL-01 | BE | 1.5 |
| Unanswered-questions detection and report | FR-ANL-03 | BE | 2 |
| Funnel, topics, per-clerk, cost reports | FR-ANL-02, 05 | BE | 2 |
| Dashboard UI: KPI cards, charts, needs-attention queue | D11 §3 | FE | 3 |
| Analytics UI with written findings under each chart | D11 §10 | FE | 3 |
| Knowledge gaps UI with inline answer composer | D11 §7.3 | FE | 2.5 |
| Onboarding wizard: 5 steps, auto-detect, resumable | FR-ONB-01, 04, 05 | FE+BE | 4 |
| `LicenceService`, activation, seat enforcement, degradation | FR-SYS-01 | BE | 2.5 |
| Licence UI + upgrade prompts on gated features | D11 | FE | 1.5 |
| White-label branding mode | FR-SYS-08 | BE+FE | 2 |

**Exit — M3.** Feature complete. Onboarding under 10 minutes in unmoderated testing with 5 participants.

---

## 12. Sprint 10 — Harden, secure, beta

**Goal:** M4. No new features.

| Story | Req | Owner | Days |
|---|---|---|---|
| **Security review execution + remediation** | D15 | BE+QA | 4 |
| Prompt-injection test suite and hardening | D15 | BE | 2 |
| GDPR exporter/eraser registration + verification | FR-SYS-04 | BE | 2 |
| System status page: cron, queue, provider, cache | FR-SYS-07 | BE+FE | 2 |
| Uninstall routine with opt-in data removal | FR-SYS-10 | BE | 1 |
| Performance pass against every NFR budget | NFR-01…05 | BE+FE | 2.5 |
| Accessibility audit and fixes, both themes | NFR-11 | FE+DS | 2 |
| i18n: POT generation, RTL verification | NFR-10 | FE+BE | 1.5 |
| E2E suite: onboarding → publish → converse → lead → sync | D15 | QA | 2.5 |
| Host-compatibility matrix verification | R-2 | QA | 2 |
| WordPress.org guideline pre-check and `readme.txt` | R-6 | BE | 1.5 |
| Beta with 20 design partners; triage and fix | M4 | All | 3 |

**Exit — M4.** All High/Critical security findings closed. 20 partner sites running 2 weeks with no P1 defects. WordPress.org submission accepted.

---

## 13. Capacity Summary

| Sprint | Planned days | Capacity | Load |
|---|---:|---:|:--:|
| 0 | 15.5 | 16 | ✅ |
| 1 | 22.0 | 16 | ⚠ **over** |
| 2 | 20.0 | 16 | ⚠ **over** |
| 3 | 21.0 | 16 | ⚠ **over** |
| 4 | 18.5 | 16 | ⚠ over |
| 5 | 21.0 | 16 | ⚠ **over** |
| 6 | 21.5 | 16 | ⚠ **over** |
| 7 | 20.5 | 16 | ⚠ **over** |
| 8 | 24.0 | 16 | 🔴 **heavily over** |
| 9 | 26.0 | 16 | 🔴 **heavily over** |
| 10 | 26.0 | 16 | 🔴 **heavily over** |

### This plan does not fit, and pretending otherwise would be the worst thing in this document

Total planned work is **~236 engineer-days** against **~176 days of capacity** — a **34% overrun**. Three honest options:

| Option | Effect | Recommendation |
|---|---|---|
| **A. Add a third engineer from Sprint 3** | Raises capacity to ~240 days. Onboarding cost ~5 days. | ✅ **Preferred** — hits the 5-month target with the scope intact |
| **B. Extend to 13 sprints (6.5 months)** | Same team, ~208 days, still needs ~1.5 tiers of descope | Acceptable if hiring is not possible |
| **C. Hold 10 sprints and descope** | Requires cutting ladder items 1–5 from Deliverable 13 §6 | Ships on time, materially weaker product |

The V1 scope of 88 requirements was set in the PRD before capacity was known. This is where that meets arithmetic. **My recommendation is Option A**, with Option B as the fallback and Option C only if both are unavailable.

Sprints 8–10 are the worst offenders and are also the least compressible: Sprint 10 is security, accessibility, and beta, none of which should be cut.

---

## 14. Tracking

| Metric | Cadence | Action threshold |
|---|---|---|
| Velocity (days completed) | Per sprint | Below 14 by Sprint 4 → invoke descope ladder |
| Escaped defects | Per sprint | More than 3 P2+ → add a hardening sprint |
| CI duration | Weekly | Above 10 min → parallelise |
| Retrieval recall/latency | Per sprint from S4 | Regression → block release |
| Bundle sizes | Every build | Over budget → CI fails |

---

**Approval:** ⬜ Awaiting sign-off · Reviewer: ______________ · Date: __________
