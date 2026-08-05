# Hiveclerk — Database Schema

**Deliverable 7 of 16** · Version 1.0 · Status: **Draft — awaiting approval** · 2026-08-05

27 tables. All prefixed `{$wpdb->prefix}hvc_`. Shown below with the default `wp_` prefix.

---

## 1. Conventions

| Convention | Decision | Rationale |
|---|---|---|
| Engine | `InnoDB` | Transactions, row-level locking |
| Charset | `utf8mb4` / `utf8mb4_unicode_520_ci` | Full Unicode incl. emoji; matches WordPress default |
| Row format | `DYNAMIC` | Required for long indexes and large BLOBs |
| Primary keys | `BIGINT UNSIGNED AUTO_INCREMENT` | Conversation/message volume can exceed INT range |
| Indexed strings | `VARCHAR(191)` max | Safe under the 3072-byte InnoDB index limit on utf8mb4 |
| Timestamps | `DATETIME` in **UTC**, never `TIMESTAMP` | `TIMESTAMP` has a 2038 limit and timezone coercion |
| Booleans | `TINYINT(1) UNSIGNED` | No native boolean in MySQL |
| Flexible config | `JSON` columns | MySQL 8+ native; validated in the domain layer |
| Public identifiers | `CHAR(36)` UUID v4 | Never expose auto-increment IDs to browsers |
| Money | `DECIMAL(12,6)` | Model costs are fractions of a cent; floats would drift |
| Unknown money | `NULL`, never `0` | A model with no published price — a preview release, a brokered model, a customer's own fine-tune — must record *no cost*, not a free one. Summed across a month, a zero understates spend in the direction nobody audits. `usage_events.cost` shipped `NOT NULL DEFAULT 0` and was corrected by migration `M0008`; the summary layer counts and reports unpriced calls separately |

### 1.1 No database-level foreign keys — deliberate

`dbDelta()` cannot manage `FOREIGN KEY` constraints, and many managed WordPress hosts run MySQL configurations where a failed constraint produces an opaque fatal error during activation. Referential integrity is enforced in the **repository layer**, and cascade deletion is performed explicitly by `CascadeDeleteService` inside a transaction.

**Trade-off accepted:** we lose database-enforced integrity in exchange for reliable activation across the hosting landscape. Orphan-detection runs as a weekly maintenance job and reports to the system status page.

### 1.2 Migrations — versioned runner, no dbDelta at all

**`dbDelta()` is not used anywhere.** The original plan was to use it for initial creation only; implementation showed that is not safe either. `dbDelta()` silently mangles two column types this schema depends on — `VARBINARY(256)` for quantised embeddings and the `FULLTEXT` index on `chunks.content` — and it cannot drop columns, rename, or alter indexes reliably afterwards.

Every schema change runs through a versioned migration runner executing plain `CREATE TABLE IF NOT EXISTS`:

```
hiveclerk_db_version  (option)  →  M0001_Agents         (agents, agent_sources)
                                   M0002_Knowledge      (sources, documents, chunks, embeddings)
                                   M0003_Conversations  (visitors, sessions, conversations,
                                                         messages, message_citations)
                                   M0004_Leads          (stages, leads, scores, activities)
                                   M0005_Email          (sequences, steps, enrollments,
                                                         log, suppressions)
                                   M0006_Integrations   (integrations, integration_log)
                                   M0007_Platform       (usage, analytics, unanswered,
                                                         audit, rate_limits)
```

Each migration is idempotent, declares `up()` and `down()`, and is applied in version order under a short-lived lock so two concurrent requests cannot run the same migration twice. On failure the version stays at the last migration that succeeded, so the next request retries from there rather than skipping the broken step.

**Migrations do not run during activation.** Activation has a short execution budget and a failure there leaves the plugin half-installed with no way to report why. They run on `admin_init` instead, where there is a request to report into and a next request to retry from.

---

## 2. Table Group A — Agents (Clerks)

### 2.1 `wp_hvc_agents`

```sql
CREATE TABLE wp_hvc_agents (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid              CHAR(36)        NOT NULL,
  name              VARCHAR(191)    NOT NULL,
  slug              VARCHAR(191)    NOT NULL,
  role_preset       VARCHAR(32)     NOT NULL DEFAULT 'support',
      -- support | sales | qualifier | faq | concierge | custom
  status            VARCHAR(20)     NOT NULL DEFAULT 'draft',
      -- draft | published | paused | archived
  avatar_url        VARCHAR(500)        NULL,
  greeting          TEXT                NULL,
  fallback_message  TEXT                NULL,
  instructions      LONGTEXT            NULL,   -- the "job description"
  personality       JSON                NULL,   -- {tone, formality, emoji, verbosity}
  guardrails        JSON                NULL,   -- {banned_topics[], disclaimers[],
                                                --  no_invent_facts, max_reply_chars,
                                                --  confidence_threshold}
  model_config      JSON            NOT NULL,   -- {provider, model, temperature,
                                                --  max_tokens, top_p}
  retrieval_config  JSON                NULL,   -- {top_k, min_score, hybrid_weight}
  display_rules     JSON                NULL,   -- {urls[], exclude_urls[], devices[],
                                                --  user_roles[], countries[], delay_ms}
  widget_config     JSON                NULL,   -- {colors, position, radius, launcher}
  lead_config       JSON                NULL,   -- {capture_fields[], qualify_questions[]}
  token_budget      INT UNSIGNED        NULL,   -- monthly cap; NULL = unlimited
  tokens_used_month INT UNSIGNED    NOT NULL DEFAULT 0,
  budget_reset_at   DATETIME            NULL,
  created_by        BIGINT UNSIGNED     NULL,   -- wp_users.ID
  created_at        DATETIME        NOT NULL,
  updated_at        DATETIME        NOT NULL,
  deleted_at        DATETIME            NULL,   -- soft delete
  PRIMARY KEY  (id),
  UNIQUE KEY uq_uuid (uuid),
  UNIQUE KEY uq_slug (slug),
  KEY idx_status (status, deleted_at),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC;
```

**Why JSON for config.** Agent configuration evolves every release. Normalising `personality`, `guardrails`, and `display_rules` into tables would mean a migration per feature. These fields are read as a whole, never queried by sub-field, so JSON is the correct trade. Fields that *are* queried (`status`, `token_budget`) remain real columns.

### 2.2 `wp_hvc_agent_sources`

```sql
CREATE TABLE wp_hvc_agent_sources (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent_id   BIGINT UNSIGNED NOT NULL,
  source_id  BIGINT UNSIGNED NOT NULL,
  priority   SMALLINT        NOT NULL DEFAULT 0,
  created_at DATETIME        NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_agent_source (agent_id, source_id),
  KEY idx_source (source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
```

---

## 3. Table Group B — Knowledge Base

### 3.1 `wp_hvc_knowledge_sources`

```sql
CREATE TABLE wp_hvc_knowledge_sources (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid              CHAR(36)        NOT NULL,
  name              VARCHAR(191)    NOT NULL,
  type              VARCHAR(32)     NOT NULL,
      -- wp_content | woo_products | website_crawl | pdf | docx | faq | text
  status            VARCHAR(20)     NOT NULL DEFAULT 'pending',
      -- pending | processing | ready | error | needs_reembedding
  config            JSON            NOT NULL,
      -- wp_content:    {post_types[], taxonomies{}, statuses[]}
      -- website_crawl: {start_url, max_pages, respect_robots, include[], exclude[]}
      -- pdf/docx:      {attachment_id, filename, filesize}
  embed_provider    VARCHAR(32)         NULL,   -- pinned at index time
  embed_model       VARCHAR(64)         NULL,
  embed_dimensions  SMALLINT UNSIGNED   NULL,
  document_count    INT UNSIGNED    NOT NULL DEFAULT 0,
  chunk_count       INT UNSIGNED    NOT NULL DEFAULT 0,
  token_count       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  sync_schedule     VARCHAR(20)     NOT NULL DEFAULT 'manual',
      -- manual | on_save | daily | weekly
  last_synced_at    DATETIME            NULL,
  next_sync_at      DATETIME            NULL,
  last_error        TEXT                NULL,
  progress          JSON                NULL,   -- {current, total, stage}
  created_at        DATETIME        NOT NULL,
  updated_at        DATETIME        NOT NULL,
  deleted_at        DATETIME            NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_uuid (uuid),
  KEY idx_status (status, deleted_at),
  KEY idx_next_sync (next_sync_at, sync_schedule)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC;
```

### 3.2 `wp_hvc_documents`

```sql
CREATE TABLE wp_hvc_documents (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_id      BIGINT UNSIGNED NOT NULL,
  external_id    VARCHAR(191)        NULL,   -- wp post ID, product ID, URL hash
  url            VARCHAR(500)        NULL,
  title          VARCHAR(500)        NULL,
  content        LONGTEXT            NULL,   -- normalised plain text
  content_hash   CHAR(64)        NOT NULL,   -- SHA-256, drives re-embed skipping
  language       VARCHAR(10)         NULL,
  metadata       JSON                NULL,   -- {post_type, terms[], price, sku, author}
  token_count    INT UNSIGNED    NOT NULL DEFAULT 0,
  chunk_count    INT UNSIGNED    NOT NULL DEFAULT 0,
  status         VARCHAR(20)     NOT NULL DEFAULT 'pending',
  indexed_at     DATETIME            NULL,
  created_at     DATETIME        NOT NULL,
  updated_at     DATETIME        NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_source (source_id, status),
  UNIQUE KEY uq_source_external (source_id, external_id),
  KEY idx_hash (content_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC;
```

### 3.3 `wp_hvc_chunks`

```sql
CREATE TABLE wp_hvc_chunks (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  document_id   BIGINT UNSIGNED NOT NULL,
  source_id     BIGINT UNSIGNED NOT NULL,   -- denormalised: scopes retrieval without a join
  chunk_index   INT UNSIGNED    NOT NULL,
  content       TEXT            NOT NULL,
  content_hash  CHAR(64)        NOT NULL,
  heading_path  VARCHAR(500)        NULL,   -- "Shipping > International > EU"
  token_count   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  char_start    INT UNSIGNED        NULL,
  char_end      INT UNSIGNED        NULL,
  created_at    DATETIME        NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_document (document_id, chunk_index),
  KEY idx_source (source_id),
  KEY idx_hash (content_hash),
  FULLTEXT KEY ft_content (content)          -- Stage 3 BM25 for hybrid retrieval
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC;
```

**`source_id` is intentionally denormalised.** Retrieval scopes by the agent's assigned sources on every query; carrying it here removes a join from the hottest path in the product.

### 3.4 `wp_hvc_embeddings`

```sql
CREATE TABLE wp_hvc_embeddings (
  id              BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  chunk_id        BIGINT UNSIGNED   NOT NULL,
  source_id       BIGINT UNSIGNED   NOT NULL,   -- denormalised for matrix loading
  provider        VARCHAR(32)       NOT NULL,
  model           VARCHAR(64)       NOT NULL,
  dimensions      SMALLINT UNSIGNED NOT NULL,
  embedding_f32   LONGBLOB          NOT NULL,   -- packed float32 — Stage 2 only
  embedding_bits  VARBINARY(256)    NOT NULL,   -- 1 bit/dim — Stage 1 scan
  norm            FLOAT             NOT NULL,   -- precomputed L2 norm
  created_at      DATETIME          NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_chunk_model (chunk_id, provider, model),
  KEY idx_source_scan (source_id, id)           -- covering scan for Stage 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC;
```

**Separate from `chunks` for three reasons:** re-embedding with a new provider does not rewrite chunk text; a chunk may hold multiple embeddings during a provider migration; and Stage 1 scans only this table, keeping the hot rows narrow.

---

## 4. Table Group C — Conversations

### 4.1 `wp_hvc_visitors`

```sql
CREATE TABLE wp_hvc_visitors (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid            CHAR(36)        NOT NULL,
  wp_user_id      BIGINT UNSIGNED     NULL,
  lead_id         BIGINT UNSIGNED     NULL,   -- set when identity is resolved
  fingerprint     CHAR(64)            NULL,
  ip_hash         CHAR(64)            NULL,   -- hashed, never raw (GDPR)
  user_agent      VARCHAR(500)        NULL,
  country         CHAR(2)             NULL,
  language        VARCHAR(10)         NULL,
  first_seen_at   DATETIME        NOT NULL,
  last_seen_at    DATETIME        NOT NULL,
  page_views      INT UNSIGNED    NOT NULL DEFAULT 0,
  session_count   INT UNSIGNED    NOT NULL DEFAULT 1,
  metadata        JSON                NULL,   -- {referrer, utm{}, landing_page}
  PRIMARY KEY  (id),
  UNIQUE KEY uq_uuid (uuid),
  KEY idx_lead (lead_id),
  KEY idx_wp_user (wp_user_id),
  KEY idx_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC;
```

### 4.2 `wp_hvc_conversations`

```sql
CREATE TABLE wp_hvc_conversations (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid              CHAR(36)        NOT NULL,
  agent_id          BIGINT UNSIGNED NOT NULL,
  visitor_id        BIGINT UNSIGNED     NULL,
  lead_id           BIGINT UNSIGNED     NULL,
  status            VARCHAR(20)     NOT NULL DEFAULT 'active',
      -- active | ended | handoff_requested | handoff_active | resolved | abandoned
  channel           VARCHAR(20)     NOT NULL DEFAULT 'widget',
  language          VARCHAR(10)         NULL,
  page_url          VARCHAR(500)        NULL,
  page_title        VARCHAR(500)        NULL,
  message_count     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  summary           TEXT                NULL,   -- AI-generated
  sentiment         VARCHAR(20)         NULL,   -- positive | neutral | negative
  sentiment_score   DECIMAL(4,3)        NULL,
  resolved_by_ai    TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  handoff_user_id   BIGINT UNSIGNED     NULL,
  handoff_at        DATETIME            NULL,
  rating            TINYINT             NULL,   -- -1 | 0 | 1
  tags              JSON                NULL,
  total_tokens_in   INT UNSIGNED    NOT NULL DEFAULT 0,
  total_tokens_out  INT UNSIGNED    NOT NULL DEFAULT 0,
  total_cost        DECIMAL(12,6)   NOT NULL DEFAULT 0,
  started_at        DATETIME        NOT NULL,
  last_message_at   DATETIME            NULL,
  ended_at          DATETIME            NULL,
  purge_after       DATETIME            NULL,   -- retention policy target
  PRIMARY KEY  (id),
  UNIQUE KEY uq_uuid (uuid),
  KEY idx_agent_started (agent_id, started_at),
  KEY idx_status (status, started_at),
  KEY idx_lead (lead_id),
  KEY idx_visitor (visitor_id),
  KEY idx_purge (purge_after)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC;
```

### 4.3 `wp_hvc_messages`

```sql
CREATE TABLE wp_hvc_messages (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid             CHAR(36)        NOT NULL,
  conversation_id  BIGINT UNSIGNED NOT NULL,
  role             VARCHAR(16)     NOT NULL,   -- visitor | assistant | system | human_agent
  content          LONGTEXT        NOT NULL,
  content_html     LONGTEXT            NULL,   -- sanitised render cache
  wp_user_id       BIGINT UNSIGNED     NULL,   -- set when role = human_agent
  provider         VARCHAR(32)         NULL,
  model            VARCHAR(64)         NULL,
  tokens_in        INT UNSIGNED    NOT NULL DEFAULT 0,
  tokens_out       INT UNSIGNED    NOT NULL DEFAULT 0,
  cost             DECIMAL(12,6)   NOT NULL DEFAULT 0,
  latency_ms       INT UNSIGNED        NULL,
  retrieval_score  DECIMAL(5,4)        NULL,   -- best chunk score; NULL = no retrieval
  is_grounded      TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  guardrail_flags  JSON                NULL,   -- {blocked, reasons[], filtered}
  rating           TINYINT             NULL,   -- visitor thumbs: -1 | 1
  rating_comment   VARCHAR(500)        NULL,
  created_at       DATETIME        NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_uuid (uuid),
  KEY idx_conversation (conversation_id, created_at),
  KEY idx_created (created_at),
  KEY idx_rating (rating)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC;
```

### 4.4 `wp_hvc_message_citations`

```sql
CREATE TABLE wp_hvc_message_citations (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  message_id  BIGINT UNSIGNED NOT NULL,
  chunk_id    BIGINT UNSIGNED     NULL,   -- NULL if the chunk was later deleted
  document_id BIGINT UNSIGNED     NULL,
  score       DECIMAL(5,4)    NOT NULL,
  rank_order  TINYINT UNSIGNED NOT NULL,
  snapshot    JSON                NULL,   -- {url, title, heading_path, excerpt}
  PRIMARY KEY  (id),
  KEY idx_message (message_id, rank_order),
  KEY idx_chunk (chunk_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC;
```

**`snapshot` preserves the citation** even after the source is re-indexed or deleted, so historical transcripts stay auditable.

---

## 5. Table Group D — Leads

### 5.1 `wp_hvc_leads`

```sql
CREATE TABLE wp_hvc_leads (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid            CHAR(36)        NOT NULL,
  email           VARCHAR(191)        NULL,
  email_hash      CHAR(64)            NULL,   -- for dedup without a plaintext index
  first_name      VARCHAR(100)        NULL,
  last_name       VARCHAR(100)        NULL,
  phone           VARCHAR(50)         NULL,
  company         VARCHAR(191)        NULL,
  job_title       VARCHAR(191)        NULL,
  website         VARCHAR(255)        NULL,
  wp_user_id      BIGINT UNSIGNED     NULL,
  stage_id        BIGINT UNSIGNED     NULL,
  score           SMALLINT        NOT NULL DEFAULT 0,
  score_band      VARCHAR(20)     NOT NULL DEFAULT 'cold',  -- cold | warm | hot | qualified
  status          VARCHAR(20)     NOT NULL DEFAULT 'new',
      -- new | contacted | qualified | unqualified | converted | lost
  source          VARCHAR(50)         NULL,   -- which agent/page captured it
  custom_fields   JSON                NULL,   -- qualification answers
  consent         JSON                NULL,   -- {marketing, timestamp, ip_hash, text}
  owner_user_id   BIGINT UNSIGNED     NULL,
  first_seen_at   DATETIME        NOT NULL,
  last_active_at  DATETIME            NULL,
  converted_at    DATETIME            NULL,
  created_at      DATETIME        NOT NULL,
  updated_at      DATETIME        NOT NULL,
  deleted_at      DATETIME            NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_uuid (uuid),
  UNIQUE KEY uq_email_hash (email_hash),
  KEY idx_score (score_band, score),
  KEY idx_stage (stage_id),
  KEY idx_status (status, created_at),
  KEY idx_owner (owner_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC;
```

### 5.2 `wp_hvc_lead_scores`

```sql
CREATE TABLE wp_hvc_lead_scores (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lead_id         BIGINT UNSIGNED NOT NULL,
  conversation_id BIGINT UNSIGNED     NULL,
  rule_id         VARCHAR(64)         NULL,   -- NULL when source = 'ai'
  rule_label      VARCHAR(191)        NULL,
  source          VARCHAR(20)     NOT NULL DEFAULT 'rule',  -- rule | ai | manual
  points          SMALLINT        NOT NULL,
  score_after     SMALLINT        NOT NULL,
  rationale       TEXT                NULL,   -- required when source = 'ai'
  created_at      DATETIME        NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_lead (lead_id, created_at),
  KEY idx_conversation (conversation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC;
```

**Every score change is an append-only event, never an update.** This is what makes FR-LED-04's "score breakdown with rationale" possible and satisfies persona P3's requirement that sales can audit why a lead scored what it did.

### 5.3 `wp_hvc_lead_stages`

```sql
CREATE TABLE wp_hvc_lead_stages (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(191)    NOT NULL,
  slug          VARCHAR(191)    NOT NULL,
  color         VARCHAR(20)         NULL,
  position      SMALLINT        NOT NULL DEFAULT 0,
  is_won        TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  is_lost       TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  created_at    DATETIME        NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_slug (slug),
  KEY idx_position (position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
```

### 5.4 `wp_hvc_activities`

```sql
CREATE TABLE wp_hvc_activities (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lead_id      BIGINT UNSIGNED     NULL,
  visitor_id   BIGINT UNSIGNED     NULL,
  type         VARCHAR(50)     NOT NULL,
      -- page_view | conversation_started | message_sent | lead_captured
      -- | score_changed | stage_changed | email_sent | email_opened
      -- | email_clicked | crm_synced | note_added | handoff_requested
  subject_type VARCHAR(50)         NULL,   -- polymorphic target
  subject_id   BIGINT UNSIGNED     NULL,
  wp_user_id   BIGINT UNSIGNED     NULL,
  title        VARCHAR(255)    NOT NULL,
  body         TEXT                NULL,
  metadata     JSON                NULL,
  created_at   DATETIME        NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_lead (lead_id, created_at),
  KEY idx_visitor (visitor_id, created_at),
  KEY idx_type (type, created_at),
  KEY idx_subject (subject_type, subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC;
```

---

## 6. Table Group E — Email Automation

### 6.1 `wp_hvc_email_sequences`

```sql
CREATE TABLE wp_hvc_email_sequences (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid            CHAR(36)        NOT NULL,
  name            VARCHAR(191)    NOT NULL,
  status          VARCHAR(20)     NOT NULL DEFAULT 'draft',  -- draft | active | paused
  trigger_type    VARCHAR(50)     NOT NULL,
      -- lead_created | score_threshold | stage_changed
      -- | conversation_abandoned | manual
  trigger_config  JSON                NULL,
  exit_conditions JSON                NULL,   -- {on_reply, on_stage, on_unsubscribe}
  from_name       VARCHAR(191)        NULL,
  from_email      VARCHAR(191)        NULL,
  reply_to        VARCHAR(191)        NULL,
  enrolled_count  INT UNSIGNED    NOT NULL DEFAULT 0,
  created_at      DATETIME        NOT NULL,
  updated_at      DATETIME        NOT NULL,
  deleted_at      DATETIME            NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_uuid (uuid),
  KEY idx_status_trigger (status, trigger_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC;
```

### 6.2 `wp_hvc_sequence_steps`

```sql
CREATE TABLE wp_hvc_sequence_steps (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sequence_id    BIGINT UNSIGNED NOT NULL,
  position       SMALLINT        NOT NULL,
  delay_minutes  INT UNSIGNED    NOT NULL DEFAULT 0,
  subject        VARCHAR(500)    NOT NULL,
  body_html      LONGTEXT        NOT NULL,
  body_text      LONGTEXT            NULL,
  ai_generated   TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  approved_by    BIGINT UNSIGNED     NULL,   -- human approval gate (FR-EML-03)
  approved_at    DATETIME            NULL,
  conditions     JSON                NULL,
  created_at     DATETIME        NOT NULL,
  updated_at     DATETIME        NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_sequence (sequence_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC;
```

### 6.3 `wp_hvc_sequence_enrollments`

```sql
CREATE TABLE wp_hvc_sequence_enrollments (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sequence_id    BIGINT UNSIGNED NOT NULL,
  lead_id        BIGINT UNSIGNED NOT NULL,
  status         VARCHAR(20)     NOT NULL DEFAULT 'active',
      -- active | completed | exited | failed | unsubscribed
  current_step   SMALLINT        NOT NULL DEFAULT 0,
  next_send_at   DATETIME            NULL,
  exit_reason    VARCHAR(100)        NULL,
  enrolled_at    DATETIME        NOT NULL,
  completed_at   DATETIME            NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_sequence_lead (sequence_id, lead_id),
  KEY idx_due (status, next_send_at),
  KEY idx_lead (lead_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
```

### 6.4 `wp_hvc_email_log`

```sql
CREATE TABLE wp_hvc_email_log (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  enrollment_id  BIGINT UNSIGNED     NULL,
  step_id        BIGINT UNSIGNED     NULL,
  lead_id        BIGINT UNSIGNED NOT NULL,
  message_id     VARCHAR(191)        NULL,   -- RFC Message-ID for reply matching
  to_email       VARCHAR(191)    NOT NULL,
  subject        VARCHAR(500)    NOT NULL,
  status         VARCHAR(20)     NOT NULL DEFAULT 'queued',
      -- queued | sent | failed | bounced | opened | clicked | replied
  error          TEXT                NULL,
  sent_at        DATETIME            NULL,
  opened_at      DATETIME            NULL,
  clicked_at     DATETIME            NULL,
  created_at     DATETIME        NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_enrollment (enrollment_id),
  KEY idx_lead (lead_id, created_at),
  KEY idx_status (status, created_at),
  KEY idx_message_id (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC;
```

### 6.5 `wp_hvc_suppressions`

```sql
CREATE TABLE wp_hvc_suppressions (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email_hash  CHAR(64)        NOT NULL,
  reason      VARCHAR(50)     NOT NULL,   -- unsubscribed | bounced | complaint | manual
  created_at  DATETIME        NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_email_hash (email_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
```

---

## 7. Table Group F — Integrations

### 7.1 `wp_hvc_integrations`

```sql
CREATE TABLE wp_hvc_integrations (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider          VARCHAR(50)     NOT NULL,
      -- fluentcrm | groundhogg | hubspot | zoho | salesforce | webhook | slack
  name              VARCHAR(191)        NULL,
  status            VARCHAR(20)     NOT NULL DEFAULT 'disconnected',
      -- disconnected | connected | error | expired
  credentials       TEXT                NULL,   -- AES-256-GCM ciphertext
  token_expires_at  DATETIME            NULL,
  field_mapping     JSON                NULL,
  sync_config       JSON                NULL,   -- {on_capture, on_qualify, min_score}
  last_sync_at      DATETIME            NULL,
  last_error        TEXT                NULL,
  error_count       INT UNSIGNED    NOT NULL DEFAULT 0,
  created_at        DATETIME        NOT NULL,
  updated_at        DATETIME        NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_provider (provider),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC;
```

### 7.2 `wp_hvc_integration_log`

```sql
CREATE TABLE wp_hvc_integration_log (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  integration_id  BIGINT UNSIGNED NOT NULL,
  lead_id         BIGINT UNSIGNED     NULL,
  operation       VARCHAR(50)     NOT NULL,   -- push_contact | push_activity | test
  status          VARCHAR(20)     NOT NULL,   -- success | failed | retrying
  attempt         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  external_id     VARCHAR(191)        NULL,
  request_summary JSON                NULL,   -- redacted; never full credentials
  response_code   SMALLINT UNSIGNED   NULL,
  error           TEXT                NULL,
  next_retry_at   DATETIME            NULL,
  created_at      DATETIME        NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_integration (integration_id, created_at),
  KEY idx_lead (lead_id),
  KEY idx_retry (status, next_retry_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC;
```

---

## 8. Table Group G — Platform

### 8.1 `wp_hvc_usage_events`

```sql
CREATE TABLE wp_hvc_usage_events (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent_id      BIGINT UNSIGNED     NULL,
  conversation_id BIGINT UNSIGNED   NULL,
  kind          VARCHAR(20)     NOT NULL,   -- completion | embedding | summary | scoring
  provider      VARCHAR(32)     NOT NULL,
  model         VARCHAR(64)     NOT NULL,
  tokens_in     INT UNSIGNED    NOT NULL DEFAULT 0,
  tokens_out    INT UNSIGNED    NOT NULL DEFAULT 0,
  cost          DECIMAL(12,6)       NULL,   -- NULL = no published price
  latency_ms    INT UNSIGNED        NULL,
  occurred_at   DATETIME        NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_agent_time (agent_id, occurred_at),
  KEY idx_kind_time (kind, occurred_at),
  KEY idx_occurred (occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
```

### 8.2 `wp_hvc_analytics_daily`

```sql
CREATE TABLE wp_hvc_analytics_daily (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  date                DATE            NOT NULL,
  agent_id            BIGINT UNSIGNED     NULL,   -- NULL = site-wide rollup
  conversations       INT UNSIGNED    NOT NULL DEFAULT 0,
  messages            INT UNSIGNED    NOT NULL DEFAULT 0,
  unique_visitors     INT UNSIGNED    NOT NULL DEFAULT 0,
  leads_captured      INT UNSIGNED    NOT NULL DEFAULT 0,
  leads_qualified     INT UNSIGNED    NOT NULL DEFAULT 0,
  handoffs            INT UNSIGNED    NOT NULL DEFAULT 0,
  resolved_by_ai      INT UNSIGNED    NOT NULL DEFAULT 0,
  positive_ratings    INT UNSIGNED    NOT NULL DEFAULT 0,
  negative_ratings    INT UNSIGNED    NOT NULL DEFAULT 0,
  unanswered          INT UNSIGNED    NOT NULL DEFAULT 0,
  tokens_in           BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tokens_out          BIGINT UNSIGNED NOT NULL DEFAULT 0,
  cost                DECIMAL(12,6)   NOT NULL DEFAULT 0,
  avg_latency_ms      INT UNSIGNED        NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_date_agent (date, agent_id),
  KEY idx_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
```

**Pre-aggregated rollups exist because dashboard queries must not scan `messages`.** A site with 50,000 conversations would otherwise make the dashboard unusable. An hourly job rolls the previous day forward; today's figures are computed live from `usage_events` and merged.

### 8.3 `wp_hvc_unanswered`

```sql
CREATE TABLE wp_hvc_unanswered (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent_id         BIGINT UNSIGNED NOT NULL,
  conversation_id  BIGINT UNSIGNED     NULL,
  query            VARCHAR(500)    NOT NULL,
  query_hash       CHAR(64)        NOT NULL,
  best_score       DECIMAL(5,4)        NULL,
  occurrences      INT UNSIGNED    NOT NULL DEFAULT 1,
  status           VARCHAR(20)     NOT NULL DEFAULT 'open',  -- open | resolved | ignored
  resolved_by      BIGINT UNSIGNED     NULL,
  first_seen_at    DATETIME        NOT NULL,
  last_seen_at     DATETIME        NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_agent_query (agent_id, query_hash),
  KEY idx_status (status, occurrences)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC;
```

**This table is a product feature, not telemetry.** It is the knowledge-gap worklist (FR-ANL-03) — the single most actionable screen in the analytics area.

### 8.4 `wp_hvc_audit_log`

```sql
CREATE TABLE wp_hvc_audit_log (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  wp_user_id   BIGINT UNSIGNED     NULL,
  action       VARCHAR(100)    NOT NULL,   -- agent.updated | settings.api_key_changed …
  object_type  VARCHAR(50)         NULL,
  object_id    BIGINT UNSIGNED     NULL,
  changes      JSON                NULL,   -- {before:{}, after:{}} — secrets redacted
  ip_hash      CHAR(64)            NULL,
  user_agent   VARCHAR(500)        NULL,
  created_at   DATETIME        NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_user (wp_user_id, created_at),
  KEY idx_action (action, created_at),
  KEY idx_object (object_type, object_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC;
```

### 8.5 `wp_hvc_rate_limits`

```sql
CREATE TABLE wp_hvc_rate_limits (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bucket_key   VARCHAR(191)    NOT NULL,   -- sha256(ip) | session uuid
  window_start DATETIME        NOT NULL,
  hits         INT UNSIGNED    NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_bucket_window (bucket_key, window_start),
  KEY idx_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
```

**Used only when no persistent object cache exists.** With Redis or Memcached present, rate limiting never touches the database.

### 8.6 `wp_hvc_sessions`

```sql
CREATE TABLE wp_hvc_sessions (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid            CHAR(36)        NOT NULL,
  visitor_id      BIGINT UNSIGNED     NULL,
  conversation_id BIGINT UNSIGNED     NULL,
  token_hash      CHAR(64)        NOT NULL,   -- HMAC of the issued session token
  transport       VARCHAR(10)     NOT NULL DEFAULT 'sse',  -- sse | poll
  ip_hash         CHAR(64)            NULL,
  expires_at      DATETIME        NOT NULL,
  created_at      DATETIME        NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_uuid (uuid),
  UNIQUE KEY uq_token (token_hash),
  KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
```

---

## 9. Storage Sizing

Estimated for a mid-sized store: 10,000 chunks, 20,000 conversations/year, 6 messages each.

| Table | Rows | Avg row | Total |
|---|---:|---:|---:|
| `embeddings` | 10,000 | 6.4 KB | **64 MB** |
| `chunks` | 10,000 | 1.2 KB | 12 MB |
| `documents` | 2,000 | 8 KB | 16 MB |
| `messages` | 120,000 | 1.5 KB | **180 MB** |
| `conversations` | 20,000 | 0.8 KB | 16 MB |
| `message_citations` | 300,000 | 0.4 KB | 120 MB |
| `activities` | 200,000 | 0.5 KB | 100 MB |
| `usage_events` | 150,000 | 0.15 KB | 22 MB |
| Everything else | — | — | ~30 MB |
| **Total** | | | **≈ 560 MB / year** |

**Mitigations:** `embeddings.embedding_f32` dominates fixed storage — a `--compact` mode drops it and reruns Stage 2 against the quantized vector at a small recall cost, saving 60 MB per 10k chunks. Retention purging bounds the message-side growth.

---

## 10. Retention and Purge

| Data | Default retention | Governed by |
|---|---|---|
| Conversations + messages + citations | 12 months (Pro) | `conversations.purge_after` |
| Activities | 24 months | Scheduled job |
| Usage events | 13 months (keeps YoY comparison) | Scheduled job |
| Audit log | 24 months, never auto-purged below 90 days | Compliance floor |
| Rate limits | 24 hours | Scheduled job |
| Sessions | On expiry | Scheduled job |
| Analytics rollups | Indefinite (small) | — |

`hvc_maint/purge_retention` runs nightly in bounded batches of 500 rows, deleting children before parents inside a transaction.

---

## 11. GDPR Erasure Map

When a data subject erasure is requested for an email address:

```
leads (by email_hash)
  ├─ lead_scores            DELETE
  ├─ activities             DELETE
  ├─ sequence_enrollments   DELETE
  ├─ email_log              DELETE
  ├─ integration_log        ANONYMISE (retain operation record, drop lead_id + PII)
  ├─ conversations          → messages → message_citations   DELETE
  ├─ visitors               DELETE
  ├─ sessions               DELETE
  └─ leads                  DELETE
audit_log                   ANONYMISE (retain action, null wp_user_id if the subject)
suppressions                RETAIN (legal basis: honouring unsubscribe)
```

Registered with WordPress's `wp_privacy_personal_data_erasers` and `..._exporters` filters, satisfying FR-SYS-04.

---

**Approval:** ⬜ Awaiting sign-off · Reviewer: ______________ · Date: __________
