# Hiveclerk — Product Requirements Document

**Deliverable 1 of 16**

| Field | Value |
|---|---|
| Product | Hiveclerk |
| Tagline | A hive of AI clerks for your website |
| Category | WordPress AI Employee Platform |
| Vendor | Decent Themes |
| Document version | 1.0 |
| Status | **Draft — awaiting approval** |
| Date | 2026-08-05 |
| Supersedes | Working name "AgentHive AI" (rejected, brand collision) |

---

## 1. Executive Summary

Hiveclerk turns a WordPress site into a staffed storefront. Instead of installing a chatbot, the site owner **hires AI clerks** — named, configured, measurable workers with a job description, a knowledge base, a personality, and KPIs.

The V1 product is a revenue-generating chat + lead-qualification + knowledge-retrieval system. V2 adds a visual workflow builder and a WooCommerce sales clerk. V3 opens a marketplace of pre-built clerks that collaborate on multi-step goals.

**The strategic wedge:** every serious competitor (Chatbase, Tidio, Intercom, Gorgias) is a SaaS that charges per-seat or per-resolution and holds the customer's data off-site. Hiveclerk runs *inside* WordPress, on the customer's own hosting, with their own model API key, at a flat annual license. That is a structurally cheaper offer for the long tail of WordPress sites — and it is the one thing a SaaS competitor cannot copy without abandoning its revenue model.

---

## 2. Problem Statement

### 2.1 The customer's problem

A WordPress site owner loses money in three specific ways:

| Loss | Mechanism | Evidence to gather in beta |
|---|---|---|
| **Unanswered visitors** | Visitor has a pre-purchase question outside business hours, gets no answer, leaves | Session recordings, exit-intent rate |
| **Unqualified leads** | Contact form dumps every enquiry into one inbox; sales team wastes time on tyre-kickers | Lead-to-opportunity conversion |
| **Repetitive support** | 60–80% of support tickets are answerable from existing docs | Ticket tag distribution |

### 2.2 Why existing solutions fail this customer

- **SaaS AI chat (Chatbase, Botsonic, CustomGPT)** — cheap to start, but pricing is per-message or per-agent and scales against the customer. Integration with WordPress/WooCommerce is a JS snippet with no access to order data, user roles, or post meta.
- **Enterprise CX suites (Intercom, Zendesk, Gorgias, HubSpot)** — powerful, but priced for funded companies. A $60/mo WordPress site cannot justify $500+/mo.
- **Existing WordPress AI plugins** — mostly thin OpenAI wrappers. They generate content or answer from a single FAQ blob. None ship lead scoring, retrieval-augmented knowledge, CRM sync, and human handoff as one coherent product.

### 2.3 The gap

There is no **commercial-grade, self-hosted, WordPress-native AI customer-facing agent platform**. That is the position Hiveclerk takes.

---

## 3. Product Vision

> **Three-year vision.** Hiveclerk is where a WordPress business staffs its website. Owners hire clerks the way they hire people — pick a role, write a job description, give them the company handbook, set targets, review performance. Clerks talk to visitors, qualify leads, close sales, resolve tickets, and escalate to humans when they should. By V3 they work as a team.

**Positioning statement**

> For **WordPress business owners and the agencies that serve them**, who **lose revenue to unanswered visitors and unqualified leads**, Hiveclerk is an **AI employee platform** that **staffs the website with configurable AI clerks**. Unlike **SaaS chatbots that charge per conversation and hold your data off-site**, Hiveclerk **runs on your own hosting, uses your own model key, and integrates natively with WooCommerce and your CRM at a flat annual price**.

---

## 4. Goals and Non-Goals

### 4.1 Business goals

| ID | Goal | Target | Horizon |
|---|---|---|---|
| BG-1 | Ship a revenue-generating V1 | Public launch on WordPress.org + Pro tier live | Month 5 |
| BG-2 | Prove willingness to pay | 150 paying Pro licenses | Month 9 |
| BG-3 | Establish agency channel | 25 agency licenses | Month 12 |
| BG-4 | Validate SaaS extension | 200 managed-key subscribers | Month 15 |
| BG-5 | Sustain a free funnel | 10,000 active installs of the free tier | Month 12 |

### 4.2 Product goals

| ID | Goal | Measured by |
|---|---|---|
| PG-1 | A non-technical owner deploys a working clerk without reading docs | Time-to-first-conversation < 10 min |
| PG-2 | Answers are grounded in the site's real content, not hallucinated | Groundedness rate > 90% on eval set |
| PG-3 | The product proves its own ROI inside the dashboard | Leads captured, revenue influenced, deflection rate |
| PG-4 | The admin feels like Linear/Stripe, not like wp-admin | Qualitative review + SUS score > 80 |

### 4.3 Non-goals for V1

Stated explicitly so scope does not creep:

- Not a helpdesk/ticketing system (no SLAs, no ticket queues, no agent seats)
- Not a live-chat product with staffed human agents (handoff yes; full agent console no)
- Not a content-generation plugin (no blog writer, no SEO writer)
- Not a voice or phone agent
- Not a page builder or form builder
- No visual workflow builder (V2)
- No multi-agent collaboration (V3)
- No self-hosted model inference (always an external model API in V1)

---

## 5. Target Market and Users

Full detail in **Deliverable 5 — User Personas**. Summary:

| Segment | Share of V1 revenue (planned) | Primary job to be done |
|---|---|---|
| Agencies / freelancers | 40% | Resell a differentiated service to retainer clients |
| eCommerce (WooCommerce) | 25% | Recover abandoned carts, answer product questions |
| SaaS / service businesses | 20% | Qualify inbound leads before sales touches them |
| Course creators / local business | 10% | Answer FAQs, book consultations |
| Enterprise WordPress | 5% | Data-residency-compliant AI on owned infrastructure |

---

## 6. Success Metrics

### 6.1 North Star

> **Weekly Qualified Conversations** — conversations where the clerk either captured a qualified lead, resolved a support question without human escalation, or influenced a WooCommerce order.

This is deliberately an *outcome* metric, not a volume metric. It rises only when the product works.

### 6.2 Supporting metrics

| Stage | Metric | V1 target |
|---|---|---|
| Activation | Install → first clerk published | > 55% |
| Activation | Time-to-first-conversation | < 10 min median |
| Engagement | Sites with ≥ 1 conversation in last 7 days | > 60% of active installs |
| Quality | Groundedness (answer supported by retrieved chunk) | > 90% |
| Quality | Human-handoff rate | < 15% |
| Quality | Visitor thumbs-up rate | > 75% |
| Business | Free → Pro conversion | > 3.5% |
| Business | Pro annual renewal | > 70% |
| Cost | Median model spend per conversation | < $0.02 |

### 6.3 Guardrail metrics

Metrics that must **not** regress while chasing the above:

- Front-end LCP impact of the widget: **< 50 ms**, widget JS **< 40 KB gzipped**
- Admin p95 API latency: **< 400 ms** (excluding model streaming)
- Zero P1 security incidents
- Support tickets per 100 active sites: **< 5/month**

---

## 7. Scope and Release Plan

| Release | Theme | Modules | Target |
|---|---|---|---|
| **V1.0** | Get paid | Chat, Lead Qualification, Knowledge Base, CRM Integrations, Email Automation | Month 5 |
| **V1.x** | Harden | Multilingual polish, more CRM connectors, performance | Months 6–8 |
| **V2.0** | Automate | Visual Workflow Builder, WooCommerce Sales Clerk, Multi-Agent orchestration | Months 9–14 |
| **V3.0** | Ecosystem | Clerk Marketplace, Team Collaboration, SaaS dashboard | Months 15–24 |

---

## 8. Functional Requirements — V1

Requirement IDs are stable and referenced by the Sprint Plan (Deliverable 14) and Testing Strategy (Deliverable 15).

Priority: **P0** = launch blocker · **P1** = launch-desirable · **P2** = fast-follow.

### 8.1 Onboarding and Setup (`FR-ONB`)

| ID | Requirement | Priority |
|---|---|---|
| FR-ONB-01 | A guided 5-step wizard runs on first activation: connect model → choose clerk role → ingest knowledge → style widget → publish | P0 |
| FR-ONB-02 | Model provider connection supports Anthropic, OpenAI, Google, OpenRouter, and Azure OpenAI via BYO API key | P0 |
| FR-ONB-03 | API key is validated with a live test call before the step can be completed | P0 |
| FR-ONB-04 | Wizard auto-suggests knowledge sources by detecting sitemap.xml, WooCommerce products, and published pages | P1 |
| FR-ONB-05 | Wizard is skippable and resumable; progress persists | P1 |
| FR-ONB-06 | A demo clerk seeded with the site's own content is available before any configuration | P2 |

### 8.2 Clerk (Agent) Management (`FR-CLK`)

| ID | Requirement | Priority |
|---|---|---|
| FR-CLK-01 | CRUD for clerks; each has name, avatar, role, status (draft/published/paused) | P0 |
| FR-CLK-02 | Each clerk has a **job description**: system instructions, tone, greeting, fallback message | P0 |
| FR-CLK-03 | Each clerk binds to a model, temperature, max tokens, and a token budget cap | P0 |
| FR-CLK-04 | Each clerk is assigned zero or more knowledge sources; retrieval is scoped to them | P0 |
| FR-CLK-05 | Role presets ship with pre-written instructions: Support, Sales, Lead Qualifier, FAQ, Concierge | P0 |
| FR-CLK-06 | **Guardrails**: banned topics, required disclaimers, max reply length, "never invent prices/stock" toggle | P0 |
| FR-CLK-07 | Display rules control where the clerk appears: page/post/URL pattern, device, logged-in state, geography | P1 |
| FR-CLK-08 | A test console runs the clerk in the admin without touching the live site, showing retrieved sources and token cost | P0 |
| FR-CLK-09 | Free tier is capped at 1 published clerk; Pro is unlimited | P0 |
| FR-CLK-10 | Clerk configuration is exportable/importable as JSON (foundation for V3 Marketplace) | P2 |

### 8.3 Knowledge Base (`FR-KB`)

| ID | Requirement | Priority |
|---|---|---|
| FR-KB-01 | Ingest WordPress content: posts, pages, custom post types, WooCommerce products, with taxonomy filters | P0 |
| FR-KB-02 | Crawl an external website by sitemap or recursive link-following, respecting robots.txt, with a page cap | P0 |
| FR-KB-03 | Import PDF and DOCX files | P0 |
| FR-KB-04 | Import FAQs via a Q/A editor and via CSV | P0 |
| FR-KB-05 | Paste raw text or Markdown as a source | P1 |
| FR-KB-06 | Content is chunked with configurable size and overlap; chunks retain a source URL and heading path | P0 |
| FR-KB-07 | Chunks are embedded via the configured provider and stored for vector retrieval | P0 |
| FR-KB-08 | All ingestion runs as background jobs via Action Scheduler with visible progress, cancel, and retry | P0 |
| FR-KB-09 | Sources re-sync on a schedule (manual, daily, weekly) and on `save_post` for WP content | P1 |
| FR-KB-10 | Per-source status is visible: chunk count, token count, last sync, error detail | P0 |
| FR-KB-11 | Free tier caps total chunks (e.g. 200); Pro raises the cap substantially | P0 |
| FR-KB-12 | A retrieval playground lets the admin type a query and inspect ranked chunks with scores | P1 |

### 8.4 Public Chat Widget (`FR-WGT`)

| ID | Requirement | Priority |
|---|---|---|
| FR-WGT-01 | Widget loads asynchronously and never blocks page render | P0 |
| FR-WGT-02 | Responses stream token-by-token, with automatic fallback to polling when the host buffers output | P0 |
| FR-WGT-03 | Full theming: colours, position, launcher icon, border radius, light/dark, custom CSS | P0 |
| FR-WGT-04 | Conversation persists across page navigations and sessions via a first-party cookie + server session | P0 |
| FR-WGT-05 | Detects visitor language and replies in it; UI strings are translatable | P1 |
| FR-WGT-06 | Renders Markdown, links, and source citations; optional product cards | P1 |
| FR-WGT-07 | Visitor can request a human; conversation is flagged and staff notified by email | P0 |
| FR-WGT-08 | Per-message thumbs up/down feedback, stored for evaluation | P1 |
| FR-WGT-09 | Accessible: keyboard navigable, ARIA live regions, focus trap, respects `prefers-reduced-motion` | P0 |
| FR-WGT-10 | Shadow DOM isolation so themes cannot break widget styles | P0 |
| FR-WGT-11 | Widget bundle ≤ 40 KB gzipped | P0 |
| FR-WGT-12 | Free tier shows a "Powered by Hiveclerk" badge; Pro can remove it | P0 |

### 8.5 Conversations (`FR-CNV`)

| ID | Requirement | Priority |
|---|---|---|
| FR-CNV-01 | List all conversations with filters: clerk, status, date, sentiment, lead-captured, handoff | P0 |
| FR-CNV-02 | Full transcript view with retrieved sources, token cost, and latency per message | P0 |
| FR-CNV-03 | Staff can take over a conversation and reply as a human | P1 |
| FR-CNV-04 | Tag, star, and add internal notes to a conversation | P1 |
| FR-CNV-05 | Automatic per-conversation summary and sentiment classification | P1 |
| FR-CNV-06 | Export conversations as CSV/JSON | P1 |
| FR-CNV-07 | Configurable retention policy with automatic purge | P0 |

### 8.6 Leads and Qualification (`FR-LED`)

| ID | Requirement | Priority |
|---|---|---|
| FR-LED-01 | Clerk captures name, email, phone, company and arbitrary custom fields conversationally | P0 |
| FR-LED-02 | Qualification questions are configurable per clerk (e.g. budget, timeline, team size) | P0 |
| FR-LED-03 | Rule-based scoring: field values, keywords, page context, engagement depth each contribute points | P0 |
| FR-LED-04 | AI-assisted score adjustment with a written rationale | P1 |
| FR-LED-05 | Leads land in a pipeline with configurable stages and drag-and-drop | P0 |
| FR-LED-06 | Lead detail shows full conversation, score breakdown, visit history, and activity timeline | P0 |
| FR-LED-07 | Visitor identification stitches anonymous sessions to a lead once email is known | P1 |
| FR-LED-08 | Deduplicate by email; merge conversations onto one lead | P0 |
| FR-LED-09 | Notify staff by email/Slack when a lead crosses a score threshold | P1 |
| FR-LED-10 | Export leads as CSV | P0 |

### 8.7 Email Automation (`FR-EML`)

| ID | Requirement | Priority |
|---|---|---|
| FR-EML-01 | Build multi-step follow-up sequences with delays | P0 |
| FR-EML-02 | Enrolment triggers: lead created, score threshold, stage change, conversation abandoned | P0 |
| FR-EML-03 | AI drafts email copy from the conversation context; human approves before send | P1 |
| FR-EML-04 | Exit conditions: reply received, stage reached, manual unenrol | P0 |
| FR-EML-05 | Sends via `wp_mail` with SMTP-plugin compatibility; per-site rate limit | P0 |
| FR-EML-06 | One-click unsubscribe honouring `List-Unsubscribe`; suppression list enforced | P0 |
| FR-EML-07 | Per-sequence open/click/reply metrics | P1 |
| FR-EML-08 | Email automation is Pro-only | P0 |

### 8.8 CRM Integrations (`FR-CRM`)

| ID | Requirement | Priority |
|---|---|---|
| FR-CRM-01 | A connector interface any integration implements: authenticate, test, push contact, push activity, map fields | P0 |
| FR-CRM-02 | FluentCRM connector (local, no OAuth — ship first) | P0 |
| FR-CRM-03 | Groundhogg connector (local) | P0 |
| FR-CRM-04 | HubSpot connector (OAuth 2.0) | P0 |
| FR-CRM-05 | Zoho CRM connector (OAuth 2.0) | P1 |
| FR-CRM-06 | Salesforce connector (OAuth 2.0) | P1 |
| FR-CRM-07 | Configurable field mapping per connector, including custom fields | P0 |
| FR-CRM-08 | Failed pushes retry with exponential backoff and surface in a sync log | P0 |
| FR-CRM-09 | Generic outbound webhook as a universal fallback | P1 |
| FR-CRM-10 | CRM integrations are Pro-only | P0 |

### 8.9 Analytics (`FR-ANL`)

| ID | Requirement | Priority |
|---|---|---|
| FR-ANL-01 | Dashboard: conversations, leads, deflection rate, top questions, model spend — all trended | P0 |
| FR-ANL-02 | Per-clerk performance comparison | P1 |
| FR-ANL-03 | **Unanswered questions report** — queries with no confident retrieval, as a knowledge-gap worklist | P0 |
| FR-ANL-04 | Token and cost tracking per clerk, per conversation, per provider | P0 |
| FR-ANL-05 | Lead funnel: conversation → engaged → captured → qualified → won | P1 |
| FR-ANL-06 | Date-range comparison against previous period | P1 |
| FR-ANL-07 | Export any report as CSV | P2 |

### 8.10 Settings, Licensing, Platform (`FR-SYS`)

| ID | Requirement | Priority |
|---|---|---|
| FR-SYS-01 | License key activation/deactivation with seat-limit enforcement and graceful degradation on expiry | P0 |
| FR-SYS-02 | Role/capability mapping so a shop manager sees conversations but not API keys | P0 |
| FR-SYS-03 | API keys encrypted at rest; never returned to the browser after save | P0 |
| FR-SYS-04 | GDPR: data export and erasure hooks, consent banner option, IP anonymisation, configurable retention | P0 |
| FR-SYS-05 | Audit log of admin actions (config changes, key changes, deletions, exports) | P0 |
| FR-SYS-06 | Rate limiting on all public endpoints, per IP and per session | P0 |
| FR-SYS-07 | System status page: PHP/MySQL versions, cron health, queue depth, provider reachability | P1 |
| FR-SYS-08 | White-label mode replaces product name, logo, and colours throughout admin (Agency tier) | P1 |
| FR-SYS-09 | Multisite compatible; per-site licensing | P2 |
| FR-SYS-10 | Full uninstall removes tables and options when the user opts in | P0 |

---

## 9. Non-Functional Requirements

| ID | Category | Requirement |
|---|---|---|
| NFR-01 | Performance | Widget adds < 50 ms to LCP; JS ≤ 40 KB gzipped; loads only where the clerk is enabled |
| NFR-02 | Performance | First streamed token within 1.5 s p95 |
| NFR-03 | Performance | Retrieval over 10,000 chunks completes in < 300 ms p95 |
| NFR-04 | Performance | Admin API p95 < 400 ms; SPA first paint < 1 s |
| NFR-05 | Scalability | 50,000 conversations and 100,000 chunks on standard shared hosting without degradation |
| NFR-06 | Compatibility | WordPress 6.6+, PHP 8.3–8.4, MySQL 8.0+ / MariaDB 10.6+ |
| NFR-07 | Compatibility | No jQuery dependency; no conflict with common cache/optimisation plugins |
| NFR-08 | Security | All WordPress security standards — see Deliverable 15 |
| NFR-09 | Privacy | No visitor data leaves the site except to the configured model provider |
| NFR-10 | i18n | 100% translatable; RTL support; widget replies in visitor's language |
| NFR-11 | Accessibility | WCAG 2.1 AA for both widget and admin |
| NFR-12 | Reliability | Provider outage degrades gracefully to a fallback message; queue survives restarts |
| NFR-13 | Maintainability | PSR-12, PHPStan level 8, ≥ 70% unit coverage on services |
| NFR-14 | Portability | No hard dependency on any single model provider or on WordPress-only APIs in the service layer |

---

## 10. Key Technical Decisions to Resolve

These are flagged here and **resolved in Deliverable 6 (System Architecture)**. They are the decisions most likely to be expensive to reverse.

| # | Decision | Options | Leaning |
|---|---|---|---|
| TD-1 | **Vector storage and search** | (a) BLOB embeddings + PHP cosine, (b) MySQL 9 `VECTOR` type, (c) bundled SQLite + sqlite-vec, (d) external vector DB | **(a) with a tiered index** — MySQL 9 is not available on typical WP hosting; keep an adapter so (d) is a drop-in for SaaS |
| TD-2 | **Streaming transport** | SSE, chunked fetch, WebSocket, polling | **SSE with polling fallback** — WebSockets are unavailable on most shared hosts |
| TD-3 | **Background processing** | Action Scheduler, WP-Cron, custom loopback | **Action Scheduler** — battle-tested, already in the stack |
| TD-4 | **Admin SPA routing** | Hash router vs. browser router under `admin.php?page=` | **Hash router** — avoids rewrite rules and server config entirely |
| TD-5 | **Model key custody** | BYO key only, managed key only, both | **Both** — BYO for Pro, managed for the SaaS tier; the abstraction must support each from day one |
| TD-6 | **Embedding provider lock-in** | Single provider vs. pluggable | **Pluggable**, with dimension recorded per source so a provider switch triggers targeted re-embedding |

---

## 11. Compliance and Privacy

| Area | Requirement |
|---|---|
| GDPR | Register data exporter and eraser with WordPress's privacy tools; document all PII in the privacy policy content hook |
| Consent | Optional pre-chat consent gate; configurable per region |
| Data residency | All conversation data stays in the site's own database; only message content transits to the model provider |
| Sub-processors | Provider list surfaced in admin so the customer can complete their own DPA |
| Retention | Configurable conversation retention (default 12 months) with scheduled purge |
| Right to erasure | Erasing a lead removes conversations, messages, and derived embeddings of their data |
| Audit | All admin config changes logged with user, timestamp, and diff |
| CCPA | "Do not sell" is trivially satisfied — no data brokerage — but must be documented |

---

## 12. Assumptions

1. Customers can obtain and pay for their own model API key (validated in beta interviews).
2. Typical shared hosting provides PHP 8.3+, 128 MB memory, and 30 s max execution — all ingestion must chunk within that envelope.
3. `wp-cron` fires reliably enough for Action Scheduler on most sites; the status page will surface when it does not.
4. WooCommerce is present on roughly a third of target sites but is **not** a V1 dependency.
5. English-first launch; the top five additional locales follow in V1.x.

## 13. Risks and Mitigations

| # | Risk | Impact | Likelihood | Mitigation |
|---|---|---|---|---|
| R-1 | Vector search too slow in PHP at scale | High | Medium | Tiered retrieval: SQL pre-filter → candidate set → cosine on a bounded subset; hard cap free tier; benchmark early (Sprint 3 spike) |
| R-2 | Streaming blocked by host buffering | High | High | Detect and fall back to polling automatically; test on the five largest shared hosts |
| R-3 | Model costs surprise the customer | High | Medium | Hard per-clerk token budgets, spend dashboard, cost estimate shown before publish |
| R-4 | Hallucinated answers damage trust | High | Medium | Strict groundedness prompt, citation requirement, "never invent prices/stock" guardrail, confidence threshold → handoff |
| R-5 | Prompt injection via crawled content or visitor input | High | Medium | Treat all retrieved content as untrusted data, not instruction; delimiter isolation; output filtering; security review in Deliverable 15 |
| R-6 | WordPress.org rejects the plugin | High | Low | Review guidelines before submission; no obfuscation; free tier fully functional; no external calls without consent |
| R-7 | A funded competitor ships a WordPress-native product | Medium | Medium | Move fast on the agency channel; depth of WooCommerce/CRM integration is the moat |
| R-8 | Scope creep from V2/V3 features into V1 | High | High | This document's non-goals are binding; the workflow builder is explicitly out |

## 14. Open Questions

| # | Question | Owner | Needed by |
|---|---|---|---|
| Q-1 | Does the free tier ship with a Decent Themes managed key (with a low quota) to remove the API-key barrier? | Product | Before Deliverable 16 |
| Q-2 | Annual-only pricing, or lifetime as a launch promotion? | Business | Before Deliverable 16 |
| Q-3 | Is WooCommerce read-only product context in V1, or fully deferred to V2? | Product | Before Deliverable 13 |
| Q-4 | Which single CRM ships first — FluentCRM (easiest) or HubSpot (largest market)? | Product | Before Deliverable 14 |
| Q-5 | Do we self-host license/update infrastructure or use an existing platform? | Engineering | Before Sprint 1 |

---

## 15. Approval

| Phase gate | Status |
|---|---|
| Deliverable 1 — PRD | ⬜ Awaiting sign-off |

Deliverables 2–5 accompany this document. Deliverable 6 (System Architecture) does not begin until this gate is signed off.

**Reviewer:** ______________________  **Date:** ____________
