# Hiveclerk — API Specification

**Deliverable 9 of 16** · Version 1.0 · Status: **Draft — awaiting approval** · 2026-08-05

Base: `/wp-json/hiveclerk/v1`

---

## 1. Conventions

### 1.1 Authentication

| Surface | Mechanism |
|---|---|
| **Admin** (`/admin/*`) | WordPress cookie auth + `X-WP-Nonce` header. Every route declares a `permission_callback` performing an explicit capability check. |
| **Public** (`/public/*`) | `X-HVC-Session` header carrying an opaque session token. Issued by `POST /public/session`, HMAC-signed with a site secret, bound to site URL and expiry. Contains no PII. |
| **Internal** (`/internal/*`) | Not exposed over HTTP. PHP service interfaces only — documented in §5. |

**A nonce is not a capability check.** Both are always required on admin routes; nonce proves intent, capability proves authorisation.

### 1.2 Capabilities

| Capability | Grants |
|---|---|
| `hiveclerk_manage_agents` | Create, edit, publish, delete clerks |
| `hiveclerk_view_conversations` | Read transcripts and analytics |
| `hiveclerk_manage_conversations` | Take over, tag, delete conversations |
| `hiveclerk_manage_leads` | Read/edit leads, pipeline, export |
| `hiveclerk_manage_knowledge` | Manage sources and trigger re-index |
| `hiveclerk_manage_integrations` | Connect CRMs, view credentials status |
| `hiveclerk_manage_settings` | API keys, licence, retention, white-label |

Mapped to `administrator` on activation. `shop_manager` receives the conversation, lead, and knowledge capabilities but **not** `manage_settings` — satisfying FR-SYS-02.

### 1.3 Response envelope

Success:
```json
{ "data": { }, "meta": { } }
```

Collection:
```json
{
  "data": [ ],
  "meta": {
    "pagination": { "page": 1, "per_page": 25, "total": 342, "total_pages": 14 }
  }
}
```

Error (also sets the matching HTTP status):
```json
{
  "code": "hvc_validation_failed",
  "message": "The request could not be validated.",
  "data": {
    "status": 422,
    "errors": { "name": ["Name is required."] }
  }
}
```

### 1.4 Standard parameters

| Param | Applies to | Notes |
|---|---|---|
| `page`, `per_page` | Collections | `per_page` max 100, default 25 |
| `search` | Collections | Server-side, indexed columns only |
| `order_by`, `order` | Collections | Whitelisted columns only — never interpolated into SQL |
| `date_from`, `date_to` | Time-series | ISO 8601, UTC |
| `fields` | Any | Sparse fieldsets to reduce payload |

### 1.5 Error codes

| Code | HTTP | Meaning |
|---|---|---|
| `hvc_unauthorized` | 401 | No valid session or nonce |
| `hvc_forbidden` | 403 | Authenticated but lacks capability |
| `hvc_not_found` | 404 | Resource missing or soft-deleted |
| `hvc_validation_failed` | 422 | Schema validation failure |
| `hvc_rate_limited` | 429 | Includes `Retry-After` header |
| `hvc_licence_required` | 402 | Feature gated by tier |
| `hvc_quota_exceeded` | 402 | Token budget or chunk cap reached |
| `hvc_provider_error` | 502 | Upstream model provider failed |
| `hvc_provider_unconfigured` | 409 | No API key set for the selected provider |
| `hvc_conflict` | 409 | Concurrent modification |
| `hvc_server_error` | 500 | Unhandled — logged with a correlation ID |

### 1.6 Rate limits

| Endpoint group | Limit | Window | Key |
|---|---|---|---|
| `POST /public/chat/*` | 20 | 1 min | session + IP |
| `POST /public/session` | 10 | 1 min | IP |
| `POST /public/events` | 60 | 1 min | session |
| `POST /public/leads` | 5 | 1 min | session |
| Admin write routes | 120 | 1 min | user |
| Admin read routes | 600 | 1 min | user |

Responses carry `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`.

---

## 2. Public API

Consumed by the chat widget. Unauthenticated visitors — every route is hardened and rate-limited.

### 2.1 Bootstrap

```http
GET /public/bootstrap?agent={uuid}&url={page_url}
```

Returns the widget configuration for the current page, or `204 No Content` if no clerk matches the display rules. Cached with `Cache-Control: public, max-age=300`.

```json
{
  "data": {
    "agent": {
      "uuid": "…", "name": "Ada", "avatar_url": "…",
      "greeting": "Hi — what can I help you find?",
      "widget_config": { "position": "bottom-right", "accent": "#4F46E5",
                         "radius": 16, "theme": "auto" },
      "locale": "en_GB",
      "branding": { "show_badge": true, "label": "Powered by Hiveclerk" }
    },
    "capabilities": { "streaming": true, "handoff": true, "feedback": true },
    "consent": { "required": false, "text": null }
  }
}
```

### 2.2 Session

```http
POST /public/session
{ "agent": "uuid", "url": "...", "referrer": "...", "language": "en", "consent": true }
→ 201 { "data": { "session": "hvc_s_…", "conversation": "uuid", "expires_at": "…" } }
```

### 2.3 Chat — streaming

```http
POST /public/chat/stream
X-HVC-Session: hvc_s_…
Accept: text/event-stream

{ "message": "Do you ship to Germany?", "conversation": "uuid" }
```

SSE frame sequence:

```
: probe                                          ← buffering detection, sent immediately

event: start
data: {"message_id":"uuid","conversation":"uuid"}

event: delta
data: {"text":"Yes — we ship to "}

event: delta
data: {"text":"Germany within 3–5 days."}

event: citations
data: {"citations":[{"title":"Shipping Policy","url":"/shipping",
                     "heading_path":"Shipping > EU","score":0.87}]}

event: done
data: {"message_id":"uuid","tokens_in":842,"tokens_out":96,
       "grounded":true,"lead_captured":false}
```

Error frame:
```
event: error
data: {"code":"hvc_provider_error","message":"…","recoverable":true}
```

### 2.4 Chat — polling fallback

Used when the probe frame does not arrive within 2,500 ms (Deliverable 6 §5).

```http
POST /public/chat/message      → 202 { "data": { "message_id": "uuid" } }
GET  /public/chat/poll?message={uuid}&cursor={n}
                               → 200 { "data": { "text": "…", "cursor": 42,
                                                 "complete": false } }
```

### 2.5 Remaining public routes

| Method | Route | Purpose |
|---|---|---|
| `GET` | `/public/chat/history?conversation={uuid}` | Restore transcript on page navigation |
| `POST` | `/public/chat/feedback` | `{ message, rating: -1\|1, comment? }` |
| `POST` | `/public/chat/handoff` | Request a human; flags conversation, notifies staff |
| `POST` | `/public/chat/end` | Close conversation, trigger summary + scoring jobs |
| `POST` | `/public/leads` | Direct lead capture from an in-chat form |
| `POST` | `/public/events` | Visitor telemetry: `page_view`, `scroll_depth`, `exit_intent`, `cart_updated` |

**`/public/events` is deliberately minimal.** It accepts a whitelisted event vocabulary only; arbitrary event names are rejected to prevent it becoming an open write endpoint.

---

## 3. Admin API

### 3.1 Dashboard

| Method | Route | Capability | Returns |
|---|---|---|---|
| `GET` | `/admin/dashboard` | `view_conversations` | KPI cards, trends, recent activity, alerts |
| `GET` | `/admin/dashboard/health` | `manage_settings` | Cron status, queue depth, provider reachability, cache mode |

```json
{
  "data": {
    "kpis": {
      "conversations": { "value": 1284, "delta_pct": 12.4 },
      "qualified_conversations": { "value": 317, "delta_pct": 8.1 },
      "leads_captured": { "value": 96, "delta_pct": -3.2 },
      "deflection_rate": { "value": 0.83, "delta_pct": 2.0 },
      "spend": { "value": 14.82, "currency": "USD", "delta_pct": 6.5 }
    },
    "series": { "conversations": [{ "date": "2026-08-01", "value": 42 }] },
    "alerts": [
      { "level": "warning", "code": "hvc_unanswered_spike",
        "message": "23 unanswered questions this week", "action": "/knowledge/gaps" }
    ]
  }
}
```

### 3.2 Clerks

| Method | Route | Capability |
|---|---|---|
| `GET` | `/admin/agents` | `manage_agents` |
| `POST` | `/admin/agents` | `manage_agents` |
| `GET` | `/admin/agents/{id}` | `manage_agents` |
| `PATCH` | `/admin/agents/{id}` | `manage_agents` |
| `DELETE` | `/admin/agents/{id}` | `manage_agents` |
| `POST` | `/admin/agents/{id}/publish` | `manage_agents` |
| `POST` | `/admin/agents/{id}/pause` | `manage_agents` |
| `POST` | `/admin/agents/{id}/duplicate` | `manage_agents` |
| `POST` | `/admin/agents/{id}/test` | `manage_agents` |
| `GET` | `/admin/agents/{id}/export` | `manage_agents` |
| `POST` | `/admin/agents/import` | `manage_agents` |
| `GET` | `/admin/agents/presets` | `manage_agents` |

**Test console** — runs the clerk without touching the live site, returning full diagnostics:

```http
POST /admin/agents/{id}/test
{ "message": "What's your return policy?", "history": [] }
```
```json
{
  "data": {
    "reply": "You can return any item within 30 days…",
    "citations": [ { "chunk_id": 9812, "score": 0.91,
                     "heading_path": "Returns > Timeframe", "excerpt": "…" } ],
    "diagnostics": {
      "retrieval_ms": 78, "completion_ms": 1420,
      "tokens_in": 1204, "tokens_out": 88,
      "cost": 0.0031, "grounded": true,
      "prompt_preview": "…redacted system prompt…",
      "guardrails_triggered": []
    }
  }
}
```

### 3.3 Knowledge Base

| Method | Route | Capability |
|---|---|---|
| `GET` `POST` | `/admin/knowledge/sources` | `manage_knowledge` |
| `GET` `PATCH` `DELETE` | `/admin/knowledge/sources/{id}` | `manage_knowledge` |
| `POST` | `/admin/knowledge/sources/{id}/sync` | `manage_knowledge` |
| `POST` | `/admin/knowledge/sources/{id}/cancel` | `manage_knowledge` |
| `GET` | `/admin/knowledge/sources/{id}/progress` | `manage_knowledge` |
| `GET` | `/admin/knowledge/sources/{id}/documents` | `manage_knowledge` |
| `GET` | `/admin/knowledge/documents/{id}/chunks` | `manage_knowledge` |
| `POST` | `/admin/knowledge/upload` | `manage_knowledge` |
| `POST` | `/admin/knowledge/crawl/preview` | `manage_knowledge` |
| `POST` | `/admin/knowledge/search` | `manage_knowledge` |
| `GET` `PATCH` | `/admin/knowledge/gaps` | `manage_knowledge` |

**Retrieval playground** (FR-KB-12) — the tool that makes retrieval quality debuggable:

```http
POST /admin/knowledge/search
{ "query": "international shipping cost", "agent_id": 3, "top_k": 10 }
```
```json
{
  "data": {
    "results": [
      { "chunk_id": 9812, "document_title": "Shipping Policy",
        "heading_path": "Shipping > International",
        "excerpt": "…", "vector_score": 0.89, "bm25_score": 4.21,
        "fused_score": 0.93, "rank": 1 }
    ],
    "diagnostics": { "stage1_candidates": 200, "stage1_ms": 22,
                     "stage2_ms": 18, "fusion_ms": 6, "total_ms": 46 }
  }
}
```

**Crawl preview** returns discovered URLs and an estimated chunk/token count **before** the customer spends money embedding — directly addressing risk R-3.

### 3.4 Conversations

| Method | Route | Capability |
|---|---|---|
| `GET` | `/admin/conversations` | `view_conversations` |
| `GET` | `/admin/conversations/{id}` | `view_conversations` |
| `DELETE` | `/admin/conversations/{id}` | `manage_conversations` |
| `POST` | `/admin/conversations/{id}/takeover` | `manage_conversations` |
| `POST` | `/admin/conversations/{id}/reply` | `manage_conversations` |
| `POST` | `/admin/conversations/{id}/resolve` | `manage_conversations` |
| `PATCH` | `/admin/conversations/{id}/tags` | `manage_conversations` |
| `POST` | `/admin/conversations/{id}/notes` | `manage_conversations` |
| `GET` | `/admin/conversations/export` | `view_conversations` |

Filters: `agent_id`, `status`, `sentiment`, `has_lead`, `rating`, `handoff`, `date_from`, `date_to`, `search`.

### 3.5 Leads

| Method | Route | Capability |
|---|---|---|
| `GET` `POST` | `/admin/leads` | `manage_leads` |
| `GET` `PATCH` `DELETE` | `/admin/leads/{id}` | `manage_leads` |
| `GET` | `/admin/leads/{id}/timeline` | `manage_leads` |
| `GET` | `/admin/leads/{id}/score` | `manage_leads` |
| `POST` | `/admin/leads/{id}/score/adjust` | `manage_leads` |
| `POST` | `/admin/leads/{id}/stage` | `manage_leads` |
| `POST` | `/admin/leads/{id}/sync` | `manage_leads` |
| `POST` | `/admin/leads/merge` | `manage_leads` |
| `GET` | `/admin/leads/export` | `manage_leads` |
| `GET` `POST` `PATCH` `DELETE` | `/admin/leads/stages` | `manage_leads` |
| `GET` `PUT` | `/admin/leads/scoring-rules` | `manage_leads` |

Score breakdown (FR-LED-04 — the transparency persona P3 requires):

```json
{
  "data": {
    "score": 72, "band": "hot",
    "breakdown": [
      { "source": "rule", "label": "Provided business email", "points": 15 },
      { "source": "rule", "label": "Visited pricing page ≥2×", "points": 20 },
      { "source": "rule", "label": "Budget stated ≥ £5k", "points": 25 },
      { "source": "ai",   "label": "Buying-intent language", "points": 12,
        "rationale": "Asked about implementation timeline and contract terms." }
    ]
  }
}
```

### 3.6 Email, Integrations, Analytics, Settings

| Method | Route | Capability |
|---|---|---|
| `GET` `POST` | `/admin/email/sequences` | `manage_leads` |
| `GET` `PATCH` `DELETE` | `/admin/email/sequences/{id}` | `manage_leads` |
| `POST` | `/admin/email/sequences/{id}/activate` | `manage_leads` |
| `GET` `POST` `PATCH` `DELETE` | `/admin/email/sequences/{id}/steps` | `manage_leads` |
| `POST` | `/admin/email/steps/{id}/generate` | `manage_leads` |
| `POST` | `/admin/email/steps/{id}/approve` | `manage_leads` |
| `POST` | `/admin/email/steps/{id}/preview` | `manage_leads` |
| `GET` | `/admin/email/log` | `manage_leads` |
| `GET` | `/admin/integrations` | `manage_integrations` |
| `POST` | `/admin/integrations/{provider}/connect` | `manage_integrations` |
| `GET` | `/admin/integrations/{provider}/callback` | `manage_integrations` |
| `POST` | `/admin/integrations/{provider}/test` | `manage_integrations` |
| `DELETE` | `/admin/integrations/{provider}` | `manage_integrations` |
| `GET` `PUT` | `/admin/integrations/{provider}/mapping` | `manage_integrations` |
| `GET` | `/admin/integrations/{provider}/fields` | `manage_integrations` |
| `GET` | `/admin/integrations/log` | `manage_integrations` |
| `GET` | `/admin/analytics/overview` | `view_conversations` |
| `GET` | `/admin/analytics/agents` | `view_conversations` |
| `GET` | `/admin/analytics/funnel` | `view_conversations` |
| `GET` | `/admin/analytics/topics` | `view_conversations` |
| `GET` | `/admin/analytics/costs` | `view_conversations` |
| `GET` | `/admin/analytics/export` | `view_conversations` |
| `GET` `PUT` | `/admin/settings` | `manage_settings` |
| `GET` `PUT` | `/admin/settings/providers` | `manage_settings` |
| `POST` | `/admin/settings/providers/{p}/verify` | `manage_settings` |
| `GET` | `/admin/settings/providers/{p}/models` | `manage_settings` |
| `GET` `POST` `DELETE` | `/admin/settings/licence` | `manage_settings` |
| `GET` `PUT` | `/admin/settings/privacy` | `manage_settings` |
| `GET` `PUT` | `/admin/settings/branding` | `manage_settings` |
| `GET` | `/admin/settings/audit-log` | `manage_settings` |
| `POST` | `/admin/settings/purge` | `manage_settings` |

**API key handling.** `PUT /admin/settings/providers` accepts a plaintext key over HTTPS, encrypts it immediately, and never returns it. `GET` responds with `{ "provider": "anthropic", "is_set": true, "masked": "sk-ant-…4f2a", "verified_at": "…" }`. There is no endpoint that returns a decrypted key — satisfying FR-SYS-03.

### 3.7 Onboarding

| Method | Route |
|---|---|
| `GET` | `/admin/onboarding/state` |
| `POST` | `/admin/onboarding/step/{n}` |
| `POST` | `/admin/onboarding/detect` |
| `POST` | `/admin/onboarding/complete` |
| `POST` | `/admin/onboarding/skip` |

`/detect` scans for `sitemap.xml`, WooCommerce, public post types, and existing FAQ pages, returning suggested sources — the mechanism behind FR-ONB-04 and the 10-minute activation target.

### 3.8 Workflows

| Method | Route |
|---|---|
| `GET` `POST` | `/admin/workflows` |
| `GET` | `/admin/workflows/vocabulary` |
| `GET` `PATCH` `DELETE` | `/admin/workflows/{uuid}` |
| `POST` | `/admin/workflows/{uuid}/activate` · `/pause` |
| `POST` | `/admin/workflows/{uuid}/test` |
| `GET` | `/admin/workflows/{uuid}/runs` |
| `GET` | `/admin/workflows/runs/{id}` |

Every route requires `hiveclerk_manage_workflows`, which only administrators hold by default — a workflow can reach a CRM, a webhook endpoint and a mailing list, so the builder is a superset of three capabilities the role map otherwise hands out separately (FR-WFL-08).

`/vocabulary` returns triggers, actions, condition fields, operators, stages, sequences and placeholders in one response. The builder needs all of it before it can draw a single node, and five round trips on a screen with a spinner is how a fast product feels slow.

`/activate` returns `422` with a `blockers` array naming each step that is not ready and why. The reason is the contract, not the status: "validation failed" sends an operator back to a screen with nine steps on it and no idea which one is wrong.

`/test` is a dry run. Conditions are evaluated against the named lead for real; actions are described and not performed. The response carries `executed: false`.

Writes are gated on the Workflows entitlement; reads are not. A site whose licence has lapsed can still see what its workflows did and why a lead received what it received.

---

## 4. Webhooks (outbound)

Configured under Integrations. Signed with `X-HVC-Signature: sha256=…` (HMAC over the raw body) plus `X-HVC-Timestamp` for replay protection.

| Event | Fires when |
|---|---|
| `conversation.started` | First visitor message |
| `conversation.ended` | Closed or timed out |
| `conversation.handoff_requested` | Human requested |
| `lead.captured` | Email first resolved |
| `lead.qualified` | Score crosses the qualified threshold |
| `lead.stage_changed` | Pipeline stage moved |
| `knowledge.sync_completed` | Source finished indexing |
| `knowledge.gap_detected` | Query with no confident retrieval |
| `workflow.{name}` | A workflow's webhook action fires; the `workflow.` prefix is not optional, so an automation cannot impersonate one of the events above |

Retries: 5 attempts with exponential backoff (1 m, 5 m, 30 m, 2 h, 12 h). Non-2xx responses are logged to `integration_log`.

---

## 5. Internal Service Interfaces

Not HTTP. These are the PHP contracts the REST layer orchestrates.

```php
interface AiServiceInterface {
    public function complete(CompletionRequest $r): CompletionResponse;
    public function stream(CompletionRequest $r, callable $onDelta): CompletionResponse;
    public function countTokens(string $text, string $model): int;
    public function estimateCost(int $in, int $out, string $model): float;
}

interface EmbeddingServiceInterface {
    /** @param string[] $texts @return Embedding[] */
    public function embedBatch(array $texts, ?string $model = null): array;
    public function embedQuery(string $text): Embedding;
    public function dimensions(?string $model = null): int;
}

interface RetrievalServiceInterface {
    /** @param int[] $sourceIds @return RetrievedChunk[] */
    public function retrieve(string $query, array $sourceIds, RetrievalOptions $o): array;
    public function explain(string $query, array $sourceIds): RetrievalDiagnostics;
}

interface VectorStoreInterface {          // the SaaS swap point
    public function upsert(int $chunkId, Embedding $e): void;
    public function delete(int $chunkId): void;
    /** @return ScoredChunk[] */
    public function search(Embedding $query, array $sourceIds, int $k): array;
    public function rebuildIndex(array $sourceIds): void;
}

interface CrmConnectorInterface {
    public function id(): string;
    public function authenticate(array $credentials): AuthResult;
    public function test(): TestResult;
    public function pushContact(Lead $lead, FieldMap $map): SyncResult;
    public function pushActivity(Lead $lead, Activity $a): SyncResult;
    public function availableFields(): array;
}

interface EmailServiceInterface {
    public function send(EmailMessage $m): SendResult;
    public function generateCopy(Lead $l, Conversation $c, string $goal): EmailDraft;
    public function enrol(Lead $l, EmailSequence $s): Enrollment;
    public function tick(): int;   // advances due enrollments, returns count sent
}

interface QueueInterface {
    public function enqueue(string $hook, array $args = [], string $group = ''): int;
    public function schedule(string $hook, int $timestamp, array $args = []): int;
    public function cancel(string $hook, array $args = []): void;
    public function pending(string $group = ''): int;
}
```

**Every one of these is bound in the container and swappable.** `VectorStoreInterface` and `AiServiceInterface` in particular are the two seams along which the V3 SaaS extraction happens — see Deliverable 6 §15.

---

## 6. Versioning and Deprecation

- Namespace is `hiveclerk/v1`. Breaking changes create `hiveclerk/v2`; both run in parallel for at least two minor releases.
- Additive changes (new optional fields, new endpoints) ship within `v1`.
- Deprecated endpoints return `X-HVC-Deprecated: true` and `Sunset: <RFC 8594 date>`.
- The widget pins the API version it was built against and degrades gracefully on mismatch.

## 7. Extensibility Hooks

Third-party developers extend Hiveclerk without forking:

```php
// Filters
apply_filters('hiveclerk/agent/system_prompt', string $prompt, Agent $a, Context $c);
apply_filters('hiveclerk/retrieval/results', array $chunks, string $query, Agent $a);
apply_filters('hiveclerk/lead/score', int $score, Lead $l, array $breakdown);
apply_filters('hiveclerk/widget/config', array $config, Agent $a);
apply_filters('hiveclerk/providers', array $providers);
apply_filters('hiveclerk/crm/connectors', array $connectors);

// Actions
do_action('hiveclerk/conversation/started', Conversation $c);
do_action('hiveclerk/conversation/ended', Conversation $c);
do_action('hiveclerk/lead/captured', Lead $l, Conversation $c);
do_action('hiveclerk/lead/qualified', Lead $l);
do_action('hiveclerk/knowledge/indexed', KnowledgeSource $s, int $chunks);
```

Registering a custom CRM connector or model provider is a single `add_filter` call — the foundation for the V3 marketplace.

---

**Approval:** ⬜ Awaiting sign-off · Reviewer: ______________ · Date: __________
