# Hiveclerk — User Personas

**Deliverable 5 of 16** · Version 1.0 · Status: **Draft — awaiting approval** · 2026-08-05

Six personas, ordered by priority for V1. Each maps to requirement IDs in Deliverable 1 so design decisions stay traceable to a real user.

---

## P1 — Priya, the Agency Owner ★ Primary

| | |
|---|---|
| **Role** | Founder, 8-person WordPress agency |
| **Context** | 30 client sites on retainer, £2k–8k/month contracts |
| **Technical level** | High — writes PHP, manages staging, uses WP-CLI |
| **Buying authority** | Full |
| **Target tier** | **Agency — $899/yr** |

**Situation.** Priya's agency competes with cheaper freelancers and offshore shops. Retainers are under pressure and clients keep asking "what are we actually paying for each month?" They need a service line that is visibly valuable, recurring, and not something a client can buy themselves for £10.

**Goals**
- Add a billable AI service to every retainer without hiring
- Deploy consistently across 30 sites without 30 separate configurations
- Present it as *their* offering, not a third-party plugin the client could buy direct
- Show clients a monthly report proving the value

**Frustrations**
- SaaS tools bill per client site, destroying the margin on resale
- Client data in a US SaaS creates GDPR conversations they do not want to have
- Every tool requires teaching the client a new dashboard
- Existing WordPress AI plugins look amateurish next to their design work

**Jobs to be done**
> "When I onboard a new retainer client, I want to deploy a branded AI assistant in under an hour, so I can charge £300/month for something that costs me almost nothing to run."

**Buying trigger.** A client asks for "something with AI" and Priya has no answer that protects margin.

**Objections**
| Objection | What must be true |
|---|---|
| "Will this break my clients' sites?" | Documented host compatibility, diagnostics panel, no fatal-error surface |
| "Can I put my own brand on it?" | White-label mode replaces name, logo, colours everywhere |
| "Do I have to configure 30 sites by hand?" | Clerk export/import; agency dashboard |
| "What happens when a client leaves?" | Per-site licence deactivation without touching the others |

**Success looks like.** Six clients upsold within a quarter; the agency dashboard is where they start their morning.

**Key requirements:** FR-SYS-08 (white-label), FR-SYS-09 (multisite), FR-CLK-10 (export/import), FR-ANL-01 (client-facing reporting)

---

## P2 — Marcus, the WooCommerce Store Owner ★ Primary

| | |
|---|---|
| **Role** | Owner-operator, specialist outdoor equipment store |
| **Context** | ~£40k/month revenue, 1,200 SKUs, two part-time staff |
| **Technical level** | Medium — installs plugins confidently, does not write code |
| **Buying authority** | Full |
| **Target tier** | **Pro — $199/yr** |

**Situation.** Marcus answers the same twelve questions every day: sizing, stock, delivery times, returns, compatibility. Evenings and weekends are his highest-traffic hours and nobody is there to answer. He suspects he loses sales but cannot prove it.

**Goals**
- Answer product questions accurately at 11pm without staffing it
- Stop repetitive email and get back to buying inventory
- Recover some of the carts that abandon at the shipping question
- Know it never invents a price or promises stock that does not exist

**Frustrations**
- Chatbots that answer confidently and wrongly, then he refunds an angry customer
- Gorgias and Zendesk quotes exceed his monthly profit
- Tools that do not know his catalogue, so answers are generic
- He does not want to "train" anything — the answers already exist on his site

**Jobs to be done**
> "When a customer asks at midnight whether a jacket fits over a mid-layer, I want an accurate answer from my own product pages, so the sale closes instead of going to a competitor."

**Buying trigger.** A weekend of missed enquiries, or a competitor's site visibly has one.

**Objections**
| Objection | What must be true |
|---|---|
| "It'll make things up about my products" | Groundedness guardrails, citations, "never invent prices/stock" toggle |
| "How much will the AI cost me?" | Cost estimate before publish; live spend dashboard; hard budget caps |
| "Will it slow my store down?" | < 50 ms LCP impact, async load, ≤ 40 KB bundle |
| "I don't have time to set it up" | Wizard auto-detects products and pages; working clerk in 10 minutes |

**Success looks like.** Dashboard shows 40 conversations/week, 12 leads, and 3 orders influenced. He renews without thinking about it.

**Key requirements:** FR-ONB-04 (auto-detect), FR-CLK-06 (guardrails), FR-KB-01 (product indexing), FR-ANL-04 (cost tracking), NFR-01 (performance)

---

## P3 — Sofia, the SaaS Marketing Lead ★ Primary

| | |
|---|---|
| **Role** | Head of Marketing, 25-person B2B SaaS |
| **Context** | WordPress marketing site, product on a separate stack; HubSpot CRM |
| **Technical level** | Medium-high — comfortable with HubSpot, GA4, Zapier |
| **Buying authority** | Recommends; CEO approves under $2k |
| **Target tier** | **Pro or Business** |

**Situation.** Sofia's demo-request form converts at 2%. Sales complains that the leads that do arrive are unqualified — students, competitors, people with no budget. She wants to qualify *before* the handoff, not after.

**Goals**
- Raise conversion by engaging visitors who would never fill a form
- Qualify on budget, timeline, and team size conversationally
- Push only qualified leads into HubSpot with the full transcript attached
- Prove the channel's contribution in her board deck

**Frustrations**
- Static forms are a wall; visitors bounce rather than fill them
- Intercom quoted more than her whole tooling budget
- Sales does not trust marketing's lead scoring because it is opaque
- No visibility into what prospects actually ask before converting

**Jobs to be done**
> "When a visitor reads our pricing page for three minutes, I want a clerk to open a conversation, qualify them on budget and timeline, and route only the real ones to sales with context attached."

**Buying trigger.** A quarterly review where sales publicly blames lead quality.

**Objections**
| Objection | What must be true |
|---|---|
| "Sales won't trust the score" | Transparent score breakdown with a written rationale per lead |
| "It has to reach HubSpot properly" | Field mapping incl. custom fields, retries, visible sync log |
| "It can't sound like a bot" | Full tone and personality control; test console before publishing |
| "I need to prove ROI" | Funnel report: conversation → engaged → captured → qualified → won |

**Success looks like.** Qualified demo requests up 35%; sales stops complaining; the funnel chart goes in the board deck.

**Key requirements:** FR-LED-02/03/04 (qualification and scoring), FR-CRM-04 (HubSpot), FR-CRM-07 (field mapping), FR-ANL-05 (funnel)

---

## P4 — David, the Course Creator ◆ Secondary

| | |
|---|---|
| **Role** | Solo creator, online courses on LearnDash |
| **Context** | 3,000 students, £15k/month, no staff |
| **Technical level** | Low-medium — uses page builders, avoids code |
| **Buying authority** | Full |
| **Target tier** | **Free → Pro** |

**Situation.** David answers the same pre-purchase questions ("is this for beginners?", "how long do I get access?") plus a steady stream of student support. It eats the time he should spend making courses.

**Goals** — deflect repetitive questions; capture emails from browsers who are not ready to buy; support students without a helpdesk.

**Frustrations** — support is unpaid work that scales with success; existing tools are priced for companies with staff; he will not maintain a knowledge base by hand.

**Jobs to be done**
> "When a prospective student asks whether the course suits a beginner, I want an accurate answer from my own sales page, so I stop answering it forty times a week."

**Objections** — "Is the free tier actually usable?" (yes, deliberately) · "Do I have to maintain it?" (auto re-sync on publish)

**Why he matters.** David is the free-tier persona. He converts to Pro when the chunk cap bites — which is the intended mechanic. He also writes WordPress.org reviews and talks to other creators, so his experience disproportionately affects acquisition.

**Key requirements:** FR-KB-09 (auto re-sync), FR-KB-11 (free cap as upgrade trigger), FR-ONB-01 (wizard)

---

## P5 — Aisha, the Enterprise Web Manager ◆ Tertiary, high value

| | |
|---|---|
| **Role** | Digital Manager, 400-person healthcare-adjacent organisation |
| **Context** | Large multisite WordPress estate, internal IT and legal review |
| **Technical level** | Medium — manages vendors and requirements, not code |
| **Buying authority** | Recommends; procurement and legal approve |
| **Target tier** | **Agency / custom** |

**Situation.** Aisha's organisation wants AI-assisted support, but legal blocked two SaaS vendors over data processing. Self-hosting is not a preference here — it is the only viable path.

**Goals** — deploy AI support without visitor data leaving controlled infrastructure; satisfy legal with a defensible data-flow diagram; audit every configuration change; roll out across multiple sites under one policy.

**Frustrations** — every vendor is multi-tenant SaaS; DPAs take months; no vendor can explain exactly where data goes.

**Jobs to be done**
> "When legal asks where visitor data goes, I want a one-page diagram showing it stays in our database except for the model call to a provider we already have a DPA with."

**Objections**
| Objection | What must be true |
|---|---|
| "Where exactly does data go?" | Published data-flow diagram; documented sub-processors |
| "Can we prove who changed what?" | Full audit log with user, timestamp, and diff |
| "Can we delete a person completely?" | GDPR eraser removes conversations, messages, and derived embeddings |
| "What if the provider is unreachable?" | Graceful degradation to a fallback message |

**Why she matters.** Low volume, high contract value, and she validates the data-ownership pillar that differentiates the whole product. Her requirements make the product more trustworthy for everyone.

**Key requirements:** FR-SYS-04 (GDPR), FR-SYS-05 (audit log), FR-SYS-02 (capabilities), NFR-09 (privacy), NFR-12 (degradation)

---

## P6 — Tom, the Local Business Owner ◇ Tertiary

| | |
|---|---|
| **Role** | Owner, two-branch dental practice |
| **Context** | Brochure site, bookings by phone |
| **Technical level** | Low — pays someone else for anything technical |
| **Buying authority** | Full but price-sensitive |
| **Target tier** | **Free**, occasionally Pro via an agency |

**Situation.** The practice misses calls during treatment hours. The website answers nothing. Tom knows he is losing bookings but has no time to fix it.

**Goals** — answer opening hours, pricing, and location questions; capture enquiries out of hours; do it without learning software.

**Frustrations** — everything is priced per month for something he does not understand; the last plugin he installed broke the site.

**Jobs to be done**
> "When someone asks about implant prices at 9pm, I want them to get an answer and leave their number, so we can call back tomorrow."

**Why he matters.** He rarely pays directly, but he is the volume driver for free-tier installs and, critically, he is **Priya's client**. The Agency tier monetises Tom indirectly. Design for him as an *end beneficiary* who never opens the admin, not as a buyer.

**Key requirements:** FR-ONB-01 (wizard), FR-KB-04 (FAQ editor), FR-LED-01 (capture), free tier usability

---

## Anti-Persona — who we deliberately do not serve

| Not our user | Why | Where they should go |
|---|---|---|
| Enterprise CX team needing omnichannel + SOC 2 | We have neither in V1 and will not by V3 | Intercom, Zendesk |
| Shopify merchant | Not a WordPress site | Gorgias |
| Developer wanting a raw LLM library | We are an application, not an SDK | Provider SDKs directly |
| Business wanting AI content generation | Explicit non-goal | AI Engine and similar |
| Anyone unwilling to hold a model API key **and** unwilling to pay for Managed | No viable path to value | Chatbase free tier |

Naming these prevents roadmap drift and stops sales chasing deals that will churn.

---

## Persona → Priority Summary

| Persona | Segment | Tier | V1 priority | Revenue weight |
|---|---|---|---|---|
| P1 Priya | Agency | Agency $899 | **Critical** | 40% |
| P2 Marcus | WooCommerce | Pro $199 | **Critical** | 25% |
| P3 Sofia | SaaS marketing | Pro/Business | **Critical** | 20% |
| P4 David | Course creator | Free → Pro | High | 7% |
| P5 Aisha | Enterprise | Custom | Medium | 5% |
| P6 Tom | Local business | Free | Low (funnel) | 3% |

**Design implication.** P1, P2 and P3 account for 85% of planned V1 revenue. Where their needs conflict with P4–P6, they win. Concretely: the admin optimises for a competent operator managing multiple clerks, not for the absolute beginner — with the onboarding wizard carrying beginners through their first success.

---

**Approval:** ⬜ Awaiting sign-off · Reviewer: ______________ · Date: __________
