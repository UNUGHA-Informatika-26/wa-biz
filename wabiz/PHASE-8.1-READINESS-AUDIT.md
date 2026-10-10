# WABiz Phase 8.1 — Read-Only Production Readiness Audit

Audit date: 2026-10-08 (Asia/Bangkok)
Scope: Flutter `C:\WABiz\wabiz`, Laravel `C:\WABiz\wabiz-backend`, WPPConnect gateway `C:\WABiz\wabiz-whatsapp-gateway`.

## Executive summary

**Production decision: NOT READY.** The application has substantial end-to-end foundations: authenticated Flutter API services, organization-scoped Laravel resources, provider abstractions, AI processing and queued delivery, campaign scheduling, WPPConnect session management, and conversation handoff. Static review supports those paths, but production readiness is blocked by a confirmed loopback API base URL in the Flutter client, missing visible login rate limiting, a shared-secret WPPConnect callback token carried in a URL, and an audit-session credential exposure described under Security. Operational readiness (production queue worker/scheduler, backups/restore, monitoring/alerts, and actual deployed configuration) is not established by repository evidence. Client/contacts and dashboard portions still use local/demo data.

This was a source/configuration review. No application tests, database queries, migrations, service starts, provider delivery, QR pairing, WhatsApp login, or real messages were performed. Syntax-only checks were run on selected PHP files and the gateway launcher. The report is new; application source/configuration, gateway, tests, and baseline were not modified.

## 1. Inventory and environment

| Item | Finding | Evidence / limitation |
|---|---|---|
| Flutter project | Present | `C:\WABiz\wabiz` |
| Laravel project | Present | `C:\WABiz\wabiz-backend` |
| Gateway project | Present | `C:\WABiz\wabiz-whatsapp-gateway` |
| Git metadata | Not present in any of the three project roots | `.git` absent at each root; current working diff and historical attribution cannot be verified. |
| Flutter | 3.47.5 stable | `C:\src\flutter\bin\cache\flutter.version.json`; Flutter CLI version command did not complete during this review. |
| Dart SDK | 3.13.4 | `C:\src\flutter\bin\cache\dart-sdk\version`; `pubspec.yaml` requires `^3.13.4`. |
| PHP | 8.5.11 | `php --version` |
| Composer | 2.10.3 | `composer --version` |
| Laravel | 13.34.0 | `wabiz-backend/composer.lock`, `laravel/framework` |
| Sanctum | 4.3.3 | `wabiz-backend/composer.lock`, `laravel/sanctum` |
| Node.js / npm | v22.22.2 / 10.9.7 | CLI versions |
| WPPConnect | `@wppconnect/server` 2.10.30; `@wppconnect-team/wppconnect` 2.3.4; `prom-client` 14.2.0 | `wabiz-whatsapp-gateway/package-lock.json` |
| Baseline / prior reports | Present | `C:\WABiz\wabiz\CURRENT-STATE-BASELINE-2026-10-07.sha256`, `PHASE-7.2-BASELINE-AUDIT.md`, `PHASE-7.3-FINAL-ACCEPTANCE-REPORT.md` |

The gateway has no project README or checked-in deployment manifest at its root. The backend has a generic Laravel README, but the inspected top-level project contents did not establish production deployment, worker, backup, or monitoring configuration. This does not prove that such infrastructure is absent outside these source trees.

## 2. Architecture and principal data paths

### Flutter authentication and API

- `wabiz/lib/core/network/api_client.dart:33` defines the API base URL as `http://127.0.0.1:8000/api/`; request construction is in the same API client. This targets the device itself on phones and is not a usable production API host for deployed clients. This is a confirmed release blocker unless overridden elsewhere; no override was found in the inspected client.
- `wabiz/lib/core/network/auth_storage.dart:10` uses `FlutterSecureStorage`. `AuthController::login()` validates credentials, checks the password hash, and creates a Sanctum token (`wabiz-backend/app/Http/Controllers/Api/AuthController.php:14-45`).
- Authenticated Flutter service classes include `features/inbox/data/conversation_api_service.dart`, `features/ai_assistant/data/ai_assistant_api_service.dart`, `features/campaigns/data/campaign_api_service.dart`, `features/cs_management/data/cs_management_api_service.dart`, and `features/whatsapp_connection/data/whatsapp_connection_service.dart`.
- `routes/api.php:36` puts business routes behind `auth:sanctum`; operation gates are defined in `app/Providers/AppServiceProvider.php:44-46`.

### Inbox / conversation and message history

- Flutter calls conversation list, detail, message pagination, read, reset, reply, status, assignment, connection, member, and notification-recovery APIs through `conversation_api_service.dart:41-105`.
- Laravel routes are in `wabiz-backend/routes/api.php:71-81`; `ConversationController` scopes list/resource queries by authenticated `organization_id`, returns active-generation messages, and exposes read/reset/reconcile/reply/status/assignment operations (`ConversationController.php:40-107,131-167,199-220,239-277,339-411`).
- Inbound WPPConnect callback is handled in `WhatsAppWebhookController.php:31-35,293-344`; it resolves session/connection/organization server-side, persists idempotent event/message data, and logs sanitized failure fields in `:157-177,427-438`. Duplicate behavior is covered in prior Phase reports, but not re-run in this audit.
- Conversation and message schema/migrations include organization and connection relationships and `history_generation`; relevant migrations are under `wabiz-backend/database/migrations/`, including `2026_10_07_032627_add_connection_and_history_generation_to_conversations_and_messages.php` and `2026_10_08_060000_add_unread_count_to_conversations.php`.

### AI and outbound delivery

- `AiMessageProcessor.php:49-80` resolves organization settings and builds a decision; `:118-165` persists a response under transaction/locks and binds it to the active history generation; `:202-220` dispatches delivery after commit and records uncertain queue dispatch as `unknown`.
- `AiContextBuilder.php:26-53` checks organization consistency and loads organization-scoped settings and messages only from the active generation. `DeterministicAiOrchestrator.php:30-75` selects settings/decision and an AI provider. Gemini credentials are read server-side through `config/services.php:38-43`; no credential value is included here.
- `SendAiConversationMessage` is a unique queue job with `tries=1`, `timeout=25` (`wabiz-backend/app/Jobs/SendAiConversationMessage.php:13-25`). `WhatsAppMessageSender.php:32-123,135-170,180-404` validates tenant, connection, client number, conversation state/generation, persists delivery state, and uses `WhatsAppProviderRegistry`. Known failures become `failed`; ambiguous provider outcomes remain `unknown`/non-retryable. This is not exactly-once delivery.
- Provider call occurs while the sender holds transaction/locks around connection/conversation/message state. That reduces stale-state races but can delay database state changes until the external request times out; runtime impact under production load has not been measured.

### Campaign delivery

- Flutter campaign service calls list/create/update/send/schedule/cancel/recipient APIs (`campaign_api_service.dart:55-170`). Laravel endpoints are `routes/api.php:54-63`.
- `SendCampaignRecipient.php:23-35,48-96,135-138` uses a unique job identity, tenant-scoped connection/recipient resolution, and records ambiguous provider outcomes as `unknown` rather than treating them as a known failure. The job is configured for one try and a 30-second timeout.
- `routes/console.php:12` schedules `DispatchDueCampaigns` every minute with `withoutOverlapping()`. `config/queue.php:16` defaults to the environment-selected queue, with `database` as fallback. Therefore production must run both a persistent queue worker and Laravel scheduler; source presence alone does not prove they are deployed or supervised.

### Handoff notification

- Flutter exposes handoff notification recovery in `conversation_api_service.dart:78`; route is `routes/api.php:77`.
- `ConversationHandoffNotificationController.php:22-87` restricts recovery to organization operations and tenant-scoped records, and only permits failed/unknown or stale-sending states. `SendConversationHandoffNotification.php:44-107,114-147` resolves conversation, CS member and WhatsApp connection within the organization, normalizes the recipient, includes active-generation context and configured registration URL, and records `sent`/`failed`/`unknown`.
- Explicit recovery of `unknown` can duplicate a notification if the original provider accepted the message but its result was lost. This is a known ambiguity; do not claim exactly-once delivery. Stale `sending` recovery is explicit, but deployed operator process/monitoring is not verified.

### WhatsApp connection and gateway

- Flutter connection service uses Laravel connection create/status/start/QR/disconnect routes (`whatsapp_connection_service.dart:45-152`). Backend routes are in `routes/api.php:43-50`; `WhatsAppConnectionController.php:56-80,102-191` scopes connection operations to the authenticated organization and restricts management actions.
- Laravel `WppConnectGateway.php:145-198,224` maps a connection to a gateway session reference, obtains a per-session token server-side, sends bearer-authenticated requests, and reads the gateway base URL from backend configuration. Flutter does not call the gateway directly.
- `launcher-loopback.js:6-10,13-59,83-105` pins port 21465, forces loopback binding when the package omits a host, blocks POST `start-all` and `clear-session-data`, and starts with `startAllSession: false`. This is source evidence only; no gateway process or socket binding was runtime-checked.
- WPPConnect callback authentication uses a shared callback token in `POST /api/webhooks/whatsapp/wppconnect/{token}` (`routes/api.php:32-34`, `WhatsAppWebhookController.php:31-35`, `config/services.php:58`). It is not an HMAC signature. Because it is in the URL path, proxy/access logs or tracing can capture it. Meta/provider webhook routes use a signature verifier (`WhatsAppWebhookController.php:264-273`).

### Other product screens

- `wabiz/lib/features/clients/presentation/pages/clients_page.dart:22` initializes from `Client.mockData()` and contains local in-memory CRUD; `lib/features/clients/domain/models/client.dart:24` supplies demo data. This production UI is not backed by the Laravel client endpoints currently exposed in `routes/api.php:65-69`.
- `lib/features/contacts/presentation/pages/contacts_page.dart:34-81` contains seeded local contacts.
- `lib/features/dashboard/presentation/pages/dashboard_page.dart:76-112,327-335` renders static campaign/activity summaries. These should not be treated as live operational metrics.

## 3. Security findings

### Confirmed / high priority

1. **CRITICAL — configured gateway and callback credentials were exposed in audit command output (CONFIRMED).** During this read-only audit, a command intended to inspect environment configuration printed the configured WPPConnect secret and callback token as part of matching lines. Their values are intentionally omitted from this report and must not be copied. Treat both as compromised: rotate the gateway secret and callback token before production, update protected deployment configuration, and review/limit any retained terminal, task, or diagnostic logs containing the output. No WhatsApp message/session was created by the command.
2. **HIGH — Flutter points at loopback API (CONFIRMED).** `wabiz/lib/core/network/api_client.dart:33`. A production mobile device interprets `127.0.0.1` as itself, so it cannot reach a separately deployed Laravel API at that address. Configure a production HTTPS host through a controlled build/runtime configuration before release.
3. **HIGH — login route has no visible application throttle (CONFIRMED in checked route/controller).** `routes/api.php:22` registers login without throttle; `AuthController.php:14-45` validates credentials and issues Sanctum tokens. Webhooks have explicit `throttle:120,1` (`routes/api.php:24-34`), but login does not. External ingress controls were not available for inspection, so effective edge throttling is NOT VERIFIED. Add/verify rate limits and abuse monitoring before production.
4. **HIGH — WPP callback token is a bearer secret in URL path (CONFIRMED).** `routes/api.php:32-34`, `WhatsAppWebhookController.php:31-35`. A shared secret is compared with `hash_equals`, but there is no signature or timestamp/replay authentication for this WPP path. URL logs can disclose the token. Rotate due to this audit exposure, then prefer header-based authentication if the deployed WPPConnect version supports callback headers; otherwise aggressively redact path logs and use a high-entropy dedicated callback token, distinct from gateway `SECRET_KEY`.

### Additional risks / boundaries

- **MEDIUM — API health endpoint depth NOT VERIFIED.** `routes/api.php:15` defines `/health`; the audit did not run the server or inspect runtime dependencies. Confirm it checks dependencies (or expose separate liveness/readiness probes) without leaking internals.
- **MEDIUM — tenant protection is endpoint/service scoped, not proven by a single universal global scope.** Reviewed controllers/services repeatedly derive organization from `request->user()` and constrain resources/connection/provider (`ConversationController.php:40-60`, `WhatsAppConnectionController.php:56-80`, `WhatsAppProviderRegistry.php:23-24`, sender and campaign job evidence above). Existing Phase 7 tests are reported historically, but not re-run; no production authorization verification is claimed.
- **MEDIUM — transaction spans provider network request.** Sender locking around delivery reduces stale AI/human transitions but may keep database locks open for provider timeout (`WhatsAppMessageSender.php:180-304`). Load/deadlock behavior is NOT VERIFIED.
- **MEDIUM — ambiguous delivery recovery can duplicate on explicit operator retry.** Campaign and handoff use `unknown` to preserve uncertainty; no provider-side idempotency guarantee was established. Do not automatically retry `unknown`.
- **LOW/MEDIUM — demo-backed Clients, Contacts, and Dashboard remain.** Source locations above. This risks operator confusion if presented as production data.
- **NOT VERIFIED — log redaction and retention.** Reviewed error logs include identifiers/result fields, but deployment logging sinks, proxy access logs, retention, access control, and tracing were unavailable. Keep message bodies, tokens, headers, and provider credentials out of logs.
- **NOT VERIFIED — CORS/TLS/edge firewall and production host configuration.** No deployment environment or reverse proxy was inspected.

## 4. Reliability and operations

- Queue/scheduler implementation exists (`config/queue.php:16,38-69`, `routes/console.php:12`), but no worker service definition, process supervisor, scheduler cron/container, or deployment manifest was found among inspected project-root files. Runtime operation is **NOT VERIFIED**. A database queue default is not itself a running worker.
- Campaign and AI queue jobs use uniqueness/guarding and conservative attempt counts; delivery status distinguishes known failure from ambiguous `unknown`. These mechanisms reduce duplicate risk but cannot guarantee provider exactly-once semantics.
- No backup policy, restore rehearsal evidence, RPO/RTO, monitoring dashboard, alert rules, incident runbook, or log-retention policy was found in the inspected source-root documentation. This is NOT VERIFIED outside the repositories and is a production gate.
- Gateway has local `tokens`, `userDataDir`, and `log` directories (names only inspected). These may contain session/token/browser state; permissions, encryption, backup exclusion, retention, and disaster recovery are NOT VERIFIED. Protect them as credentials and WhatsApp session material.
- Gateway package includes `prom-client`, but no active scrape target/alerting deployment was verified. Do not infer monitoring merely from the dependency.
- Scheduler jobs should be monitored for overlap/missed execution; workers need restart/supervision, queue depth and failed-job alerts, and graceful deployment behavior. None was runtime-verified.

## 5. Test and verification status

### Checks run in this audit

- Recalculated SHA-256 for all 196 baseline entries; all were readable.
- `php -l` on 11 selected PHP route/controller/service/job files: no syntax errors.
- `node --check C:\WABiz\wabiz-whatsapp-gateway\launcher-loopback.js`: passed.
- Read package/SDK version metadata and route/config/source; no application services started.

### Checks deliberately not run

- Laravel or Flutter tests, Flutter analyze, Pint, Artisan route/migration commands, database queries, or migrations. `wabiz-backend/phpunit.xml:21-32` statically sets `APP_ENV=testing`, `DB_CONNECTION=pgsql`, host loopback, database `wabiz_test`, and `QUEUE_CONNECTION=sync`; however, the actual server/database identity and isolation were not independently verified. Tests may write to that database and synchronous jobs may execute side effects, so running them could not be proven safe under this read-only audit policy.
- No production/staging database connection, queue worker, scheduler, Laravel server, gateway process, real provider, QR flow, login, pairing, campaign, or WhatsApp delivery was attempted.
- Prior Phase 7 test counts are historical claims in `PHASE-7.3-FINAL-ACCEPTANCE-REPORT.md`, not current verification for this audit.
- No Flutter compile/analyze. Flutter CLI invocation did not complete; static SDK metadata was used for version only.

### Migration/dependency safety

No migration status, fresh database rehearsal, rollback, Composer/npm install, or dependency mutation was performed. Migration safety against production/current data is therefore NOT VERIFIED. Existing migration list was inspected statically; no schema introspection was run.

## 6. Baseline audit

Manifest: `C:\WABiz\wabiz\CURRENT-STATE-BASELINE-2026-10-07.sha256`.

- 196 valid hash entries; 6 comment lines; 0 malformed non-comment lines.
- 164 matched; 32 mismatched; 0 missing; 0 unreadable.
- Manifest SHA-256 remains `827b71775cab29f3b6696650bb6e9f3b626b509088f17c5e4426d84a195e32b5`.
- Manifest was read only and not changed.
- The existing per-file expected/actual hash table and current-role risk notes are in `C:\WABiz\wabiz\PHASE-7.2-BASELINE-AUDIT.md`. That report lists all 32 mismatches. In this audit, affected paths are verified to be app/test files involved in AI, conversation, CS/handoff, campaign/webhook, inbox, and dashboard. Source/tests can show present behavior and feature relevance, not when a hash changed or whether a historical edit was authorized.
- There is no `.git` metadata and no prior 29-file per-file snapshot was found. Therefore the three additional mismatches (29→32) are **UNVERIFIABLE**; no attribution is made. Historical pre-Pint baseline remains unavailable. Do not restore any mismatched file based on hash alone.
- Phase 8.1 created no source/test/config/migration change. This report is the only intended new project file.

## 7. Prioritized findings

### Blockers before production

| ID | Severity | Evidence | Impact / recommendation / dependency |
|---|---|---|---|
| P81-01 | CRITICAL — CONFIRMED | Audit command output disclosed configured gateway secret and callback token; values omitted. | Rotate both immediately through protected environment/secret management; invalidate previous credentials, verify gateway↔Laravel callback still works in a controlled non-production test, inspect/purge accessible logs as policy permits. Do not proceed to production until rotation is verified. |
| P81-02 | HIGH — CONFIRMED | `wabiz/lib/core/network/api_client.dart:33` | Mobile production client points to loopback. Before release, supply environment-specific HTTPS API URL and validate Android/iOS/desktop build targets. |
| P81-03 | HIGH — CONFIRMED | `routes/api.php:22`; `AuthController.php:14-45` | Login lacks visible rate limiting. Add/verify app or ingress throttling, lockout/abuse monitoring, and token expiration/revocation policy. External edge controls are NOT VERIFIED. |
| P81-04 | HIGH — CONFIRMED | `routes/api.php:32-34`; `WhatsAppWebhookController.php:31-35` | Shared callback token in URL risks log leakage and replay; rotate now, redact all URL logs, and migrate to header authentication if supported. If URL-only is required, use dedicated high-entropy token and documented redaction/rotation controls. |
| P81-05 | HIGH — NOT VERIFIED | No deployment/worker/scheduler/backup/alert configuration evidence in the inspected project roots. | Prove production DB backups and restore; persistent worker + scheduler supervision; queue/failed-job alerts; health/readiness probes; TLS, firewall, and incident procedures before go-live. |

### Acceptable only with explicit mitigation

| ID | Severity | Evidence | Mitigation |
|---|---|---|---|
| P81-06 | MEDIUM — CONFIRMED | Sender/campaign/handoff preserve ambiguous outcomes as `unknown`; sender uses provider abstraction and no exactly-once claim. | Never auto-retry ambiguous sends. Require operator verification and an explicit, audited decision; accept possible duplicate if manually retried. |
| P81-07 | MEDIUM — POTENTIAL | Provider request is made while locked transaction is held in AI sender. | Measure lock duration and DB pool/queue behavior in staging with a fake/delayed provider; adjust only after race-safety review. |
| P81-08 | MEDIUM — NOT VERIFIED | Gateway `tokens`, `userDataDir`, `log` state exists; deployment ACL/encryption/backup behavior not inspected. | Restrict filesystem ACLs, exclude session/token state from unencrypted/general backups, document recovery and revocation. |

### Non-blocking before production data use

| ID | Severity | Evidence | Recommendation |
|---|---|---|---|
| P81-09 | MEDIUM — CONFIRMED | Clients/Contacts/Dashboard contain local seeded/demo content. | Connect those surfaces to API or label as demo/disabled before operator use; verify no demo action is presented as persisted customer data. |
| P81-10 | LOW — NOT VERIFIED | Runtime metrics and alert configuration not found; `prom-client` dependency alone does not prove collection. | Define metrics, alerts, log retention/access controls, and incident runbook. |

## 8. Recommended order for Phase 8.2+ (not implemented)

1. Rotate the exposed gateway and callback credentials; verify secrets are not present in Flutter, logs, build artifacts, or issue/report attachments.
2. Replace the Flutter loopback base URL with controlled environment-specific HTTPS configuration and validate target-device reachability.
3. Add/verify login throttling and abuse monitoring; document token lifetime/revocation and webhook log redaction.
4. Prove production topology: Laravel and loopback-only gateway colocated as intended; worker and scheduler supervised; readiness/health checks; queue alerts.
5. Establish database backup and restore rehearsal, migration/rollback rehearsal on isolated staging, RPO/RTO, and session-state protection.
6. Run full tests/analyze/Pint only after a disposable isolated test database and fake-only provider mode are positively verified; retain test output as current evidence.
7. Remove or clearly mark demo-backed client/contact/dashboard content before allowing production operators to rely on it.
8. Conduct non-production integration verification for callback, campaign, AI, and handoff with fake providers. Real WhatsApp verification remains outside this audit.

## 9. Decision

**NOT READY for production.** The confirmed credential exposure requires rotation; Flutter's API URL is loopback-only; login has no visible throttle; and runtime operations, backup/restore, ingress controls, and deployment configuration are not proven. Phase 8.2 was not started.

## Safety and change record

- No application, test, migration, config, dependency, lockfile, baseline, or gateway source file was edited.
- No database connection/query or migration was performed.
- No app/service/queue/gateway was started.
- No QR was generated/scanned, no WhatsApp login/pairing occurred, and no real or fake campaign/message delivery was attempted.
- The only new file is this requested audit report.
- Credential incident: configured WPPConnect gateway secret and callback token appeared in the audit command output while checking configuration lines. Values are not present in this report. Treat them as exposed and rotate before production.
