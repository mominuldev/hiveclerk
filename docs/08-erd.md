# Hiveclerk — Entity Relationship Diagram

**Deliverable 8 of 16** · Version 1.0 · Status: **Draft — awaiting approval** · 2026-08-05

Diagrams render natively on GitHub and in any Mermaid-capable viewer.

> **Note on notation.** Relationships shown are *logical*. As decided in Deliverable 7 §1.1, no `FOREIGN KEY` constraints exist at the database level — integrity is enforced in the repository layer.

---

## 1. Master ERD

```mermaid
erDiagram
    AGENTS                ||--o{ AGENT_SOURCES        : "indexes"
    KNOWLEDGE_SOURCES     ||--o{ AGENT_SOURCES        : "assigned to"
    KNOWLEDGE_SOURCES     ||--o{ DOCUMENTS            : "contains"
    DOCUMENTS             ||--o{ CHUNKS               : "split into"
    CHUNKS                ||--o{ EMBEDDINGS           : "vectorised as"

    AGENTS                ||--o{ CONVERSATIONS        : "handles"
    VISITORS              ||--o{ CONVERSATIONS        : "starts"
    VISITORS              ||--o{ SESSIONS             : "authenticated by"
    CONVERSATIONS         ||--o{ MESSAGES             : "contains"
    MESSAGES              ||--o{ MESSAGE_CITATIONS    : "cites"
    CHUNKS                ||--o{ MESSAGE_CITATIONS    : "cited by"

    LEADS                 ||--o{ CONVERSATIONS        : "originates"
    LEADS                 ||--o{ LEAD_SCORES          : "scored by"
    LEADS                 ||--o{ ACTIVITIES           : "timeline of"
    LEAD_STAGES           ||--o{ LEADS                : "positions"
    VISITORS              |o--o| LEADS                : "resolves to"

    EMAIL_SEQUENCES       ||--o{ SEQUENCE_STEPS       : "composed of"
    EMAIL_SEQUENCES       ||--o{ SEQUENCE_ENROLLMENTS : "enrols"
    LEADS                 ||--o{ SEQUENCE_ENROLLMENTS : "enrolled in"
    SEQUENCE_ENROLLMENTS  ||--o{ EMAIL_LOG            : "produces"
    SEQUENCE_STEPS        ||--o{ EMAIL_LOG            : "rendered as"

    INTEGRATIONS          ||--o{ INTEGRATION_LOG      : "records"
    LEADS                 ||--o{ INTEGRATION_LOG      : "synced via"

    AGENTS                ||--o{ USAGE_EVENTS         : "consumes"
    CONVERSATIONS         ||--o{ USAGE_EVENTS         : "attributed to"
    AGENTS                ||--o{ ANALYTICS_DAILY      : "rolled up into"
    AGENTS                ||--o{ UNANSWERED           : "surfaces gaps in"
    CONVERSATIONS         ||--o{ UNANSWERED           : "detected in"
```

---

## 2. Module ERDs

### 2.1 Knowledge Base

```mermaid
erDiagram
    KNOWLEDGE_SOURCES {
        bigint   id PK
        char36   uuid UK
        varchar  name
        varchar  type          "wp_content|crawl|pdf|docx|faq|text"
        varchar  status        "pending|processing|ready|error"
        json     config
        varchar  embed_provider
        varchar  embed_model
        smallint embed_dimensions
        int      chunk_count
        varchar  sync_schedule
        datetime last_synced_at
        datetime next_sync_at
    }
    DOCUMENTS {
        bigint   id PK
        bigint   source_id FK
        varchar  external_id
        varchar  url
        varchar  title
        longtext content
        char64   content_hash  "drives re-embed skipping"
        json     metadata
        int      token_count
    }
    CHUNKS {
        bigint    id PK
        bigint    document_id FK
        bigint    source_id FK  "denormalised for retrieval scope"
        int       chunk_index
        text      content       "FULLTEXT indexed"
        char64    content_hash
        varchar   heading_path
        smallint  token_count
    }
    EMBEDDINGS {
        bigint    id PK
        bigint    chunk_id FK
        bigint    source_id FK  "denormalised for matrix load"
        varchar   provider
        varchar   model
        smallint  dimensions
        longblob  embedding_f32 "Stage 2 exact"
        varbinary embedding_bits "Stage 1 scan, 192B"
        float     norm
    }

    KNOWLEDGE_SOURCES ||--o{ DOCUMENTS  : contains
    DOCUMENTS         ||--o{ CHUNKS     : "split into"
    CHUNKS            ||--o{ EMBEDDINGS : "vectorised as"
```

**Why four tables and not two.** Each level has a distinct lifecycle: a *source* is reconfigured, a *document* is re-fetched, a *chunk* is re-split, an *embedding* is regenerated when the provider changes. Collapsing them would force full re-embedding on any change — the single largest avoidable cost in the product.

### 2.2 Conversations

```mermaid
erDiagram
    VISITORS {
        bigint   id PK
        char36   uuid UK
        bigint   wp_user_id
        bigint   lead_id FK
        char64   ip_hash       "hashed, never raw"
        char2    country
        varchar  language
        int      page_views
        datetime first_seen_at
        datetime last_seen_at
    }
    SESSIONS {
        bigint   id PK
        char36   uuid UK
        bigint   visitor_id FK
        bigint   conversation_id FK
        char64   token_hash UK
        varchar  transport     "sse|poll"
        datetime expires_at
    }
    CONVERSATIONS {
        bigint   id PK
        char36   uuid UK
        bigint   agent_id FK
        bigint   visitor_id FK
        bigint   lead_id FK
        varchar  status
        varchar  language
        varchar  page_url
        smallint message_count
        text     summary
        varchar  sentiment
        tinyint  resolved_by_ai
        bigint   handoff_user_id
        decimal  total_cost
        datetime purge_after
    }
    MESSAGES {
        bigint   id PK
        char36   uuid UK
        bigint   conversation_id FK
        varchar  role          "visitor|assistant|system|human_agent"
        longtext content
        varchar  provider
        varchar  model
        int      tokens_in
        int      tokens_out
        decimal  cost
        int      latency_ms
        decimal  retrieval_score
        tinyint  is_grounded
        json     guardrail_flags
        tinyint  rating
    }
    MESSAGE_CITATIONS {
        bigint  id PK
        bigint  message_id FK
        bigint  chunk_id FK   "nullable if chunk deleted"
        bigint  document_id FK
        decimal score
        tinyint rank_order
        json    snapshot      "survives re-indexing"
    }

    VISITORS      ||--o{ SESSIONS          : "authenticated by"
    VISITORS      ||--o{ CONVERSATIONS     : starts
    CONVERSATIONS ||--o{ MESSAGES          : contains
    MESSAGES      ||--o{ MESSAGE_CITATIONS : cites
```

### 2.3 Leads

```mermaid
erDiagram
    LEAD_STAGES {
        bigint   id PK
        varchar  name
        varchar  slug UK
        smallint position
        tinyint  is_won
        tinyint  is_lost
    }
    LEADS {
        bigint   id PK
        char36   uuid UK
        varchar  email
        char64   email_hash UK "dedup without plaintext index"
        varchar  first_name
        varchar  last_name
        varchar  phone
        varchar  company
        bigint   stage_id FK
        smallint score
        varchar  score_band    "cold|warm|hot|qualified"
        varchar  status
        json     custom_fields "qualification answers"
        json     consent
        bigint   owner_user_id
    }
    LEAD_SCORES {
        bigint   id PK
        bigint   lead_id FK
        bigint   conversation_id FK
        varchar  rule_id
        varchar  rule_label
        varchar  source        "rule|ai|manual"
        smallint points
        smallint score_after
        text     rationale     "required when source=ai"
        datetime created_at
    }
    ACTIVITIES {
        bigint   id PK
        bigint   lead_id FK
        bigint   visitor_id FK
        varchar  type
        varchar  subject_type  "polymorphic"
        bigint   subject_id
        varchar  title
        json     metadata
        datetime created_at
    }

    LEAD_STAGES ||--o{ LEADS       : positions
    LEADS       ||--o{ LEAD_SCORES : "scored by"
    LEADS       ||--o{ ACTIVITIES  : "timeline of"
```

**`LEAD_SCORES` is append-only.** A score is never updated in place; each adjustment is a new row carrying its own rationale. `LEADS.score` is the materialised running total. This is what makes the score breakdown auditable for persona P3's sceptical sales team.

### 2.4 Email Automation

```mermaid
erDiagram
    EMAIL_SEQUENCES {
        bigint  id PK
        char36  uuid UK
        varchar name
        varchar status        "draft|active|paused"
        varchar trigger_type
        json    trigger_config
        json    exit_conditions
    }
    SEQUENCE_STEPS {
        bigint   id PK
        bigint   sequence_id FK
        smallint position
        int      delay_minutes
        varchar  subject
        longtext body_html
        tinyint  ai_generated
        bigint   approved_by   "human approval gate"
        json     conditions
    }
    SEQUENCE_ENROLLMENTS {
        bigint   id PK
        bigint   sequence_id FK
        bigint   lead_id FK
        varchar  status
        smallint current_step
        datetime next_send_at
        varchar  exit_reason
    }
    EMAIL_LOG {
        bigint   id PK
        bigint   enrollment_id FK
        bigint   step_id FK
        bigint   lead_id FK
        varchar  message_id    "RFC Message-ID for reply matching"
        varchar  to_email
        varchar  status
        datetime sent_at
        datetime opened_at
        datetime clicked_at
    }
    SUPPRESSIONS {
        bigint  id PK
        char64  email_hash UK
        varchar reason
    }

    EMAIL_SEQUENCES      ||--o{ SEQUENCE_STEPS       : "composed of"
    EMAIL_SEQUENCES      ||--o{ SEQUENCE_ENROLLMENTS : enrols
    SEQUENCE_ENROLLMENTS ||--o{ EMAIL_LOG            : produces
    SEQUENCE_STEPS       ||--o{ EMAIL_LOG            : "rendered as"
```

### 2.5 Integrations and Platform

```mermaid
erDiagram
    INTEGRATIONS {
        bigint   id PK
        varchar  provider UK
        varchar  status
        text     credentials   "AES-256-GCM ciphertext"
        datetime token_expires_at
        json     field_mapping
        json     sync_config
        int      error_count
    }
    INTEGRATION_LOG {
        bigint   id PK
        bigint   integration_id FK
        bigint   lead_id FK
        varchar  operation
        varchar  status
        tinyint  attempt
        varchar  external_id
        json     request_summary "redacted"
        datetime next_retry_at
    }
    USAGE_EVENTS {
        bigint   id PK
        bigint   agent_id FK
        bigint   conversation_id FK
        varchar  kind          "completion|embedding|summary|scoring"
        varchar  provider
        varchar  model
        int      tokens_in
        int      tokens_out
        decimal  cost
        datetime occurred_at
    }
    ANALYTICS_DAILY {
        bigint  id PK
        date    date
        bigint  agent_id FK   "NULL = site-wide"
        int     conversations
        int     leads_captured
        int     handoffs
        int     resolved_by_ai
        int     unanswered
        decimal cost
    }
    UNANSWERED {
        bigint   id PK
        bigint   agent_id FK
        bigint   conversation_id FK
        varchar  query
        char64   query_hash
        decimal  best_score
        int      occurrences
        varchar  status
    }
    AUDIT_LOG {
        bigint   id PK
        bigint   wp_user_id
        varchar  action
        varchar  object_type
        bigint   object_id
        json     changes       "secrets redacted"
        char64   ip_hash
    }

    INTEGRATIONS ||--o{ INTEGRATION_LOG : records
    AGENTS       ||--o{ USAGE_EVENTS    : consumes
    AGENTS       ||--o{ ANALYTICS_DAILY : "rolled up into"
    AGENTS       ||--o{ UNANSWERED      : "surfaces gaps in"
```

---

## 3. Cardinality Reference

| From | To | Cardinality | Delete behaviour |
|---|---|---|---|
| `agents` | `conversations` | 1 : N | Restrict — archive the agent instead |
| `agents` | `agent_sources` | 1 : N | Cascade |
| `knowledge_sources` | `documents` | 1 : N | Cascade |
| `documents` | `chunks` | 1 : N | Cascade |
| `chunks` | `embeddings` | 1 : N | Cascade |
| `chunks` | `message_citations` | 1 : N | **Set NULL** — snapshot preserves the citation |
| `visitors` | `conversations` | 1 : N | Cascade |
| `visitors` | `leads` | 1 : 0..1 | Set NULL |
| `conversations` | `messages` | 1 : N | Cascade |
| `messages` | `message_citations` | 1 : N | Cascade |
| `leads` | `lead_scores` | 1 : N | Cascade |
| `leads` | `activities` | 1 : N | Cascade |
| `lead_stages` | `leads` | 1 : N | Restrict — reassign before deleting a stage |
| `email_sequences` | `sequence_steps` | 1 : N | Cascade |
| `email_sequences` | `sequence_enrollments` | 1 : N | Cascade |
| `sequence_enrollments` | `email_log` | 1 : N | **Set NULL** — retain send history |
| `integrations` | `integration_log` | 1 : N | Cascade |

`CascadeDeleteService` implements this table explicitly, ordering deletes children-first inside a transaction.

---

## 4. Hot Paths and Index Justification

The three queries that must never be slow:

### 4.1 Retrieval Stage 1 — every visitor message

```sql
SELECT id, chunk_id, embedding_bits
  FROM wp_hvc_embeddings
 WHERE source_id IN (?, ?, ?)
 ORDER BY id;
```
Served by `idx_source_scan (source_id, id)`. Result is cached as a packed binary matrix; the query runs on cache miss only.

### 4.2 Conversation transcript — admin detail view

```sql
SELECT * FROM wp_hvc_messages
 WHERE conversation_id = ?
 ORDER BY created_at ASC;
```
Served by `idx_conversation (conversation_id, created_at)` — index order matches sort order, so no filesort.

### 4.3 Dashboard trend — most-loaded admin screen

```sql
SELECT date, SUM(conversations), SUM(leads_captured), SUM(cost)
  FROM wp_hvc_analytics_daily
 WHERE date BETWEEN ? AND ?
 GROUP BY date;
```
Served by `idx_date`. **This is precisely why `analytics_daily` exists** — the equivalent query against `messages` and `usage_events` would scan hundreds of thousands of rows on every dashboard load.

---

## 5. Volume Projection

| Entity | Small site / yr | Mid store / yr | Agency site / yr |
|---|---:|---:|---:|
| Conversations | 600 | 20,000 | 60,000 |
| Messages | 3,600 | 120,000 | 360,000 |
| Citations | 9,000 | 300,000 | 900,000 |
| Chunks | 400 | 10,000 | 50,000 |
| Embeddings | 400 | 10,000 | 50,000 |
| Leads | 120 | 3,000 | 9,000 |
| Activities | 6,000 | 200,000 | 600,000 |
| **DB growth** | **~30 MB** | **~560 MB** | **~1.6 GB** |

The Agency column is where retention policy stops being optional. Default 12-month retention with nightly purge keeps steady-state size bounded rather than monotonically growing.

---

**Approval:** ⬜ Awaiting sign-off · Reviewer: ______________ · Date: __________
