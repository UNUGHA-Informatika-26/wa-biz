# WABiz Phase 8.2A — Implementation Report

Date: 2026-10-09 (Asia/Bangkok)
Status: **PARTIAL**

## 1. Summary

Local gateway credentials and callback token were rotated in both Laravel and WPPConnect environment files using locally generated 32-byte CSPRNG values encoded as 64-character lowercase hex. The login endpoint now uses a named Laravel rate limiter keyed by a SHA-256 of normalized email plus client IP, limited to 10 requests per minute. Focused authentication and callback HTTP tests pass against the isolated `wabiz_test` target.

This phase is **PARTIAL**, not PASS: the local WPPConnect process was not started, so old gateway-secret rejection and actual WPPConnect callback delivery with the new URL are not runtime verified. The callback feature test rejects a synthetically invalid token and accepts the currently configured token, but it does not prove that the exact pre-rotation token was rejected. No real WhatsApp operation occurred.

## 2. Files changed

1. `C:\WABiz\wabiz-backend\.env` — rotated `WPPCONNECT_SECRET_KEY` and `WPPCONNECT_WEBHOOK_TOKEN`; no credential value is recorded here.
2. `C:\WABiz\wabiz-whatsapp-gateway\.env` — rotated `SECRET_KEY` and changed the configured `WEBHOOK_URL` path token to match Laravel.
3. `C:\WABiz\wabiz-backend\app\Providers\AppServiceProvider.php:47-53` — added named `login` limiter, using normalized email + IP hash and 10/minute.
4. `C:\WABiz\wabiz-backend\routes\api.php:22` — attached `throttle:login` to `/auth/login`.
5. `C:\WABiz\wabiz-backend\tests\Feature\AuthFoundationTest.php:43` — test for threshold, separate email/IP keys, and expiry.
6. `C:\WABiz\wabiz-backend\tests\Feature\WhatsAppWebhookTest.php:332` — synthetic Laravel HTTP test for invalid callback rejection and configured-token acceptance.
7. `C:\WABiz\wabiz\PHASE-8.2A-IMPLEMENTATION-REPORT.md` — this report.

No gateway source, Flutter source, migration, dependency, package lock, database schema, session data, or historical baseline was changed. Targeted formatting cleanup only fixed line-ending/method-spacing findings on changed PHP files; no repository-wide formatter was run.

## 3. Gateway secret rotation

- **Configuration rotation:** verified on both sides. Laravel `services.wppconnect.secret_key` reads `WPPCONNECT_SECRET_KEY` (`wabiz-backend/config/services.php:55-58`); WPPConnect 2.10.30 reads `SECRET_KEY` (`wabiz-whatsapp-gateway/node_modules/@wppconnect/server/dist/config.js:18`). In-memory comparison after final rotation confirmed equality and accepted 64-hex format; values were not printed in the final verification.
- **Secret old rejection:** **NOT VERIFIED at gateway runtime.** No WPPConnect process was running, and it was not started. Therefore the package's live management endpoint was not tested with either current or former secret. Static source confirms the secret-key contract, not runtime authentication.
- The first newly generated pair was accidentally exposed in a malformed verification output. Both sides were rotated again afterward; the final pair was checked for consistency without outputting values. Retained terminal/task logs containing earlier credentials should be reviewed and removed/restricted according to local policy. The gateway's current final secret was not printed.

## 4. Callback token rotation

- **Configuration rotation:** verified on both sides. Laravel validates the path token with `hash_equals` (`WhatsAppWebhookController.php:31-35`); the route accepts the supported token characters/length (`routes/api.php:32-34`). WPPConnect 2.10.30 reads `WEBHOOK_URL` and its callback helper POSTs to that URL without custom headers (`node_modules/@wppconnect/server/dist/config.js:27-28`, `dist/util/functions.js:123-145`). The URL-token contract was retained.
- **Laravel HTTP behavior:** one focused test passed (1 test / 7 assertions): an invalid synthetic token receives unauthorized; the configured token reaches the Laravel callback and persists a synthetic inbound message through the HTTP testing layer. AI processing is mocked and no provider is called.
- **Exact former callback token rejection:** **NOT VERIFIED.** After the first rotated token appeared in an inspection output, it was replaced by a second token and was not retained or reused for testing. The synthetic invalid-token test is not evidence about that exact former token.
- **Actual WPPConnect → Laravel callback:** **NOT VERIFIED.** The new URL/token pair is statically consistent and Laravel accepts the configured token, but the gateway process was not started and no actual gateway callback was emitted.
- WPPConnect sends the token as part of the callback URL path. No header mechanism was found in the installed callback helper. Application code inspected does not log the complete callback token, but web server, reverse-proxy, tracing, or gateway access-log redaction cannot be confirmed from this local source review.

## 5. Login rate limiting

- Named limiter is in `wabiz-backend/app/Providers/AppServiceProvider.php:47-53`; login route uses it at `routes/api.php:22`.
- Limiter key is a SHA-256 digest over normalized email and IP; this avoids a single-IP-only bucket that would throttle unrelated users behind the same NAT. Limit: 10 attempts/minute per email+IP combination.
- `AuthFoundationTest` passed **5 tests / 42 assertions**. This includes the existing valid login and invalid-credential behavior plus the new threshold, account/IP isolation, and expiry test. The test asserts Laravel's normal 429 response after the threshold.
- Flutter was not changed. No Flutter-specific error UX test was run; Laravel's route limiter uses the framework's standard throttling response.

## 6. Test database and test results

Before running tests, effective test configuration was checked: `phpunit.xml` specifies `APP_ENV=testing`, `DB_DATABASE=wabiz_test`, `QUEUE_CONNECTION=sync`, and array cache; the development `.env` database name differs. No inherited `DB_DATABASE`/`DB_URL` override and no `.env.testing` file were present. Tests were run serially. The callback test uses Laravel's HTTP feature layer, a test transaction, and a mocked `AiMessageProcessor`; it does not call WPPConnect or a WhatsApp provider.

- `AuthFoundationTest`: **5 passed / 42 assertions**.
- Rotated callback focused test: **1 passed / 7 assertions**.
- `php -l` for `AppServiceProvider.php`, `routes/api.php`, `AuthFoundationTest.php`, and `WhatsAppWebhookTest.php`: **all passed**.
- Targeted `pint --test` on those four PHP files: **passed** after fixing only reported style issues.
- Full Laravel suite, Flutter tests/analyze, migration status, and production database checks were not run; they were outside this focused scope and production/test runtime was not independently inspected.
- The configured test target was separate from the development database by effective configuration. No database reset or migration command was run; feature tests used their existing `DatabaseTransactions` pattern.

## 7. Runtime, restart, and safety

- No Laravel or gateway listener was active on the inspected local ports. Neither service was started or restarted.
- Updated `.env` values take effect when the relevant processes start/restart. Before local integration verification, start/restart Laravel and the loopback gateway under the existing launcher. Do not use a global session-start endpoint; preserve `127.0.0.1:21465`, `startAllSession:false`, and the existing request gate.
- WPPConnect callback delivery over the new URL still needs a safe local runtime check. If performed later, use a synthetic callback only; do not scan QR, login, create a WhatsApp session, or send a message.
- No campaign, outbound message, real recipient, WhatsApp login, QR scan, pairing, session creation, or provider delivery occurred.
- Gateway source/security boundary was not modified.

## 8. Baseline

- Baseline SHA-256 before work: `827b71775cab29f3b6696650bb6e9f3b626b509088f17c5e4426d84a195e32b5`.
- Baseline SHA-256 after work: `827b71775cab29f3b6696650bb6e9f3b626b509088f17c5e4426d84a195e32b5`.
- Manifest was not edited. No historical mismatch was restored or reattributed.

## 9. Remaining limitations

1. Runtime WPPConnect authentication with the new secret and rejection of the exact former gateway secret are unverified.
2. Exact former callback token rejection is unverified; only an invalid synthetic token was tested against the new configured token.
3. Actual WPPConnect callback delivery after process restart is unverified.
4. Access-log/token redaction at the local HTTP server, gateway, or any future proxy is not verified. The callback token remains in the URL by contract.
5. Prior terminal output may retain earlier exposed credential values; review and restrict/purge those logs. Final credentials are new and were not included in this report.

## 10. Final status

**PARTIAL.** Local configuration pairs are rotated and consistent, login throttling is implemented and tested, and the new Laravel callback token path is covered by a synthetic HTTP feature test. The gateway itself was intentionally left stopped, so live gateway secret rejection and callback delivery cannot be claimed. Do not mark Phase 8.2A PASS until an approved, non-messaging local gateway authentication/callback runtime check proves the remaining items.
