# Hiveclerk — Plugin Folder Architecture

**Deliverable 10 of 16** · Version 1.0 · Status: **Draft — awaiting approval** · 2026-08-05

---

## 1. Top-Level Layout

```
hiveclerk/
├── hiveclerk.php                 # Bootstrap only — header, guards, autoload, Plugin::boot()
├── uninstall.php                 # Opt-in data removal
├── composer.json
├── package.json
├── vite.config.ts
├── tailwind.config.ts
├── tsconfig.json
├── phpunit.xml.dist
├── phpstan.neon.dist
├── phpcs.xml.dist
├── .distignore                   # Excludes dev files from the release ZIP
├── readme.txt                    # WordPress.org format
├── CHANGELOG.md
│
├── src/                          # PHP — PSR-4 → Hiveclerk\
│   ├── Plugin.php
│   ├── Core/
│   ├── Domain/
│   ├── Modules/
│   ├── Api/
│   ├── Database/
│   ├── Services/
│   ├── Infrastructure/
│   └── Support/
│
├── admin-app/                    # React 19 SPA source
├── public-widget/                # Preact widget source
├── assets/                       # Vite build output (committed for the release ZIP)
├── templates/                    # Server-rendered PHP (mount points, emails)
├── languages/                    # .pot + translations
├── vendor/                       # Composer (production only in release)
├── tests/
└── docs/                         # These 16 deliverables
```

**`hiveclerk.php` contains no logic.** It declares the plugin header, guards PHP/WP versions, requires the autoloader, and calls `Plugin::boot()`. Everything else lives in `src/`.

---

## 2. `src/Core/` — Framework

```
src/Core/
├── Container/
│   ├── Container.php                 # PSR-11 implementation
│   ├── ServiceProvider.php           # Abstract base
│   └── Providers/
│       ├── CoreServiceProvider.php
│       ├── DatabaseServiceProvider.php
│       ├── ProviderServiceProvider.php   # LLM + embedding adapters
│       └── QueueServiceProvider.php
├── Module/
│   ├── ModuleInterface.php
│   ├── ModuleRegistry.php
│   └── AbstractModule.php
├── Events/
│   ├── EventBus.php
│   ├── EventInterface.php
│   └── ListenerInterface.php
├── Hooks/
│   ├── HookLoader.php                # Declarative hook registration
│   └── Hookable.php
├── Activation/
│   ├── Activator.php
│   ├── Deactivator.php
│   └── Requirements.php              # PHP/WP/MySQL preflight
├── Licence/
│   ├── LicenceService.php
│   ├── LicenceGate.php               # Feature gating by tier
│   └── LicenceClient.php
├── Capabilities/
│   ├── CapabilityManager.php
│   └── Capabilities.php              # Constants
├── Settings/
│   ├── SettingsRepository.php
│   └── SettingsSchema.php
└── Support/
    ├── Encryptor.php                 # AES-256-GCM
    ├── RateLimiter.php
    ├── Logger.php
    └── Clock.php                     # Injectable — makes time testable
```

**Why a custom container rather than PHP-DI or League.** Plugin conflicts from duplicated Composer dependencies are the single most common cause of fatal errors in the WordPress ecosystem. A ~200-line PSR-11 container with no third-party dependency eliminates that risk entirely. Where a dependency is unavoidable (Action Scheduler, PDF parsing), it is prefixed with PHP-Scoper — see §8.

---

## 3. `src/Domain/` — Framework-free core

```
src/Domain/
├── Agent/         Agent.php · AgentStatus.php · RolePreset.php
│                  Personality.php · Guardrails.php · ModelConfig.php
│                  DisplayRules.php · AgentRepositoryInterface.php
├── Conversation/  Conversation.php · Message.php · MessageRole.php
│                  Citation.php · Sentiment.php · *RepositoryInterface.php
├── Knowledge/     KnowledgeSource.php · Document.php · Chunk.php
│                  Embedding.php · SourceType.php · RetrievedChunk.php
├── Lead/          Lead.php · LeadScore.php · ScoreBand.php · Stage.php
│                  Activity.php · ScoringRule.php · *RepositoryInterface.php
├── Email/         EmailSequence.php · SequenceStep.php · Enrollment.php
│                  EmailMessage.php · EmailDraft.php
├── Integration/   Integration.php · FieldMap.php · SyncResult.php
└── Shared/        Uuid.php · Money.php · TokenUsage.php · DateRange.php
                   Pagination.php · Result.php
```

**This directory imports nothing.** No `wp_*` functions, no `$wpdb`, no Composer packages beyond PHP itself. Enforced by a PHPStan rule that fails CI on any WordPress function call inside `src/Domain/`. This is the constraint that makes the V3 SaaS extraction a rebinding rather than a rewrite.

---

## 4. `src/Modules/` — Feature modules

Each module owns its full vertical slice and is independently removable.

```
src/Modules/
├── Chat/
│   ├── ChatModule.php
│   ├── Services/       ChatService · PromptBuilder · GuardrailService
│   │                   StreamHandler · ConversationSummariser
│   ├── Http/           StreamController · MessageController · HistoryController
│   ├── Listeners/      PersistUsage · DetectKnowledgeGap · TriggerLeadExtraction
│   └── Jobs/           SummariseConversation · AnalyseSentiment
│
├── KnowledgeBase/
│   ├── KnowledgeModule.php
│   ├── Services/       IngestionService · ChunkerService · EmbeddingService
│   │                   RetrievalService · RerankService
│   ├── Extractors/     WpContentExtractor · WooProductExtractor
│   │                   WebCrawlExtractor · PdfExtractor · DocxExtractor
│   │                   FaqExtractor · TextExtractor
│   ├── Vector/         MysqlBlobVectorStore · BinaryQuantiser
│   │                   CosineCalculator · MatrixCache
│   ├── Http/           SourceController · SearchController · UploadController
│   └── Jobs/           CrawlUrl · ParseDocument · ChunkDocument · EmbedBatch
│
├── Leads/
│   ├── LeadsModule.php
│   ├── Services/       LeadService · ScoringService · IdentityResolver
│   │                   PipelineService · LeadExtractor
│   ├── Rules/          RuleInterface · FieldRule · KeywordRule
│   │                   PageContextRule · EngagementRule · AiRule
│   ├── Http/           LeadController · StageController · ScoringRuleController
│   └── Jobs/           RecomputeScore · ResolveIdentity
│
├── Email/
│   ├── EmailModule.php
│   ├── Services/       EmailService · SequenceEngine · CopyGenerator
│   │                   SuppressionService
│   ├── Transport/      WpMailTransport · MailTransportInterface
│   ├── Http/           SequenceController · StepController · LogController
│   └── Jobs/           SequenceTick · SendEmail
│
├── Integrations/
│   ├── IntegrationsModule.php
│   ├── Services/       CrmService · ConnectorRegistry · FieldMapper · OAuthService
│   ├── Connectors/     FluentCrmConnector · GroundhoggConnector
│   │                   HubSpotConnector · ZohoConnector · SalesforceConnector
│   │                   WebhookConnector · SlackConnector
│   ├── Http/           IntegrationController · OAuthCallbackController
│   └── Jobs/           PushContact · PushActivity · RetrySync
│
├── Analytics/
│   ├── AnalyticsModule.php
│   ├── Services/       AnalyticsService · RollupService · CostTracker
│   │                   TopicClusterer
│   ├── Http/           DashboardController · ReportController · ExportController
│   └── Jobs/           RollupDaily · PurgeRetention
│
└── Agents/
    ├── AgentsModule.php
    ├── Services/       AgentService · PresetLibrary · TestConsoleService
    │                   BudgetGuard
    └── Http/           AgentController · PresetController · TestController
```

### V2/V3 modules drop in without touching existing code

```
├── Workflows/         # V2 — visual builder
├── WooCommerce/       # V2 — sales clerk
└── Marketplace/       # V3 — clerk marketplace
```

They subscribe to existing domain events (`LeadCaptured`, `ConversationEnded`, `SourceIndexed`) rather than modifying the modules that emit them.

### Module contract

```php
final class ChatModule extends AbstractModule
{
    public static function id(): string { return 'chat'; }

    public function register(ContainerInterface $c): void {
        $c->singleton(ChatService::class, fn($c) => new ChatService(
            $c->get(AiServiceInterface::class),
            $c->get(RetrievalServiceInterface::class),
            $c->get(GuardrailService::class),
            $c->get(ConversationRepositoryInterface::class),
            $c->get(EventBus::class),
        ));
    }

    public function boot(): void {
        $this->routes(StreamController::class, MessageController::class);
        $this->listen(ConversationEnded::class, SummariseConversation::class);
        $this->jobs(SummariseConversation::class, AnalyseSentiment::class);
    }

    public function migrations(): array {
        return [M0001_Conversations::class, M0002_Messages::class];
    }

    public function capabilities(): array {
        return [Capabilities::VIEW_CONVERSATIONS, Capabilities::MANAGE_CONVERSATIONS];
    }

    public function isAvailable(): bool { return true; }
}
```

---

## 5. `src/Api/`, `src/Database/`, `src/Infrastructure/`

```
src/Api/
├── RestServer.php               # Route registration
├── AbstractController.php
├── Middleware/                  # Nonce · Capability · RateLimit
│                                # SessionAuth · LicenceGate · Validation
├── Schema/                      # JSON Schema per route
├── Transformers/                # Domain object → API response
└── Response/                    # ApiResponse · ErrorResponse · Paginator

src/Database/
├── Migrator.php                 # Versioned runner (not dbDelta)
├── Migration.php
├── Migrations/                  # M0001_Initial … M00NN_*
├── QueryBuilder.php             # Thin, prepare()-safe
├── AbstractRepository.php
└── Repositories/                # One per aggregate root

src/Infrastructure/
├── Ai/
│   ├── Providers/               # Anthropic · OpenAi · Google
│   │                            # AzureOpenAi · OpenRouter · ManagedGateway
│   ├── ProviderRegistry.php
│   ├── KeyResolver.php
│   ├── StreamParser.php         # SSE parsing per provider
│   └── PricingTable.php
├── Queue/                       # ActionSchedulerQueue · JobDispatcher
├── Cache/                       # ObjectCache · TransientCache · CacheInterface
├── Http/                        # HttpClient (wp_remote_*) · RetryPolicy
├── Storage/                     # AttachmentStorage · TempFileManager
└── Wordpress/                   # WpOptions · WpUsers · WpMail · WpCron
                                 # — the ONLY place WP globals are touched
```

**`src/Infrastructure/Wordpress/` is the containment boundary.** Every `get_option`, `wp_mail`, `$wpdb`, and `current_user_can` call lives here or in `src/Api/`. Nothing else in the codebase may call a WordPress function — enforced in CI.

---

## 6. `admin-app/` — React 19 SPA

```
admin-app/
├── index.html                    # Dev only
├── src/
│   ├── main.tsx                  # Mounts to #hvc-root
│   ├── App.tsx                   # HashRouter + providers
│   ├── boot.ts                   # Reads window.HVC_BOOT
│   │
│   ├── routes/
│   │   ├── dashboard/            # DashboardPage · KpiCard · TrendChart
│   │   ├── clerks/               # ClerkListPage · ClerkEditorPage
│   │   │                         # JobDescriptionTab · KnowledgeTab
│   │   │                         # GuardrailsTab · AppearanceTab
│   │   │                         # DisplayRulesTab · TestConsole
│   │   ├── conversations/        # ConversationListPage · TranscriptPanel
│   │   │                         # CitationInspector · TakeoverComposer
│   │   ├── leads/                # LeadListPage · PipelineBoard
│   │   │                         # LeadDetailPage · ScoreBreakdown
│   │   │                         # ScoringRulesEditor
│   │   ├── knowledge/            # SourceListPage · SourceEditor
│   │   │                         # CrawlPreview · RetrievalPlayground
│   │   │                         # KnowledgeGapsPage
│   │   ├── integrations/         # IntegrationGrid · ConnectorCard
│   │   │                         # FieldMappingEditor · SyncLog
│   │   ├── workflows/            # V2 placeholder
│   │   ├── analytics/            # OverviewPage · FunnelReport
│   │   │                         # TopicsReport · CostReport
│   │   ├── settings/             # ProvidersPage · LicencePage
│   │   │                         # PrivacyPage · BrandingPage · AuditLogPage
│   │   └── onboarding/           # WizardShell + 5 steps
│   │
│   ├── components/
│   │   ├── ui/                   # Design-system primitives (Deliverable 12)
│   │   ├── layout/               # AppShell · Sidebar · TopBar · CommandPalette
│   │   ├── data/                 # DataTable · EmptyState · Pagination · Filters
│   │   ├── charts/               # Recharts wrappers
│   │   └── feedback/             # Toast · Skeleton · ErrorBoundary
│   │
│   ├── api/
│   │   ├── client.ts             # fetch wrapper — nonce, envelope, errors
│   │   ├── queries/              # React Query hooks per resource
│   │   └── types.ts              # Generated from JSON Schema
│   │
│   ├── stores/                   # Zustand — ui · filters · onboarding
│   ├── hooks/                    # useCapability · useLicence · useTheme
│   ├── lib/                      # format · validation (Zod) · cn()
│   └── styles/                   # tailwind.css · tokens.css
└── public/
```

**No Gutenberg packages.** `@wordpress/components`, `@wordpress/data`, and `@wordpress/element` are explicitly excluded — enforced by an ESLint `no-restricted-imports` rule that fails the build.

**State discipline:** React Query owns all server state; Zustand owns only ephemeral UI state (open panels, filter drafts, wizard progress). No server data is duplicated into Zustand.

---

## 7. `public-widget/` — Preact widget

```
public-widget/
├── src/
│   ├── index.ts                  # Entry — reads config, mounts shadow root
│   ├── Widget.tsx
│   ├── components/               # Launcher · ChatWindow · MessageList
│   │                             # MessageBubble · Composer · CitationChip
│   │                             # LeadForm · TypingIndicator · HandoffPrompt
│   ├── transport/
│   │   ├── SseTransport.ts
│   │   ├── PollTransport.ts
│   │   └── TransportSelector.ts  # Probe-frame detection (Deliverable 6 §5)
│   ├── state/                    # Minimal signals-based store
│   ├── styles/                   # Shadow-DOM-scoped CSS
│   └── i18n/
└── vite.widget.config.ts
```

**Preact, not React, for the widget.** The 40 KB gzipped budget (NFR-01) is not achievable with React 19 plus its runtime. Preact with `preact/compat` gives the same authoring experience at roughly a tenth of the runtime size. The admin SPA — which has no size constraint of that severity — uses full React 19.

**Shadow DOM isolation** prevents theme CSS from breaking the widget and vice versa, satisfying FR-WGT-10.

---

## 8. Dependencies

### `composer.json`

```json
{
  "name": "decent-theme/hiveclerk",
  "type": "wordpress-plugin",
  "require": {
    "php": ">=8.3",
    "woocommerce/action-scheduler": "^3.8",
    "smalot/pdfparser": "^2.11",
    "phpoffice/phpword": "^1.3"
  },
  "require-dev": {
    "phpunit/phpunit": "^11",
    "phpstan/phpstan": "^2",
    "szepeviktor/phpstan-wordpress": "^2",
    "wp-coding-standards/wpcs": "^3",
    "brain/monkey": "^2.6",
    "humbug/php-scoper": "^0.18"
  },
  "autoload": { "psr-4": { "Hiveclerk\\": "src/" } },
  "config": { "platform": { "php": "8.3.0" } }
}
```

**Runtime dependencies are kept to three** — each earns its place, and all are prefixed with PHP-Scoper into `Hiveclerk\Vendor\` at build time so a conflicting version bundled by another plugin cannot cause a fatal error. This is the most common source of WordPress plugin conflicts and it is entirely preventable.

### `package.json` (excerpt)

```json
{
  "dependencies": {
    "react": "^19", "react-dom": "^19", "react-router-dom": "^7",
    "@tanstack/react-query": "^5", "zustand": "^5",
    "react-hook-form": "^7", "@hookform/resolvers": "^3", "zod": "^3",
    "recharts": "^2", "@headlessui/react": "^2", "clsx": "^2",
    "tailwind-merge": "^2", "date-fns": "^4", "preact": "^10"
  },
  "devDependencies": {
    "vite": "^6", "typescript": "^5.7", "tailwindcss": "^4",
    "@vitejs/plugin-react": "^4", "vitest": "^2",
    "@testing-library/react": "^16", "playwright": "^1.49",
    "eslint": "^9", "prettier": "^3", "size-limit": "^11"
  }
}
```

---

## 9. Build and Release

```
Development                        Release ZIP
────────────────────────────       ─────────────────────────
npm run dev      → Vite HMR        composer install --no-dev -o
composer install → dev deps        php-scoper add-prefix
                                   npm run build
npm run build    → assets/         wp dist-archive
  admin/[name].[hash].js
  admin/[name].[hash].css          Excluded via .distignore:
  widget/hiveclerk-widget.js         admin-app/ public-widget/
                                     tests/ docs/ node_modules/
                                     *.config.* phpunit.xml *.dist
```

**`assets/` is committed** because WordPress.org distributes source ZIPs without a build step. `admin-app/` and `public-widget/` sources are excluded from the release ZIP to keep it small.

### CI gates — all must pass before merge

| Gate | Tool | Threshold |
|---|---|---|
| PHP static analysis | PHPStan + phpstan-wordpress | Level 8, zero errors |
| Domain purity | Custom PHPStan rule | No WP functions in `src/Domain/` |
| Coding standards | PHPCS + WPCS | Zero errors |
| PHP unit tests | PHPUnit | ≥ 70% on `src/Services/` and `src/Domain/` |
| TS type check | `tsc --noEmit` | Zero errors |
| No Gutenberg imports | ESLint `no-restricted-imports` | Zero violations |
| Widget bundle size | size-limit | ≤ 40 KB gzipped |
| Admin bundle size | size-limit | ≤ 350 KB gzipped |
| E2E | Playwright | Onboarding → publish → converse → lead |

---

## 10. Namespace Map

| Path | Namespace |
|---|---|
| `src/Core/` | `Hiveclerk\Core\` |
| `src/Domain/` | `Hiveclerk\Domain\` |
| `src/Modules/Chat/` | `Hiveclerk\Modules\Chat\` |
| `src/Api/` | `Hiveclerk\Api\` |
| `src/Database/` | `Hiveclerk\Database\` |
| `src/Infrastructure/` | `Hiveclerk\Infrastructure\` |
| `vendor/` (scoped) | `Hiveclerk\Vendor\` |
| `tests/` | `Hiveclerk\Tests\` |

---

## 11. How Each Module Stays Independently Extendable

The PRD requires every module be independently extendable. Four mechanisms deliver it:

1. **Self-registration** — a module declares its own routes, jobs, migrations, and capabilities. Adding one means dropping a directory in and listing it in `ModuleRegistry`.
2. **Event-driven communication** — modules never call each other's services. `Leads` reacts to `ConversationEnded`; it does not know `Chat` exists.
3. **Interface-first ports** — `CrmConnectorInterface`, `LlmProviderInterface`, and `VectorStoreInterface` let third parties register implementations via filter, without forking.
4. **Graceful absence** — `isAvailable()` gates a module on licence tier or a missing dependency. A removed or gated module degrades the product; it never fatals it.

---

**Approval:** ⬜ Awaiting sign-off · Reviewer: ______________ · Date: __________
