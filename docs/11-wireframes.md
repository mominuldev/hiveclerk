# Hiveclerk — UI/UX Wireframes

**Deliverable 11 of 16** · Version 1.0 · Status: **Draft — awaiting approval** · 2026-08-05

Covers all 9 admin areas, the onboarding wizard, and the public widget. Visual tokens are specified in Deliverable 12.

---

## 1. Design Thesis

> **This is a staff board, not a dashboard.**

The product's premise is that you *employ* these clerks. The interface should behave that way: the roster is always visible, duty status is always legible, and every screen answers one of two questions — *is my staff doing their job?* or *how do I fix this one?*

Three consequences that shape every screen below:

| Decision | Rationale |
|---|---|
| **The Roster rail is permanent** | Clerks are the nouns of this application. They occupy the lower half of the sidebar on every screen, showing live duty status. |
| **Colour is rationed** | The base UI is achromatic. Colour signals duty status and the single primary action. A screen with four coloured buttons has no primary action. |
| **Every number traces to a source** | Costs, scores, and answers all expose their derivation. This is the trust mechanism the competitor analysis identified as our required parity with CustomGPT. |

---

## 2. Application Shell

```
┌─────────────────────┬────────────────────────────────────────────────────────────────┐
│ ⬢ Hiveclerk    ⌘K   │  Dashboard                                    [ ⚙ ]  [ AV ]    │  56px
├─────────────────────┼────────────────────────────────────────────────────────────────┤
│                     │                                                                │
│  ▪ Dashboard        │                                                                │
│  ▫ Conversations 12 │                                                                │
│  ▫ Leads          4 │                                                                │
│  ▫ Knowledge        │                     WORK SURFACE                                │
│  ▫ Integrations     │                                                                │
│  ▫ Workflows    Pro │                     max-width 1280                              │
│  ▫ Analytics        │                                                                │
│  ▫ Settings         │                                                                │
│                     │                                                                │
│  ─────────────────  │                                                                │
│  ROSTER         + │ │                                                                │
│                     │                                                                │
│  ● Ada          ▍   │  ← honey bar = currently selected                              │
│    Support          │                                                                │
│  ● Rafi             │  ● emerald  = on duty                                          │
│    Sales            │                                                                │
│  ◐ Mira             │  ◐ blue     = indexing knowledge                               │
│    Qualifier        │                                                                │
│  ○ Tomas            │  ○ slate    = paused                                           │
│    Concierge        │                                                                │
│  ◌ Untitled         │  ◌ zinc     = draft, never published                           │
│                     │                                                                │
│  ─────────────────  │                                                                │
│  ◈ Pro · 3 sites    │                                                                │
└─────────────────────┴────────────────────────────────────────────────────────────────┘
   264px                                                                                
```

**Roster behaviour.** Clicking a clerk filters the current screen to that clerk rather than navigating away — so selecting "Rafi" on Conversations shows Rafi's conversations, and on Analytics shows Rafi's numbers. The roster is a persistent filter, not a menu. This is the single most distinctive interaction in the product.

**Responsive.** Below 1024px the sidebar collapses to a 56px icon rail; the roster becomes a horizontal scrolling strip beneath the header. Below 768px both collapse into a sheet.

**Command palette (⌘K).** Jump to any clerk, conversation, or lead; run actions ("pause Ada", "re-index shipping policy"). Ships in V1 — it is the fastest path to competence for persona P1, who manages 30 sites.

---

## 3. Dashboard

```
┌──────────────────────────────────────────────────────────────────────────────────────┐
│  Dashboard                                     [ Last 30 days ▾ ]   [ All clerks ▾ ] │
│                                                                                      │
│  ┌────────────────────────────────────────────────────────────────────────────────┐  │
│  │ ⚠  23 questions went unanswered this week.        [ Review knowledge gaps → ]   │  │
│  └────────────────────────────────────────────────────────────────────────────────┘  │
│                                                                                      │
│  ┌──────────────────┐ ┌──────────────────┐ ┌──────────────────┐ ┌─────────────────┐ │
│  │ QUALIFIED        │ │ CONVERSATIONS    │ │ LEADS CAPTURED   │ │ SPEND           │ │
│  │                  │ │                  │ │                  │ │                 │ │
│  │      317         │ │     1,284        │ │       96         │ │    $14.82       │ │
│  │  ▲ 8.1%          │ │  ▲ 12.4%         │ │  ▼ 3.2%          │ │  ▲ 6.5%         │ │
│  │  ▁▂▃▅▄▆▇         │ │  ▂▃▄▅▆▇█         │ │  ▅▄▃▄▃▂▂         │ │  ▁▂▂▃▃▄▅        │ │
│  └──────────────────┘ └──────────────────┘ └──────────────────┘ └─────────────────┘ │
│    ↑ North Star                                        ↑ Archivo Exp, tabular figs   │
│                                                                                      │
│  ┌─────────────────────────────────────────────┐ ┌──────────────────────────────────┐│
│  │ CONVERSATION VOLUME                         │ │ ROSTER PERFORMANCE               ││
│  │                                             │ │                                  ││
│  │  60 ┤                            ╭─╮        │ │ ● Ada        612 conv   87% ▓▓▓▓ ││
│  │     │                    ╭───╮  ╭╯ ╰╮       │ │ ● Rafi       381 conv   79% ▓▓▓░ ││
│  │  30 ┤        ╭──╮   ╭───╯   ╰──╯    ╰──     │ │ ◐ Mira       218 conv   71% ▓▓░░ ││
│  │     │  ╭─────╯  ╰───╯                       │ │ ○ Tomas       73 conv   64% ▓▓░░ ││
│  │   0 ┼──┴────────────────────────────────    │ │                                  ││
│  │     1 Jul                          30 Jul   │ │        deflection rate ↑         ││
│  └─────────────────────────────────────────────┘ └──────────────────────────────────┘│
│                                                                                      │
│  ┌─────────────────────────────────────────────┐ ┌──────────────────────────────────┐│
│  │ TOP QUESTIONS                               │ │ NEEDS ATTENTION                  ││
│  │                                             │ │                                  ││
│  │  Shipping to the EU                    142  │ │ ⬤ Handoff waiting 14m            ││
│  │  Return window                          98  │ │   "…speak to a person" · Ada     ││
│  │  Sizing for the Alpine jacket           76  │ │                                  ││
│  │  Bulk discount                          54  │ │ ⬤ Rafi paused 3 days ago         ││
│  │  Warranty length                        41  │ │                                  ││
│  │                                             │ │ ⬤ HubSpot sync failing (6)       ││
│  │  [ See all → ]                              │ │                                  ││
│  └─────────────────────────────────────────────┘ └──────────────────────────────────┘│
└──────────────────────────────────────────────────────────────────────────────────────┘
```

**Why "Qualified" leads the KPI row.** It is the North Star from the PRD — an outcome metric, not a volume metric. Conversation count can rise while the product fails; qualified conversations cannot.

**"Needs attention" is a work queue, not a notification feed.** Every item is actionable and disappears once handled. An empty state here reads *"Nothing needs you right now."*

---

## 4. AI Employees (Clerks)

### 4.1 Roster list

```
┌──────────────────────────────────────────────────────────────────────────────────────┐
│  AI Employees                                              [ + Hire a clerk ]        │
│                                                                                      │
│  [ Search ]            [ All statuses ▾ ]  [ All roles ▾ ]              4 of 4       │
│                                                                                      │
│  ┌────────────────────────────────────────────────────────────────────────────────┐  │
│  │ ●  Ada                                                            [ ··· ]      │  │
│  │    Support clerk · On duty since 12 Jun                                        │  │
│  │                                                                                │  │
│  │    612 conversations    87% resolved    4 sources    $6.20 this month          │  │
│  │    ▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓░░░░░  token budget 74% of 500k             │  │
│  └────────────────────────────────────────────────────────────────────────────────┘  │
│                                                                                      │
│  ┌────────────────────────────────────────────────────────────────────────────────┐  │
│  │ ◐  Mira                                                           [ ··· ]      │  │
│  │    Lead qualifier · Indexing knowledge — 1,240 of 3,800 chunks                 │  │
│  │    ▓▓▓▓▓▓▓▓▓░░░░░░░░░░░░░░░░░  33%                     [ Cancel indexing ]     │  │
│  └────────────────────────────────────────────────────────────────────────────────┘  │
│                                                                                      │
│  ┌────────────────────────────────────────────────────────────────────────────────┐  │
│  │ ◌  Untitled clerk                                                 [ ··· ]      │  │
│  │    Draft · Never published                          [ Finish setup → ]         │  │
│  └────────────────────────────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────────────────────────────┘
```

The card reads like a personnel record: role, start date, workload, results, cost. Not a settings row.

### 4.2 Clerk editor — split pane with live test

```
┌──────────────────────────────────────────────────────────────────────────────────────┐
│  ← Employees   /   Ada                          ● On duty    [ Pause ]  [ Save ]     │
├───────────────────────────────────────────────┬──────────────────────────────────────┤
│ Job description  Knowledge  Guardrails        │  TEST CONSOLE            [ Reset ]   │
│ Appearance  Where it appears  Leads           │                                      │
├───────────────────────────────────────────────┤  ┌────────────────────────────────┐  │
│                                               │  │ You                            │  │
│  NAME                                         │  │ What's your return policy?     │  │
│  ┌─────────────────────────────────────────┐  │  └────────────────────────────────┘  │
│  │ Ada                                     │  │                                      │
│  └─────────────────────────────────────────┘  │  ┌────────────────────────────────┐  │
│                                               │  │ Ada                            │  │
│  ROLE                                         │  │ You can return any unworn item │  │
│  ( ) Support    (•) Sales    ( ) Qualifier    │  │ within 30 days of delivery.    │  │
│  ( ) FAQ        ( ) Concierge ( ) Custom      │  │                                │  │
│                                               │  │ ▸ Returns Policy · Timeframe   │  │
│  WHAT THIS CLERK DOES                         │  │   0.91                         │  │
│  ┌─────────────────────────────────────────┐  │  └────────────────────────────────┘  │
│  │ You work for Alpine Outfitters. Help    │  │                                      │
│  │ visitors find the right gear for their  │  │  ┌──── DIAGNOSTICS ──────────────┐  │
│  │ trip. Ask what conditions they expect   │  │  │ Retrieval        78 ms        │  │
│  │ before recommending anything.           │  │  │ Completion    1,420 ms        │  │
│  │                                         │  │  │ Tokens      1,204 → 88        │  │
│  └─────────────────────────────────────────┘  │  │ Cost           $0.0031        │  │
│  Written in second person. 284 / 4,000        │  │ Grounded            Yes        │  │
│                                               │  │ Guardrails         None        │  │
│  TONE                                         │  │                                │  │
│  Formal ●──────○──────────── Casual           │  │ [ View full prompt ]           │  │
│  Brief  ────────●─────────── Detailed         │  └────────────────────────────────┘  │
│                                               │                                      │
│  GREETING                                     │  ┌────────────────────────────────┐  │
│  ┌─────────────────────────────────────────┐  │  │ Ask Ada something…        [↵]  │  │
│  │ Heading somewhere cold? I can help.     │  │  └────────────────────────────────┘  │
│  └─────────────────────────────────────────┘  │                                      │
└───────────────────────────────────────────────┴──────────────────────────────────────┘
```

**The test console is permanent, not a modal.** Every edit is verifiable in the same breath it is made. Diagnostics show cost and groundedness on every test run — the two things that make an operator trust or distrust the clerk.

### 4.3 Guardrails tab

```
│  NEVER INVENT FACTS                                                                  │
│  [ ●━━ ]  On    If the answer isn't in the knowledge base, say so and offer a human.  │
│                                                                                      │
│  CONFIDENCE THRESHOLD                                                                │
│  Hand off below  ────────●──────────  0.62                                           │
│  Lower means the clerk answers more often but with weaker sources.                   │
│                                                                                      │
│  NEVER DISCUSS                                                                       │
│  [ competitor pricing ×] [ legal advice ×] [ medical claims ×]  [ + Add topic ]       │
│                                                                                      │
│  ALWAYS APPEND                                                                       │
│  ┌────────────────────────────────────────────────────────────────────────────────┐  │
│  │ Prices shown exclude VAT.                                                      │  │
│  └────────────────────────────────────────────────────────────────────────────────┘  │
│                                                                                      │
│  MONTHLY TOKEN BUDGET                                                                │
│  ┌──────────────┐  tokens · resets 1 Sep · ≈ $12.40 at current rates                 │
│  │ 500,000      │  When exhausted:  (•) Show fallback message  ( ) Keep answering    │
│  └──────────────┘                                                                    │
```

Every control states its consequence in plain language beneath it. Marcus (persona P2) must be able to read this screen and know his clerk will not invent a price.

---

## 5. Conversations

```
┌──────────────────────────────────────────────────────────────────────────────────────┐
│  Conversations                                         [ Export ]  [ Last 7 days ▾ ] │
│                                                                                      │
│  [ ⬤ Needs reply 3 ] [ All ] [ Handoffs ] [ Negative ] [ Leads ]      [ Search ]     │
├────────────────────────────────────┬─────────────────────────────────────────────────┤
│ ⬤ Anonymous · Ada          14m     │  Anonymous visitor              [ Take over ]   │
│   "…can I speak to a person"       │  Ada · /products/alpine-jacket · Berlin, DE      │
│   ⚑ Handoff requested              │  ───────────────────────────────────────────    │
│ ─────────────────────────────────  │                                                 │
│   Sarah Klein · Rafi        1h     │   Visitor                              14:02    │
│   "…bulk pricing for 40 units"     │   Does the Alpine jacket fit over a mid-layer?  │
│   ★ Lead · 72                      │                                                 │
│ ─────────────────────────────────  │   Ada                                  14:02    │
│   Anonymous · Ada           2h     │   It's cut with a regular fit, so a mid-layer   │
│   "…return window"                 │   fits comfortably underneath. If you're        │
│   ✓ Resolved                       │   layering a thick fleece, size up.             │
│ ─────────────────────────────────  │                                                 │
│   Anonymous · Mira          3h     │   ▸ Alpine Jacket · Fit and sizing      0.89    │
│   "…do you have a trade account"   │   ▸ Layering Guide · Mid-layers         0.71    │
│   ⚑ Handoff · ✓ Resolved           │                                                 │
│ ─────────────────────────────────  │   ⧗ 1.4s · 1,204 → 88 tokens · $0.0031          │
│   Anonymous · Ada           5h     │                                                 │
│   "…warranty"                      │   Visitor                              14:05    │
│   ✓ Resolved                       │   Great. Can I speak to a person about a bulk   │
│                                    │   order?                                        │
│                                    │                                                 │
│                                    │   ⚑ Handoff requested · waiting 14m             │
│                                    │  ───────────────────────────────────────────    │
│                                    │  ┌───────────────────────────────────────────┐  │
│                                    │  │ Reply as yourself…                    [↵] │  │
│                                    │  └───────────────────────────────────────────┘  │
│                                    │  Ada stops replying once you take over.         │
└────────────────────────────────────┴─────────────────────────────────────────────────┘
```

**Citations are inline, beneath the message that used them.** Clicking one opens the source chunk. This is how an operator audits an answer without leaving the transcript.

**Cost and latency sit on every assistant message.** Small, monospace, low contrast — present when you look for it, silent when you don't.

---

## 6. Leads

### 6.1 Pipeline

```
┌──────────────────────────────────────────────────────────────────────────────────────┐
│  Leads                            [ ⊞ Pipeline ] [ ☰ Table ]   [ Export ]  [ Rules ] │
│                                                                                      │
│  ┌─── NEW 12 ────┐ ┌─ CONTACTED 8 ─┐ ┌─ QUALIFIED 5 ─┐ ┌── WON 3 ──┐ ┌── LOST 2 ──┐ │
│  │               │ │               │ │               │ │           │ │            │ │
│  │ ┌───────────┐ │ │ ┌───────────┐ │ │ ┌───────────┐ │ │ ┌───────┐ │ │ ┌────────┐ │ │
│  │ │ S. Klein  │ │ │ │ M. Turner │ │ │ │ J. Okafor │ │ │ │ L. Ba │ │ │ │ P. Ryn │ │ │
│  │ │ Nordwind  │ │ │ │ Peak Ltd  │ │ │ │ Trailhead │ │ │ │ Vertx │ │ │ │ —      │ │ │
│  │ │ ▓▓▓▓▓▓▓░ 72│ │ │ │ ▓▓▓▓░░░ 45│ │ │ │ ▓▓▓▓▓▓▓▓88│ │ │ │ ▓▓▓ 91│ │ │ │ ▓░░ 22 │ │ │
│  │ │ hot       │ │ │ │ warm      │ │ │ │ qualified │ │ │ │       │ │ │ │        │ │ │
│  │ └───────────┘ │ │ └───────────┘ │ │ └───────────┘ │ │ └───────┘ │ │ └────────┘ │ │
│  │ ┌───────────┐ │ │ ┌───────────┐ │ │ ┌───────────┐ │ │           │ │            │ │
│  │ │ A. Novak  │ │ │ │ D. Silva  │ │ │ │ R. Haile  │ │ │           │ │            │ │
│  │ │ ▓▓▓▓▓░ 58 │ │ │ │ ▓▓▓░░░ 38 │ │ │ │ ▓▓▓▓▓▓ 79 │ │ │           │ │            │ │
│  │ └───────────┘ │ │ └───────────┘ │ │ └───────────┘ │ │           │ │            │ │
│  └───────────────┘ └───────────────┘ └───────────────┘ └───────────┘ └────────────┘ │
└──────────────────────────────────────────────────────────────────────────────────────┘
```

### 6.2 Lead detail — the score breakdown

```
┌──────────────────────────────────────────────────────────────────────────────────────┐
│  ← Leads  /  Sarah Klein                                    [ Sync to CRM ]  [ ··· ] │
├───────────────────────────────────────────────┬──────────────────────────────────────┤
│  Sarah Klein                                  │  SCORE            72 / 100      hot  │
│  sarah@nordwind.de · +49 30 …                 │  ▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓░░░░░░░░    │
│  Nordwind Outdoor GmbH · Head of Procurement  │                                      │
│                                               │  HOW THIS WAS CALCULATED             │
│  Stage  [ New ▾ ]      Owner  [ Unassigned ▾ ]│                                      │
│                                               │  Business email             +15      │
│  ─── QUALIFICATION ──────────────────────────  │  Rule                                │
│  Budget          €5,000 – €15,000             │                                      │
│  Timeline        This quarter                 │  Pricing page ≥ 2 visits    +20      │
│  Team size       40 – 100                     │  Rule                                │
│  Use case        Staff uniform refresh        │                                      │
│                                               │  Budget stated ≥ €5,000     +25      │
│  ─── TIMELINE ───────────────────────────────  │  Rule                                │
│  14:22  Synced to HubSpot           ✓         │                                      │
│  14:19  Stage → New                           │  Buying-intent language     +12      │
│  14:18  Score 47 → 72                         │  AI · "Asked about implementation    │
│  14:18  Qualified by Rafi                     │  timeline and contract terms, and    │
│  14:05  Lead captured                         │  named a decision date."             │
│  13:58  Conversation started                  │                                      │
│  13:51  Viewed /pricing (2nd)                 │  ────────────────────────────────    │
│  13:44  Viewed /products/workwear             │  Total                       72      │
│                                               │                                      │
│  [ View full conversation → ]                 │  [ Adjust manually ]                 │
└───────────────────────────────────────────────┴──────────────────────────────────────┘
```

**Every point is attributed and every AI adjustment carries a written rationale.** This screen exists because persona P3's sales team does not trust opaque scoring — and an unexplained number is worse than no number.

---

## 7. Knowledge Base

### 7.1 Sources

```
┌──────────────────────────────────────────────────────────────────────────────────────┐
│  Knowledge                                                      [ + Add knowledge ]  │
│                                                                                      │
│  8,412 chunks of 10,000        ▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓░░░░░░░  84%        │
│                                                                                      │
│  ┌────────────────────────────────────────────────────────────────────────────────┐  │
│  │ ✓  Website — alpineoutfitters.com                            [ Sync ] [ ··· ]  │  │
│  │    Crawl · 412 pages · 5,204 chunks · synced 2 hours ago                        │  │
│  │    Daily at 03:00                                                              │  │
│  ├────────────────────────────────────────────────────────────────────────────────┤  │
│  │ ✓  Products                                                  [ Sync ] [ ··· ]  │  │
│  │    WooCommerce · 1,180 products · 2,360 chunks · synced 20 min ago              │  │
│  │    On publish                                                                  │  │
│  ├────────────────────────────────────────────────────────────────────────────────┤  │
│  │ ◐  Supplier handbook.pdf                                             [ ··· ]   │  │
│  │    PDF · 84 pages · embedding 612 of 848                                       │  │
│  │    ▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓░░░░░░░  72%                     [ Cancel ]           │  │
│  ├────────────────────────────────────────────────────────────────────────────────┤  │
│  │ ⚠  Returns & shipping FAQ                                    [ Retry ] [ ··· ] │  │
│  │    FAQ · 34 entries · Failed 1 hour ago                                        │  │
│  │    Provider returned 429. Rate limit — will retry automatically at 15:40.      │  │
│  └────────────────────────────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────────────────────────────┘
```

Errors state what happened, what it means, and what happens next. No apology, no vagueness.

### 7.2 Crawl preview — before spending money

```
┌──────────────────────────────────────────────────────────────────────────────────────┐
│  Add a website                                                                       │
│                                                                                      │
│  URL      [ https://alpineoutfitters.com                                          ]  │
│  Limit    [ 500 ] pages          [ ✓ ] Respect robots.txt                            │
│  Skip     [ /cart, /checkout, /my-account, /wp-admin                              ]  │
│                                                                                      │
│                                                        [ Preview what we'd index ]   │
│  ────────────────────────────────────────────────────────────────────────────────    │
│                                                                                      │
│  Found 412 pages · about 5,204 chunks · roughly 1.9M tokens                          │
│                                                                                      │
│  ┌──────────────────────────────────────────────────────────────────────────────┐    │
│  │ One-off indexing cost           ≈ $0.19                                      │    │
│  │ Re-sync after content changes   ≈ $0.02  (only changed pages are re-embedded)│    │
│  └──────────────────────────────────────────────────────────────────────────────┘    │
│                                                                                      │
│  ✓ /                        ✓ /products/alpine-jacket      ✗ /cart      skipped      │
│  ✓ /shipping                ✓ /products/base-layer         ✗ /checkout  skipped      │
│  ✓ /returns                 ✓ /about                       ✗ /wp-admin  robots.txt   │
│                                                    [ Show all 412 ]                  │
│                                                                                      │
│                                              [ Cancel ]   [ Index 412 pages ]        │
└──────────────────────────────────────────────────────────────────────────────────────┘
```

**Cost is shown before commitment.** This directly answers risk R-3 — model spend surprising the customer — and it is the kind of detail that separates a considered product from a wrapper.

### 7.3 Knowledge gaps — the most actionable screen in the product

```
┌──────────────────────────────────────────────────────────────────────────────────────┐
│  Knowledge  /  Gaps                                    [ Open 23 ] [ Resolved ] [All]│
│                                                                                      │
│  Questions your clerks couldn't answer from your knowledge.                          │
│                                                                                      │
│  ┌────────────────────────────────────────────────────────────────────────────────┐  │
│  │ "Do you offer trade accounts?"                                       asked 18× │  │
│  │ Best match scored 0.21 — well below Rafi's 0.62 threshold.                     │  │
│  │                                          [ Write an answer ]  [ Ignore ]       │  │
│  ├────────────────────────────────────────────────────────────────────────────────┤  │
│  │ "What's the warranty on zips?"                                       asked 11× │  │
│  │ Best match scored 0.34 — Warranty page covers fabric only.                     │  │
│  │                                          [ Write an answer ]  [ Ignore ]       │  │
│  ├────────────────────────────────────────────────────────────────────────────────┤  │
│  │ "Can I collect from your Berlin store?"                               asked 7× │  │
│  │ No matching content found.                                                     │  │
│  │                                          [ Write an answer ]  [ Ignore ]       │  │
│  └────────────────────────────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────────────────────────────┘
```

**"Write an answer" opens an inline FAQ composer** that adds the entry, embeds it, and marks the gap resolved — closing the loop without leaving the screen. This is the product's compounding-value mechanic: the more it is used, the better it gets.

### 7.4 Retrieval playground

```
┌──────────────────────────────────────────────────────────────────────────────────────┐
│  Knowledge  /  Playground                                        [ Ada ▾ ]           │
│                                                                                      │
│  [ international shipping cost                                        ]  [ Search ]  │
│                                                                                      │
│  Stage 1 coarse 200 candidates · 22 ms      Stage 2 exact · 18 ms      Fusion · 6 ms  │
│                                                                                      │
│  1   Shipping Policy › International                       vector 0.89   bm25 4.21   │
│      "Orders outside the EU are shipped DDU. Duties are…"           fused 0.93       │
│                                                                                      │
│  2   FAQ › Delivery                                        vector 0.81   bm25 6.02   │
│      "How much does international delivery cost? Rates…"            fused 0.88       │
│                                                                                      │
│  3   Terms › Section 4                                     vector 0.74   bm25 1.10   │
│      "…carrier surcharges may apply to remote areas."               fused 0.61       │
│                                        ─────── threshold 0.62 ───────                │
│  4   Returns › International                               vector 0.58   bm25 0.40   │
│      "Return shipping for international orders is…"                 fused 0.44       │
└──────────────────────────────────────────────────────────────────────────────────────┘
```

The threshold line is drawn across the results. An operator can see exactly which content their clerk will and will not use — making retrieval quality debuggable rather than mystical.

---

## 8. Integrations

```
┌──────────────────────────────────────────────────────────────────────────────────────┐
│  Integrations                                                                        │
│                                                                                      │
│  CRM                                                                                 │
│  ┌──────────────────┐ ┌──────────────────┐ ┌──────────────────┐ ┌─────────────────┐ │
│  │ FluentCRM        │ │ HubSpot          │ │ Groundhogg       │ │ Salesforce      │ │
│  │ ● Connected      │ │ ⚠ 6 failed syncs │ │ ○ Not connected  │ │ ◈ Business      │ │
│  │                  │ │                  │ │                  │ │                 │ │
│  │ 214 contacts     │ │ Token expired    │ │ Installed        │ │ Upgrade to use  │ │
│  │ [ Configure ]    │ │ [ Reconnect ]    │ │ [ Connect ]      │ │ [ Compare → ]   │ │
│  └──────────────────┘ └──────────────────┘ └──────────────────┘ └─────────────────┘ │
│                                                                                      │
│  NOTIFICATIONS                                                                       │
│  ┌──────────────────┐ ┌──────────────────┐                                          │
│  │ Slack            │ │ Webhook          │                                          │
│  │ ○ Not connected  │ │ ● 2 endpoints    │                                          │
│  └──────────────────┘ └──────────────────┘                                          │
│                                                                                      │
│  ─── FIELD MAPPING · FluentCRM ─────────────────────────────────────────────────     │
│  Hiveclerk                    FluentCRM                                              │
│  Email                    →   Email                          locked                  │
│  First name               →   [ First Name        ▾ ]                                │
│  Company                  →   [ Company           ▾ ]                                │
│  Score                    →   [ Custom: hvc_score ▾ ]                                │
│  Qualification: Budget    →   [ Custom: budget    ▾ ]                                │
│  Transcript               →   [ Note              ▾ ]                                │
│                                                        [ + Map another field ]       │
│                                                                                      │
│  Push when   ( ) Lead captured   (•) Lead qualified   ( ) Score above [ 60 ]          │
└──────────────────────────────────────────────────────────────────────────────────────┘
```

Locked tier features show what they do and where to get them — never a dead disabled control.

---

## 9. Workflows (V2 placeholder)

```
┌──────────────────────────────────────────────────────────────────────────────────────┐
│  Workflows                                                                           │
│                                                                                      │
│                              ┌───────────────────────┐                               │
│                              │  ⬢                    │                               │
│                              └───────────────────────┘                               │
│                                                                                      │
│                        Workflows arrive in version 2.0                               │
│                                                                                      │
│         Chain triggers, conditions and actions — recover carts, escalate              │
│         angry conversations, nurture leads without writing code.                     │
│                                                                                      │
│              [ Tell us what you'd automate ]   [ See the roadmap → ]                 │
│                                                                                      │
└──────────────────────────────────────────────────────────────────────────────────────┘
```

The V2 stub collects demand rather than showing a dead menu item.

---

## 10. Analytics

```
┌──────────────────────────────────────────────────────────────────────────────────────┐
│  Analytics                       [ Last 30 days ▾ ]  vs [ Previous period ▾ ]  [ ⤓ ] │
│                                                                                      │
│  Overview    Clerks    Funnel    Topics    Costs                                     │
│                                                                                      │
│  ┌─── LEAD FUNNEL ────────────────────────────────────────────────────────────────┐  │
│  │                                                                                │  │
│  │  Conversations   ████████████████████████████████████████████  1,284           │  │
│  │  Engaged 3+      ██████████████████████████                      742    57.8%  │  │
│  │  Captured        ████████                                        231    31.1%  │  │
│  │  Qualified       ████                                             96    41.6%  │  │
│  │  Won             █                                                18    18.8%  │  │
│  │                                                                                │  │
│  │  Biggest drop-off: engaged → captured. 511 visitors talked but never           │  │
│  │  left contact details.              [ Review Rafi's capture prompts → ]        │  │
│  └────────────────────────────────────────────────────────────────────────────────┘  │
│                                                                                      │
│  ┌─── SPEND ──────────────────────────────────┐ ┌─── BY CLERK ────────────────────┐ │
│  │  $14.82 this month · $0.0115 per conv      │ │ Ada        $6.20   ▓▓▓▓▓▓▓▓░░░  │ │
│  │                                            │ │ Rafi       $4.90   ▓▓▓▓▓▓░░░░░  │ │
│  │  ▁▁▂▂▃▃▄▄▅▅▆▆▇▇                            │ │ Mira       $2.81   ▓▓▓░░░░░░░░  │ │
│  │  Completion $11.20 · Embedding $3.62       │ │ Tomas      $0.91   ▓░░░░░░░░░░  │ │
│  └────────────────────────────────────────────┘ └─────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────────────────────────────┘
```

**Every chart carries a written finding.** A funnel that says "biggest drop-off is engaged → captured" and links to the fix is worth more than a funnel that only renders bars.

---

## 11. Settings

```
┌──────────────────────────────────────────────────────────────────────────────────────┐
│  Settings                                                                            │
│  Providers   Licence   Privacy   Branding   Team   Audit log   System                 │
│                                                                                      │
│  ─── MODEL PROVIDERS ────────────────────────────────────────────────────────────     │
│                                                                                      │
│  ┌────────────────────────────────────────────────────────────────────────────────┐  │
│  │ ● Anthropic                                              Verified 4 Aug        │  │
│  │   sk-ant-•••••••••••••••••••••4f2a                    [ Replace ]  [ Test ]    │  │
│  │   Default for  [ Claude Haiku 4.5 ▾ ]                                          │  │
│  ├────────────────────────────────────────────────────────────────────────────────┤  │
│  │ ○ OpenAI                                                                       │  │
│  │   [ Paste an API key                                        ]  [ Verify ]      │  │
│  │   Used for embeddings if set. Your key stays on this server, encrypted.        │  │
│  └────────────────────────────────────────────────────────────────────────────────┘  │
│                                                                                      │
│  ─── PRIVACY ────────────────────────────────────────────────────────────────────     │
│  Keep conversations for   [ 12 months ▾ ]   Then deleted automatically, nightly.      │
│  [ ✓ ] Anonymise visitor IP addresses                                                │
│  [   ] Ask for consent before the first message                                      │
│                                                                                      │
│  Where your data goes                                                                │
│  Conversations, leads and knowledge stay in this site's database. Message text is    │
│  sent to Anthropic to generate replies. Nothing else leaves this server.             │
│  [ Download the data-flow diagram ]  [ Export everything ]  [ Delete everything ]     │
└──────────────────────────────────────────────────────────────────────────────────────┘
```

The privacy panel is written for Aisha (persona P5) to forward to her legal team unedited.

---

## 12. Onboarding Wizard

Five steps, ten minutes, one working clerk. This flow carries the activation metric (PG-1).

```
┌──────────────────────────────────────────────────────────────────────────────────────┐
│                                                                                      │
│   ●━━━━━━━●━━━━━━━○───────○───────○                                                  │
│   Model   Role    Knowledge  Look   Publish                                          │
│                                                                                      │
│                                                                                      │
│              What should your first clerk do?                                        │
│                                                                                      │
│   ┌──────────────────────┐  ┌──────────────────────┐  ┌──────────────────────┐      │
│   │                      │  │                      │  │                      │      │
│   │  Answer support      │  │  Qualify leads       │  │  Help people buy     │      │
│   │  questions           │  │                      │  │                      │      │
│   │                      │  │  Asks about budget   │  │  Recommends          │      │
│   │  Replies from your   │  │  and timeline, then  │  │  products and        │      │
│   │  docs and FAQs.      │  │  scores the lead.    │  │  answers questions.  │      │
│   │  Hands off when      │  │                      │  │                      │      │
│   │  unsure.             │  │                      │  │  Needs WooCommerce   │      │
│   │                      │  │                      │  │                      │      │
│   └──────────────────────┘  └──────────────────────┘  └──────────────────────┘      │
│                                                                                      │
│   ┌──────────────────────┐  ┌──────────────────────┐                                │
│   │  Answer FAQs         │  │  Start from scratch  │                                │
│   └──────────────────────┘  └──────────────────────┘                                │
│                                                                                      │
│                                            [ Skip setup ]        [ Continue → ]      │
└──────────────────────────────────────────────────────────────────────────────────────┘
```

**Step 3 auto-detects sources** and pre-ticks them, so the common path is one click:

```
│   We found these on your site                                                        │
│                                                                                      │
│   [✓] Pages                      24 pages          ≈ 180 chunks                      │
│   [✓] Products                1,180 products     ≈ 2,360 chunks                      │
│   [✓] Posts                      86 posts          ≈ 640 chunks                      │
│   [ ] Your sitemap              412 pages        ≈ 5,204 chunks                      │
│                                                                                      │
│   Selected: 3,180 chunks · one-off cost ≈ $0.11                                      │
│   Indexing runs in the background. You can publish before it finishes.               │
```

**Step 5 shows the clerk working before asking for commitment** — a live preview on a screenshot of the customer's own homepage, then `[ Put Ada on duty ]`.

---

## 13. Public Chat Widget

```
   Closed                          Open
   ┌──────────────┐                ┌────────────────────────────────────┐
   │              │                │  ⬢  Ada                      ─  ✕  │
   │              │                │     Usually replies instantly      │
   │              │                ├────────────────────────────────────┤
   │              │                │                                    │
   │              │                │  ┌──────────────────────────────┐  │
   │              │                │  │ Heading somewhere cold?      │  │
   │              │                │  │ I can help.                  │  │
   │              │                │  └──────────────────────────────┘  │
   │              │                │                                    │
   │              │                │       ┌───────────────────────┐    │
   │              │                │       │ Does the Alpine jacket│    │
   │              │                │       │ fit over a mid-layer? │    │
   │              │                │       └───────────────────────┘    │
   │        ┌───┐ │                │                                    │
   │        │ ⬢ │ │                │  ┌──────────────────────────────┐  │
   │        └───┘ │                │  │ It's a regular fit, so a     │  │
   └──────────────┘                │  │ mid-layer fits underneath.   │  │
                                   │  │ Size up for a thick fleece.  │  │
                                   │  │                              │  │
                                   │  │ ▸ Fit and sizing             │  │
                                   │  │                       ⌃  ⌄   │  │
                                   │  └──────────────────────────────┘  │
                                   │                                    │
                                   ├────────────────────────────────────┤
                                   │ ┌────────────────────────────────┐ │
                                   │ │ Ask anything…              [↑] │ │
                                   │ └────────────────────────────────┘ │
                                   │        Powered by Hiveclerk        │
                                   └────────────────────────────────────┘
```

### 13.1 In-chat lead capture

Capture is conversational, never a form wall:

```
   │  ┌──────────────────────────────┐  │
   │  │ I can get you trade pricing. │  │
   │  │ Where should I send it?      │  │
   │  │                              │  │
   │  │ ┌──────────────────────────┐ │  │
   │  │ │ you@company.com          │ │  │
   │  │ └──────────────────────────┘ │  │
   │  │ [ Send it ]      [ Not now ] │  │
   │  └──────────────────────────────┘  │
```

**"Not now" is always present.** A capture prompt that cannot be dismissed is a dark pattern and it produces junk leads.

### 13.2 Widget states

| State | Treatment |
|---|---|
| Streaming | Three-dot pulse, then tokens appear; no layout shift |
| Human requested | Inline confirmation: "Someone will pick this up here. You can close this — we'll email you." |
| Provider down | "I can't reach my brain right now. Leave your email and we'll follow up." + capture field |
| Budget exhausted | The clerk's configured fallback message; no error language shown to the visitor |
| Offline / no clerk matches | Launcher does not render at all — no empty bubble |

---

## 14. Cross-Cutting Patterns

### 14.1 Empty states are invitations

| Screen | Copy |
|---|---|
| No clerks | **Nobody's on duty yet.** Hire your first clerk and it'll start answering in about ten minutes. `[ Hire a clerk ]` |
| No conversations | **No conversations yet.** Once Ada is live, everything visitors say shows up here. `[ Check where Ada appears ]` |
| No leads | **No leads yet.** Ada captures contact details when a conversation shows buying intent. `[ Set up qualification ]` |
| No knowledge | **Your clerks have nothing to read.** Point them at your pages, products or a PDF. `[ Add knowledge ]` |
| No gaps | **Nothing unanswered this week.** Your knowledge is keeping up. |

### 14.2 Loading

Skeletons that match the shape of the content, never spinners. Charts reserve their final height so nothing shifts. Streaming text appears progressively; the container never resizes mid-stream.

### 14.3 Destructive actions

Type-to-confirm for anything irreversible with a stated blast radius:

```
   Delete "Website — alpineoutfitters.com"?

   This removes 5,204 chunks. Ada and Rafi both use this source and will
   lose access to it. Conversations that cited it keep their citations.

   Type  alpineoutfitters.com  to confirm
   ┌────────────────────────────────────┐
   │                                    │
   └────────────────────────────────────┘
                    [ Cancel ]  [ Delete source ]
```

### 14.4 Voice

| Rule | Instead of | Write |
|---|---|---|
| Name things by what people control | "Configure agent inference parameters" | "Choose a model" |
| Actions keep their name through the flow | Button "Save" → toast "Updated" | Button "Publish" → toast "Published" |
| Errors say what happened and what next | "An error occurred" | "Provider returned 429. Rate limit — retrying at 15:40." |
| No apologies, no exclamation marks | "Oops! Sorry, something went wrong!" | "That didn't send. Check the key and try again." |
| Never say "AI agent" in the UI | "Agent #3 is active" | "Ada is on duty" |

---

## 15. Accessibility

| Requirement | Implementation |
|---|---|
| Contrast | 4.5:1 body, 3:1 large text and UI boundaries — both themes |
| Keyboard | Every action reachable; ⌘K palette; visible 2px focus ring, never removed |
| Screen readers | Streaming replies in `aria-live="polite"`; duty status announced as text, never colour alone |
| Status without colour | Every status dot pairs with a shape and a text label |
| Motion | `prefers-reduced-motion` disables all transitions and the typing pulse |
| Targets | 40×40px minimum in admin, 44×44px in widget |
| Zoom | Usable to 200% without horizontal scroll |
| Widget | Focus trapped when open, returns to launcher on close, `Esc` closes |

---

**Approval:** ⬜ Awaiting sign-off · Reviewer: ______________ · Date: __________
