# WABiz Phase 8.2B — Local Runtime Verification Report

**Date:** 2026-10-09 (Asia/Bangkok)  
**Status:** **PARTIAL**

## Scope and safety

This phase performed read-only inspection, configuration comparison in memory, port/process inspection, PHP syntax checks, and an attempted isolated Laravel test. No application source, environment file, credential, gateway source, database, migration, session, or baseline manifest was changed. The only file created for this phase is this report.

No Laravel or gateway process was started or restarted. No QR was scanned, WhatsApp account logged in or paired, session created/deleted, campaign run, AI response generated, or real message sent.

## Commands and results

| Command / inspection | Result |
| --- | --- |
| Read `PHASE-8.2A-IMPLEMENTATION-REPORT.md` | Completed; prior unresolved runtime checks and prior test results recorded below. |
| Read-only PowerShell manifest/config/process-port inspection | Exit 0. Manifest hash recorded; 196 hash entries plus six header lines; config consistency booleans only; no secret values printed. No listener on ports 21465, 8000, or 8001. |
| `php artisan test --filter=AuthFoundationTest` (Laravel directory) | Exit 1: PHP reported it could not open `vendor/autoload.php`. Follow-up metadata check found the file exists, but PHP reports it is not readable. No test ran and no test database was touched. No permission workaround was attempted. |
| `php -l` on `AppServiceProvider.php`, `routes/api.php`, `AuthFoundationTest.php`, and `WhatsAppWebhookTest.php` | All four syntax checks passed. |
| Read-only `php -r` check for autoloader readability | PHP reported autoloader unavailable/unreadable. |
| Read-only process inspection | No relevant listener found. `Get-CimInstance Win32_Process` was denied by the environment; `Get-Process` returned no php/node process. Process command lines were not inspected. |
| Final manifest hash check | Same SHA-256 as before testing; see Baseline section. |

## Configuration and code-level findings

- In-memory comparison found Laravel `WPPCONNECT_SECRET_KEY` equals gateway `SECRET_KEY`; both satisfy the expected 64-character lowercase hexadecimal format. The callback token in Laravel matches the token path segment in gateway `WEBHOOK_URL`; it satisfies the route's allowed format. Values were never printed or copied into this report.
- Callback URL host is `127.0.0.1`. Laravel's gateway base URL defaults to loopback port `21465`. The launcher source pins loopback port `21465`, retains `startAllSession: false`, and contains the existing management request gates. No gateway source or security boundary was modified.
- Installed WPPConnect package version is `2.10.30`. Its callback helper adds `event` and `session` to the payload and uses an HTTP POST without custom headers. Callback authentication therefore remains a shared token in the URL path; it is not a cryptographic signature.
- The Laravel WPPConnect callback compares the supplied token using `hash_equals`, checks payload size/shape, maps the session to an enabled WPPConnect connection, and resolves organization through that connection. For a valid private inbound text event it persists the event/message and then invokes `AiMessageProcessor`. Consequently, a live valid inbound-shaped callback could trigger downstream AI/provider behavior; no such runtime request was made.
- WPPConnect exposes a GET `show-all-sessions` route, but its controller response includes session tokens. It was therefore not treated as a safe authentication probe and was not called. The gateway was also not running.
- The callback helper logs a raw Axios exception object on callback failure. Axios errors may include request configuration such as the callback URL. Because that URL contains the callback token, this inspection cannot certify that gateway error logs are token-free. No failure was induced and no gateway source was changed.
- The callback token is also part of the URL path. Access-log, reverse-proxy, tracing, and monitoring redaction were not verifiable from the available local source. Do not describe this shared-token mechanism as signed or cryptographically authenticated.

## Login rate limiter

**Code-level result: PASS. Runtime/test execution in this phase: BLOCKED.**

`AppServiceProvider::boot()` defines the named `login` limiter. It trims and lowercases the submitted email, combines that identity with the request IP, hashes the combined key with SHA-256, and applies a limit of 10 requests per minute. `/api/auth/login` uses `throttle:login`.

`AuthFoundationTest` contains assertions for the 10-attempt threshold, blocking the next request, independent buckets for a different IP and a different email, and expiry after 61 seconds. In the Phase 8.2A report, that test class previously passed 5 tests / 42 assertions. It could not be rerun in Phase 8.2B because PHP could not read the Laravel autoloader. No fresh Phase 8.2B runtime claim is made.

## Callback authentication

**Configuration/code-level result: PASS. Runtime result: BLOCKED.**

The Phase 8.2A report records a Laravel HTTP feature test that rejected an arbitrary invalid synthetic token and accepted the currently configured token, with 1 test / 7 assertions. The accepted synthetic private inbound event used a mocked `AiMessageProcessor`; it did not exercise a live AI provider or WhatsApp provider. This is an in-process Laravel feature test, not a live HTTP server or WPPConnect callback.

- Current gateway secret acceptance against a running WPPConnect endpoint: **BLOCKED / NOT VERIFIED**; no safe running gateway endpoint was available.
- Exact previous gateway secret rejection: **BLOCKED / NOT VERIFIED**; the exact former value is unavailable through an approved safe source and was not recovered from old output/history.
- Current callback token acceptance by the running Laravel HTTP server: **BLOCKED / NOT VERIFIED**; no Laravel listener was running, and the callback can invoke the AI processor on a valid inbound event.
- Exact previous callback token rejection: **BLOCKED / NOT VERIFIED**; the prior test used an invalid synthetic value, not the exact previous token. The old token was not recovered or tested.
- Arbitrary invalid callback token rejection: previously verified by the Phase 8.2A synthetic feature test, not rerun here because the test bootstrap was unreadable to PHP.

## Test isolation and provider safety

Static configuration inspection confirmed `phpunit.xml` sets `APP_ENV=testing`, PostgreSQL database `wabiz_test`, array cache, and synchronous queue. The backend development database name differs. No `.env.testing` file and no inherited `DB_DATABASE` or `DB_URL` override were present. Relevant tests use `DatabaseTransactions`; the callback test replaces `AiMessageProcessor` with a mock. The auth test has no WhatsApp/provider path.

This establishes intended test configuration, but a live database connection could not be verified because tests did not bootstrap. No broad suite, migration, migration-status command, or database operation was run. The Phase 8.2A report records earlier isolated results (AuthFoundationTest 5/5, 42 assertions; callback test 1/1, 7 assertions), but they are not fresh Phase 8.2B executions.

## Runtime and restart requirements

No listener was found on `127.0.0.1:21465`, `:8000`, or `:8001`. Process-list inspection was partially restricted as noted above. Laravel could not bootstrap through PHP because `vendor/autoload.php` is unreadable to PHP. No process was started or restarted.

Updated environment values are read when each application process initializes. A future runtime verification requires an approved restart/start of the Laravel API and the existing loopback gateway launcher. Starting the gateway can initialize its server and session-management code, so it requires explicit approval even with `startAllSession: false`. A safe runtime plan should use the existing gateway launcher from `C:\WABiz\wabiz-whatsapp-gateway` and the project's established Laravel API startup procedure, bind only to loopback, and avoid all session-start, QR, login, send, campaign, and session-deletion routes. Recovery would be to stop only the specifically identified processes that were started for that approved check. No command was executed to start either service.

**Human approval required before any process start/restart.** The current environment also requires the autoloader permission issue to be resolved through an authorized mechanism before Laravel tests or runtime can be verified; no workaround was attempted.

### Proposed commands for a later approved runtime check (not executed)

1. From `C:\WABiz\wabiz-backend`: `php artisan serve --host=127.0.0.1 --port=8000`. Expected impact: starts a local Laravel API listener only on loopback; it does not itself request a QR or send a message. It is currently expected to fail until PHP can read `vendor/autoload.php`. Recovery: stop only the process started for this check with `Ctrl+C`, then verify the listener is gone.
2. From `C:\WABiz\wabiz-whatsapp-gateway`: `node launcher-loopback.js`. Expected impact: starts the existing gateway on `127.0.0.1:21465`; launcher retains `startAllSession: false` and the existing management gates. It still starts a service and may initialize browser/session-management components, so it requires approval. Recovery: stop only the identified launcher process with `Ctrl+C` and verify the loopback listener is gone. Do not call session start, QR, logout, clear-session, or send endpoints.

Approval for these commands would not by itself make a callback request safe. Before any valid callback runtime request, callback logging must be reviewed/redacted and the payload must be constrained to a non-mutative event that cannot invoke AI or outbound processing. The installed package's raw Axios exception logging and the lack of a safe token-probe endpoint remain blockers to claiming callback runtime verification.

## Baseline integrity

- Manifest: `C:\WABiz\wabiz\CURRENT-STATE-BASELINE-2026-10-07.sha256`
- SHA-256 before checks: `827b71775cab29f3b6696650bb6e9f3b626b509088f17c5e4426d84a195e32b5`
- SHA-256 after checks: `827b71775cab29f3b6696650bb6e9f3b626b509088f17c5e4426d84a195e32b5`
- Manifest has six non-hash header lines and 196 syntactically valid hash entries. It was not edited or regenerated.
- This phase did not recalculate every tracked file's digest; it makes no claim that historical mismatches are resolved or that all files match the baseline. No source/config changes were made in Phase 8.2B. This new report is the sole Phase 8.2B artifact and is not a baseline reconciliation.

## Findings by severity

### High — runtime credential checks unavailable

Neither new nor exact previous gateway credentials were tested against a live gateway. Exact former credential rejection remains unverified. No service was started, consistent with the approval gate.

### High — Laravel runtime and fresh tests unavailable

The autoloader exists as a file but PHP cannot read it. The targeted test command exited before running tests. No permission restriction was bypassed.

### Medium — callback token may appear in gateway error logs

The installed callback helper logs the raw Axios exception on failure while callback authentication is carried in the URL path. Whether the logger serializes the URL/token in the deployed log sink was not verified. Gateway source and configuration were left unchanged.

### Medium — callback URL access-log redaction unknown

Application, HTTP server, proxy, tracing, and monitoring redaction could not be runtime-verified.

## Verification criteria summary

| Check | Result | Evidence / limitation |
| --- | --- | --- |
| Baseline manifest unchanged | PASS | Before/after SHA-256 identical; manifest not written. |
| New gateway credentials accepted at runtime | BLOCKED | No gateway listener; no service started. |
| Exact old gateway credentials rejected | BLOCKED | Exact old value unavailable safely; not tested. |
| Current callback token accepted at runtime | BLOCKED | No Laravel listener; callback may invoke AI processing. |
| Exact old callback token rejected | BLOCKED | Exact old value unavailable safely; not tested. |
| Synthetic current/invalid callback behavior | PARTIAL | Previously covered by Phase 8.2A Laravel feature test; not rerun due PHP autoloader read restriction. |
| Test isolation / external provider safety | PARTIAL | Static config and mock inspection passed; actual test bootstrap and live DB connection not verified. |
| Runtime process state | PARTIAL | No listeners found on inspected ports; CIM process listing denied, so process inventory is incomplete. |
| No real WhatsApp activity | PASS | No service, session, QR, login, campaign, or message action occurred. |
| Secret/token confidentiality in logs | PARTIAL | No values printed; installed gateway callback logger may serialize Axios error config, and access-log redaction is unknown. |
| No gateway source/security regression | PASS | Gateway files were inspected only, not modified. |

## Conclusion and remaining approval-gated steps

**Overall: PARTIAL.** Configuration pairs are consistent and syntax checks passed, but runtime authentication/callback checks and fresh database-backed tests were not completed. The exact previous credentials were not tested. Do not mark Phase 8.2B PASS.

To continue, explicit approval is needed before starting/restarting Laravel or WPPConnect. Separately, PHP must be granted authorized read access to the existing Laravel autoloader before tests can run. After that, run targeted tests serially against `wabiz_test` with the existing AI mock, then perform only non-mutative authentication checks and a callback scenario proven not to invoke AI or outbound providers. The gateway error-log token exposure concern should be reviewed in a separately approved security change; this phase did not modify gateway source.

**Phase 8.3 was not started.**
