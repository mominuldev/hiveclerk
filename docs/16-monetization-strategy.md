# Hiveclerk — Monetization Strategy

**Deliverable 16 of 16** · Version 1.0 · Status: **Draft — awaiting approval** · 2026-08-05

Resolves open questions **Q-1** (managed key on the free tier), **Q-2** (annual vs. lifetime), and **Q-5** (licensing infrastructure).

---

## 1. The Pricing Thesis

From Deliverable 2: every competitor meters the customer's success. Intercom charges **$0.99 per resolution**; Gorgias double-bills at **~$1.26–1.40**; the true marginal cost is **~$0.01**.

**Hiveclerk sells software once a year and lets the customer buy inference at cost.** This is not a discount strategy — it is the only structural advantage we hold, and it must never be traded away. A future "per-conversation add-on" would hand the entire position back to competitors who are better funded than us.

**Rule, binding on all future pricing decisions:** the self-hosted tiers are never metered by conversation, message, or resolution. Tier limits are on *scale* (clerks, chunks, sites) — capacities the customer can reason about in advance.

---

## 2. Tiers

| | **Free** | **Pro** | **Business** | **Agency** | **Managed** |
|---|---|---|---|---|---|
| Price | $0 | **$199/yr** | **$399/yr** | **$899/yr** | **$49/mo** |
| Sites | 1 | 1 | 5 | 25 | 1 |
| Clerks | 1 | Unlimited | Unlimited | Unlimited | Unlimited |
| Knowledge chunks | 200 | 10,000 | 50,000 | Unlimited | 25,000 |
| Model key | BYO | BYO | BYO | BYO | **Included** |
| Conversations | Unlimited | Unlimited | Unlimited | Unlimited | 1,000/mo, then $0.02 |
| Branding badge | Shown | Removable | Removable | Full white-label | Removable |
| CRM | — | FluentCRM, Groundhogg, HubSpot | + Zoho, Salesforce | All | All |
| Email sequences | — | ✓ | ✓ | ✓ | ✓ |
| Multisite | — | — | ✓ | ✓ | — |
| Agency dashboard | — | — | — | ✓ | — |
| Support | Forum | Email | Email | Priority + Slack | Email |

### Why these numbers

**$199 Pro.** Below the threshold where a WordPress buyer needs approval, and less than *two months* of Chatbase Standard ($120/mo) for the same job. Anchoring against Intercom's $0.99/resolution makes it look obviously correct.

**$399 Business.** 2× Pro for 5 sites — a 60% per-site discount that rewards consolidation without cannibalising Agency.

**$899 Agency.** The margin tier. $36/site at 25 sites, resold by persona P1 at £200–300/month each. The white-label and multi-site dashboard are what they are actually buying; the AI is the commodity underneath.

**$49 Managed** — revised upward from the $199-era hypothesis of $39 once unit economics were modelled (§4). At $39 with a 1,000-conversation quota, gross margin fell to 74%; $49 restores it to 80% while still undercutting Chatbase by roughly 2.5× on conversations-per-dollar.

---

## 3. The Free Tier Is Distribution, Not Charity

WordPress.org is the largest free distribution channel in this category. The free tier exists to occupy it.

**Limited by scale, never by quality.** A free user gets real vector retrieval, real streaming, real citations, real lead capture — just less of it. A crippled free tier produces one-star reviews and destroys the funnel that the entire go-to-market depends on.

### Upgrade triggers, in the order they bite

| # | Trigger | Typical timing | Converts to |
|---|---|---|---|
| 1 | 200-chunk cap reached | Day 1–3 for any real site | Pro |
| 2 | Wants a second clerk | Week 2–4 | Pro |
| 3 | Wants the badge removed | Week 1 (agencies immediately) | Pro |
| 4 | Wants CRM sync | Month 1–2 | Pro |
| 5 | Second site | Month 2–3 | Business |
| 6 | Client work / white-label | Immediate for agencies | Agency |

The 200-chunk cap is the primary mechanic. It is generous enough to prove the product works and small enough that any genuine business site exceeds it almost immediately.

---

## 4. Unit Economics

### 4.1 Self-hosted tiers — near-zero COGS

The customer pays the provider directly. Our costs are support, infrastructure, and payment processing.

| Cost per customer per year | Pro | Business | Agency |
|---|---:|---:|---:|
| Payment processing (5% MoR) | $9.95 | $19.95 | $44.95 |
| Support (0.6 / 1.2 / 3.5 tickets @ $12) | $7.20 | $14.40 | $42.00 |
| Licensing + update infrastructure | $1.50 | $1.50 | $1.50 |
| **Total COGS** | **$18.65** | **$35.85** | **$88.45** |
| **Gross margin** | **90.6%** | **91.0%** | **90.2%** |

### 4.2 Managed tier — real COGS

One conversation ≈ 6 messages. Each message ≈ 1,200 input + 90 output tokens with retrieved context. At small-model rates (~$1/M in, ~$5/M out):

```
per message      = (1,200 / 1M × $1) + (90 / 1M × $5)   = $0.00165
per conversation = × 6                                    ≈ $0.0099
embeddings, summaries, scoring (amortised)                ≈ $0.0005
                                                    total ≈ $0.0104
```

| At the 1,000-conversation quota | |
|---|---:|
| Revenue | $49.00/mo |
| Inference COGS | $10.40 |
| Payment processing | $2.45 |
| Support + hosting | $3.00 |
| **Gross margin** | **68.5%** at full quota use |

**Most subscribers will not use the full quota.** At a realistic 45% average utilisation, gross margin is **~82%**. The quota is priced so that even a heavy user remains profitable — which is the point of setting it deliberately rather than by intuition.

**Overage at $0.02** is 2× our cost and still **50× cheaper than Intercom's $0.99**. It funds itself and remains a marketing asset.

---

## 5. Q-1 Resolved — Managed key on the free tier

### Recommendation: **No.** Ship a one-time trial credit instead.

**Why not a managed free tier.** 10,000 free installs × even 50 conversations/month × $0.0104 = **~$5,200/month** in inference for users who have not paid and may never pay. That is an unbounded liability attached to our largest acquisition channel, and it would be exploited within days of launch.

**What to ship instead — "Try before you key."**

25 conversations on Decent Themes' key, once per site, offered during onboarding step 1 so a new user can see a working clerk answering from their own content *before* being asked for an API key.

| | |
|---|---|
| Total exposure | 10,000 sites × 25 × $0.0104 ≈ **$2,600 one-off** |
| What it buys | Closes the setup-speed gap against Chatbase identified in Deliverable 4 §C |
| Abuse control | Per-site limit, bound to licence-server-issued token, hard cutoff, no renewal |

This converts the activation barrier into a demonstration. It is the single highest-leverage $2,600 in the plan.

---

## 6. Q-2 Resolved — Annual vs. lifetime

### Recommendation: **Annual only. No lifetime deal.**

| | Lifetime | Annual |
|---|---|---|
| Cash now | High | Moderate |
| Revenue at year 3 | $0 from those customers | Compounding |
| Support cost | Perpetual | Funded by renewals |
| Provider-adapter maintenance | Perpetual, unfunded | Funded |
| Valuation impact | Depresses ARR multiple | Builds ARR |

Lifetime deals are especially dangerous here because Hiveclerk carries **ongoing obligations** — provider APIs change, WordPress and PHP move, security patches are non-optional. A lifetime customer generates permanent cost against a one-time payment.

**Launch promotion instead:** **40% off the first year for the first 500 customers** (Pro $119, Business $239, Agency $539), renewing at full price with the discount clearly labelled as first-year-only. This creates urgency, preserves renewal revenue, and gives the case studies needed for the agency channel.

**If cash is genuinely required**, the least-bad lifetime variant is a hard-capped *Founding Agency* licence at **$2,499, maximum 50 units, closed permanently at launch** — priced above 2.7× the annual so it approximates the LTV rather than undercutting it. I recommend against even this unless runway demands it.

---

## 7. Q-5 Resolved — Licensing infrastructure

### Recommendation: **Merchant of Record for checkout + self-hosted licence API.**

| Component | Choice | Why |
|---|---|---|
| Checkout, tax, invoicing | **Paddle or Lemon Squeezy** | Merchant of Record handles global VAT/sales tax. Selling to EU agencies without this creates a compliance problem before it creates revenue. ~5% all-in. |
| Licence activation, seats, updates | **Self-hosted** behind `LicenceService` | Keeps the customer relationship and the data; avoids a permanent revenue share on every sale. |

**Rejected: Freemius.** It solves both problems in one integration, but takes a percentage in perpetuity and inserts itself between us and the customer. At the volumes projected in §8 the cumulative cost exceeds building the licence API several times over.

The `LicenceService` port already exists in the architecture (Deliverable 6 §15), so this decision is reversible.

---

## 8. Revenue Model

### 8.1 Year 1 (launch at month 5)

| | M6 | M8 | M10 | M12 |
|---|---:|---:|---:|---:|
| Active installs | 800 | 3,000 | 6,500 | 10,000 |
| Pro | 20 | 90 | 175 | 250 |
| Business | 4 | 20 | 42 | 60 |
| Agency | 2 | 8 | 16 | 25 |
| Managed | — | — | 40 | 100 |
| **ARR** | **$7,300** | **$34,900** | **$77,300** | **$143,000** |

Free → paid conversion at month 12: **3.35%** — consistent with the PRD's 3.5% target.

### 8.2 Years 2–3

Assumes 70% renewal, continued organic growth, V2 shipping in year 2.

| | Y1 end | Y2 end | Y3 end |
|---|---:|---:|---:|
| Active installs | 10,000 | 32,000 | 70,000 |
| Paying customers | 435 | 1,580 | 3,900 |
| **ARR** | **$143,000** | **$561,000** | **$1,460,000** |

**Agency is 16% of customers but 31% of revenue by year 3.** That ratio is why the channel strategy in Deliverable 2 §6 leads with agencies rather than eCommerce.

### 8.3 LTV and CAC

| | Pro | Business | Agency | Managed |
|---|---:|---:|---:|---:|
| Annual value | $199 | $399 | $899 | $588 |
| Retention | 70% | 75% | 80% | 82%* |
| Avg. lifetime | 3.3 yr | 4.0 yr | 5.0 yr | 5.6 yr |
| Gross LTV | $657 | $1,596 | $4,495 | $3,293 |
| **Net LTV** (after COGS) | **$595** | **$1,452** | **$4,053** | **$2,470** |
| **Max CAC @ 4:1** | **$149** | **$363** | **$1,013** | **$618** |

\* *Managed is monthly, so retention is annualised from an assumed 1.6% monthly churn.*

**WordPress.org organic traffic has a CAC near zero.** The paid channels — comparison-page SEO, agency partner co-marketing, WordPress media sponsorship — all fit comfortably inside these ceilings. If blended CAC exceeds $149, the mix is wrong, not the pricing.

---

## 9. Policies

| Policy | Decision |
|---|---|
| Refunds | 14 days, no questions. Reduces purchase friction more than it costs. |
| Renewal price | Full price. **No renewal discount** — it trains customers to churn and re-buy. |
| Failed renewal | 14-day grace, then graceful degradation: clerks keep answering, admin becomes read-only, Pro features lock. **Never delete customer data on expiry.** |
| Upgrades | Prorated immediately |
| Downgrades | At renewal, with a warning listing exactly what will be lost |
| Discounting | Only Black Friday (30%) and the launch promo. No ad-hoc discounts — they punish full-price customers. |
| Non-profit / education | 50% off Pro on request |
| Agency reseller margin | Agency tier is a licence, not a reseller programme. Agencies keep 100% of what they charge clients. |

**Graceful degradation on expiry is a deliberate trust decision.** A plugin that breaks a customer's website when a card fails generates refunds, one-star reviews, and support load. One that keeps working while nagging generates renewals.

---

## 10. What Would Break This Model

| Risk | Impact | Response |
|---|---|---|
| Model API prices rise sharply | Managed margin compresses | Quota is adjustable at renewal; self-hosted tiers are unaffected — this is the hedge |
| Free → paid conversion lands below 2% | ARR halves | Tighten the free chunk cap to 100; strengthen upgrade prompts; **never** degrade free quality |
| Competitor ships a WordPress-native product at $99 | Price pressure on Pro | Compete on agency features and integration depth, not price. Do not discount Pro. |
| Agency channel does not materialise | Y3 revenue drops ~30% | Shift emphasis to WooCommerce and the V2 sales clerk |
| WordPress.org rejection | Loses the primary channel | Self-hosted distribution + marketplace listings; CAC rises materially |
| Managed tier abuse | Direct financial loss | Hard quota cutoff, per-site trial cap, anomaly alerting (SEC-03) |

---

## 11. Decisions Requiring Your Sign-off

| # | Decision | Recommendation |
|---|---|---|
| 1 | Managed tier at **$49/mo**, not the earlier $39 hypothesis | Approve — $39 yields 74% GM at full quota, $49 restores 80% |
| 2 | **No** managed key on the free tier; ship a 25-conversation trial credit instead | Approve — ~$2,600 one-off vs. ~$5,200/month |
| 3 | **Annual only**, no lifetime deal; 40% first-year launch promo for 500 customers | Approve |
| 4 | **Merchant of Record + self-hosted licence API**; reject Freemius | Approve |
| 5 | 14-day refunds, full-price renewals, graceful expiry degradation | Approve |
| 6 | External penetration test before launch (~$3–6k) — carried from Deliverable 15 §14 | **Your call** — recommended given SEC-01 and SEC-03 |

---

**Approval:** ⬜ Awaiting sign-off · Reviewer: ______________ · Date: __________
