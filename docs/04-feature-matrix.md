# Hiveclerk — Feature Matrix

**Deliverable 4 of 16** · Version 1.0 · Status: **Draft — awaiting approval** · 2026-08-05

Three views: **A.** feature by licence tier · **B.** feature by release · **C.** competitive scoring.

Legend: **●** full · **◐** limited · **○** not included · **→** planned in that release

---

## A. Feature by Licence Tier

### A.1 Clerks (Agents)

| Feature | Free | Pro | Business | Agency | Managed |
|---|:--:|:--:|:--:|:--:|:--:|
| Published clerks | 1 | Unlimited | Unlimited | Unlimited | Unlimited |
| Role presets (Support, Sales, Qualifier, FAQ, Concierge) | ● | ● | ● | ● | ● |
| Custom job description / system instructions | ◐ 500 chars | ● | ● | ● | ● |
| Personality and tone controls | ◐ 3 presets | ● | ● | ● | ● |
| Guardrails (banned topics, disclaimers, no-invent) | ◐ basic | ● | ● | ● | ● |
| Display rules (URL, device, role, geo) | ○ | ● | ● | ● | ● |
| Test console with source inspection | ● | ● | ● | ● | ● |
| Per-clerk token budget caps | ○ | ● | ● | ● | ● |
| Clerk export / import (JSON) | ○ | ● | ● | ● | ● |

### A.2 Knowledge Base

| Feature | Free | Pro | Business | Agency | Managed |
|---|:--:|:--:|:--:|:--:|:--:|
| Total indexed chunks | 200 | 10,000 | 50,000 | Unlimited | 25,000 |
| WordPress posts / pages / CPTs | ● | ● | ● | ● | ● |
| WooCommerce products | ○ | ● | ● | ● | ● |
| Website crawler (sitemap + recursive) | ◐ 25 pages | ● 1,000 | ● 5,000 | ● | ● |
| PDF import | ○ | ● | ● | ● | ● |
| DOCX import | ○ | ● | ● | ● | ● |
| FAQ editor + CSV import | ● | ● | ● | ● | ● |
| Raw text / Markdown source | ● | ● | ● | ● | ● |
| Scheduled re-sync | ○ | ● | ● | ● | ● |
| Auto re-index on `save_post` | ○ | ● | ● | ● | ● |
| Retrieval playground | ○ | ● | ● | ● | ● |
| Unanswered-questions report | ◐ 7 days | ● | ● | ● | ● |

### A.3 Chat Widget

| Feature | Free | Pro | Business | Agency | Managed |
|---|:--:|:--:|:--:|:--:|:--:|
| Streaming responses | ● | ● | ● | ● | ● |
| Theming (colour, position, radius, icon) | ◐ colour only | ● | ● | ● | ● |
| Custom CSS | ○ | ● | ● | ● | ● |
| Light / dark mode | ● | ● | ● | ● | ● |
| Multi-language replies | ● | ● | ● | ● | ● |
| Source citations | ● | ● | ● | ● | ● |
| Message feedback (thumbs) | ● | ● | ● | ● | ● |
| Human handoff request | ◐ email only | ● | ● | ● | ● |
| Remove "Powered by Hiveclerk" | ○ | ● | ● | ● | ● |
| Fully white-labelled widget | ○ | ○ | ○ | ● | ○ |

### A.4 Conversations and Leads

| Feature | Free | Pro | Business | Agency | Managed |
|---|:--:|:--:|:--:|:--:|:--:|
| Conversation history retention | 30 days | 12 months | 24 months | Configurable | 12 months |
| Full transcript with sources and cost | ● | ● | ● | ● | ● |
| Tags, stars, internal notes | ○ | ● | ● | ● | ● |
| Human takeover in admin | ○ | ● | ● | ● | ● |
| AI summary and sentiment | ○ | ● | ● | ● | ● |
| Lead capture (name, email, phone, custom) | ● | ● | ● | ● | ● |
| Configurable qualification questions | ◐ 3 | ● | ● | ● | ● |
| Rule-based lead scoring | ○ | ● | ● | ● | ● |
| AI score adjustment with rationale | ○ | ● | ● | ● | ● |
| Pipeline with custom stages | ○ | ● | ● | ● | ● |
| Visitor identification and stitching | ○ | ● | ● | ● | ● |
| CSV export | ● | ● | ● | ● | ● |

### A.5 Automation and Integrations

| Feature | Free | Pro | Business | Agency | Managed |
|---|:--:|:--:|:--:|:--:|:--:|
| Email follow-up sequences | ○ | ● | ● | ● | ● |
| AI-drafted email copy | ○ | ● | ● | ● | ● |
| FluentCRM / Groundhogg | ○ | ● | ● | ● | ● |
| HubSpot | ○ | ● | ● | ● | ● |
| Zoho / Salesforce | ○ | ○ | ● | ● | ● |
| Outbound webhooks | ○ | ● | ● | ● | ● |
| Slack notifications | ○ | ● | ● | ● | ● |
| Visual workflow builder | ○ | ● | ● | ● | ● |

### A.6 Platform and Licensing

| Feature | Free | Pro | Business | Agency | Managed |
|---|:--:|:--:|:--:|:--:|:--:|
| Sites per licence | 1 | 1 | 5 | 25 | 1 |
| Model providers (Anthropic/OpenAI/Google/Azure/OpenRouter) | ● BYO | ● BYO | ● BYO | ● BYO | Included |
| Inference cost | Customer pays provider | Customer pays provider | Customer pays provider | Customer pays provider | **Included in subscription** |
| Analytics dashboard | ◐ 7 days | ● | ● | ● | ● |
| Cost and token tracking | ◐ | ● | ● | ● | ● |
| Role / capability mapping | ○ | ● | ● | ● | ● |
| Audit log | ○ | ● | ● | ● | ● |
| GDPR export / erasure tools | ● | ● | ● | ● | ● |
| White-label admin (name, logo, colours) | ○ | ○ | ○ | ● | ○ |
| Agency multi-site dashboard | ○ | ○ | ○ | ● | ○ |
| Multisite support | ○ | ○ | ● | ● | ○ |
| Priority support | ○ | Email | Email | Priority + Slack | Email |
| **Indicative price** | **$0** | **$199/yr** | **$399/yr** | **$899/yr** | **$39/mo** |

### Gating principle

The free tier is limited by **scale**, never by **quality**. A free user gets real vector retrieval, real streaming, real citations, and real lead capture — just less of it. Crippling quality in the free tier would produce one-star WordPress.org reviews and destroy the acquisition funnel that the entire go-to-market depends on.

---

## B. Feature by Release

| Capability | V1.0 | V1.x | V2.0 | V3.0 |
|---|:--:|:--:|:--:|:--:|
| **Chat** | | | | |
| Website chat widget, streaming | ● | | | |
| Multi-language | ● | | | |
| Human handoff (email + admin takeover) | ● | | | |
| Conversation analytics | ● | | | |
| Tidio / Chatbase migration importer | | ● | | |
| Proactive triggers (exit intent, time on page) | | ● | | |
| **Knowledge** | | | | |
| WP content, crawler, PDF, DOCX, FAQ | ● | | | |
| Vector indexing + retrieval + citations | ● | | | |
| Retrieval playground | ● | | | |
| Hybrid search (BM25 + vector) | | ● | | |
| Auto knowledge-gap suggestions | | | ● | |
| **Leads** | | | | |
| Capture, scoring, pipeline | ● | | | |
| Visitor identification | ● | | | |
| Predictive lead scoring from outcomes | | | ● | |
| **Automation** | | | | |
| Email sequences | ● | | | |
| Visual workflow builder (triggers/conditions/actions/delays/branching) | ● *(brought forward)* | | | |
| Scheduled and recurring workflows | ● *(brought forward)* | | | |
| **Commerce** | | | | |
| WooCommerce product indexing | ● | | | |
| Product recommendations | | | ● | |
| Cart recovery | | | ● | |
| Upsell / cross-sell | | | ● | |
| Checkout assistant | | | ● | |
| Order status and support | | | ● | |
| **Agents** | | | | |
| Single-clerk conversations | ● | | | |
| Clerk-to-clerk handoff | | | ● | |
| Orchestrator / router clerk | | | ● | |
| Shared memory across clerks | | | ● | |
| Multi-clerk team tasks with merged output | | | | ● |
| **Ecosystem** | | | | |
| Clerk export / import | ● | | | |
| Clerk Marketplace (install pre-built employees) | | | | ● |
| Personality / goals / KPIs / memory per employee | | | | ● |
| Third-party developer SDK and hooks | | ● | | |
| **Platform** | | | | |
| CRM connectors (FluentCRM, Groundhogg, HubSpot) | ● | | | |
| Zoho, Salesforce | | ● | | |
| White-label + agency dashboard | ● | | | |
| Multisite | | ● | | |
| Managed-key SaaS tier | | ● | | |
| SaaS dashboard, team accounts, usage billing | | | | ● |

---

## C. Competitive Feature Scoring

Scored 0–5 on how well each product serves **a WordPress business buyer**. This is a scorecard for *our* buyer, not a neutral product review — an enterprise buyer would score these very differently.

| Dimension | Weight | Chatbase | Tidio | Intercom | Gorgias | CustomGPT | **Hiveclerk V1** |
|---|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| Retrieval quality | 15% | 4 | 3 | 5 | 4 | **5** | 4 |
| WordPress / Woo integration | 15% | 1 | 2 | 1 | 2 | 1 | **5** |
| Total cost of ownership | 15% | 2 | 2 | 1 | 1 | 2 | **5** |
| Data ownership / privacy | 10% | 2 | 2 | 2 | 2 | 2 | **5** |
| Lead qualification depth | 10% | 2 | 3 | 4 | 3 | 1 | **5** |
| Admin UX quality | 10% | **5** | 4 | **5** | 4 | 3 | 4 |
| Setup speed | 10% | **5** | **5** | 3 | 3 | 4 | 4 |
| Agency / white-label fit | 5% | 2 | 1 | 1 | 1 | 2 | **5** |
| Omnichannel breadth | 5% | 2 | 4 | **5** | **5** | 1 | 1 |
| Enterprise compliance | 5% | 3 | 3 | **5** | 4 | 3 | 1 |
| **Weighted total** | 100% | **2.85** | **2.90** | **3.05** | **2.75** | **2.70** | **4.25** |

### Honest reading of this table

Hiveclerk scores highest **because the weights reflect our buyer**. Re-weight for an enterprise buyer — omnichannel and compliance at 20% each — and Intercom wins decisively. That is the correct result, and it defines the boundary of the market we should pursue.

Two dimensions where we score below the leaders demand attention:

- **Retrieval quality (4 vs CustomGPT's 5)** — must close this gap. It is the dimension a customer evaluates within five minutes, and losing it undermines everything else. Deliverable 6 must treat chunking, embedding, and reranking as a first-class problem rather than plumbing.
- **Setup speed (4 vs Chatbase/Tidio's 5)** — the BYO-key requirement is the whole gap. The onboarding wizard must reduce it to a single paste, and the Managed tier exists for buyers who will not do even that.

---

## D. Explicit Non-Features

Stated so scope stays honest and sales stays truthful:

| Not building | Rationale |
|---|---|
| Full omnichannel inbox (SMS, WhatsApp, social) | Different product; commoditised by dedicated tools |
| Native mobile apps | Cost is not justified before V3 |
| Voice / phone agents | Different technical stack entirely |
| Content-generation / SEO writing | Crowded WordPress category; dilutes positioning |
| Self-hosted model inference | Impossible on shared hosting |
| Page or form builder | Not our category |
| Replacing a helpdesk | We are the AI layer beneath one |

---

**Approval:** ⬜ Awaiting sign-off · Reviewer: ______________ · Date: __________
