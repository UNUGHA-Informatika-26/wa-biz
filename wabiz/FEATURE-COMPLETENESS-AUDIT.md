# WABiz — Feature Completeness Audit

**Tanggal audit:** 2026-10-09 (Asia/Bangkok)  
**Mode:** inspeksi read-only. Tidak ada source, database, migration, konfigurasi, lockfile, atau baseline yang diubah.  
**Cakupan:** Flutter `C:\WABiz\wabiz`; Laravel `C:\WABiz\wabiz-backend`; WPPConnect gateway `C:\WABiz\wabiz-whatsapp-gateway`.

## Batas bukti

Status merefleksikan implementasi source saat ini. Test yang dirujuk dari laporan sebelumnya adalah bukti historis, bukan hasil eksekusi audit ini. Laravel tests/migrations/DB queries dan runtime tidak dijalankan: `PHASE-8.2B1-PREREQUISITE-REPORT.md` menyatakan PHP tidak dapat membaca `vendor/autoload.php`; hanya `phpunit.xml` yang menyebut `wabiz_test`, sehingga target efektif saat ini belum dibuktikan. Flutter tests/analyze juga tidak dijalankan. Gateway package tidak memiliki test script yang valid. Tidak ada WhatsApp nyata yang dihubungi.

## 1. Ringkasan eksekutif

Matriks memuat 87 fitur spesifik: 19 SUDAH ADA — TERVERIFIKASI, 43 TERSEDIA SEBAGIAN, 7 BELUM ADA, 9 ADA MASALAH, 7 BELUM TERVERIFIKASI, dan 2 MENUNGGU KONFIGURASI EKSTERNAL. Kelompok yang paling lengkap: campaign backend/queue, conversations/history API, AI settings/context, WhatsApp connection API, serta assignment/handoff. Gap terbesar: Clients dan Contacts Flutter demo/local, Dashboard statis, Flutter base URL hardcoded loopback, belum ada bukti konfigurasi operasional production, serta consent/anti-spam campaign tidak tampak pada skema yang ditinjau. Secara keseluruhan project **belum dapat dinyatakan lengkap atau production-ready**.

Status “SUDAH ADA — TERVERIFIKASI” mensyaratkan source path dan bukti test relevan yang pernah dilaporkan; itu bukan klaim runtime live atau test ulang pada audit ini. Kode yang tampak tersambung tetapi kurang bukti runtime/operasional dikategorikan “TERSEDIA SEBAGIAN”.

## 2. Arsitektur dan teknologi

| Layer | Bukti | Batas |
|---|---|---|
| Flutter | `pubspec.yaml` constraint Dart `^3.13.4`; `lib/core/network`, `lib/features/*`; auth, AI, campaign, WhatsApp, Inbox, CS, Clients, Contacts, Dashboard | Versi SDK Flutter/Dart terpasang tidak diperiksa dengan menjalankan SDK. |
| Laravel/PHP | `composer.lock`: Laravel `13.34.0`; `composer.json`: PHP `^8.3`; Phase 8.2B1 mencatat PHP `8.5.11` | Bootstrap runtime terhalang `vendor/autoload.php` tidak terbaca. |
| Gateway | `package.json`: `@wppconnect/server` `2.10.30`; Node `22.22.2` tercatat pada pemeriksaan sebelumnya. `launcher-loopback.js` pins `127.0.0.1:21465`, `startAllSession:false`, management gate | Tidak start proses/socket. |
| Auth/API | `routes/api.php`: Sanctum untuk API aplikasi; login `throttle:login`; callback route khusus; AuthController/Sanctum; FE `AuthService`, `AuthStorage` (`FlutterSecureStorage`) | Tidak diuji HTTP live. |
| Tenant | controllers/services menurunkan organization dari authenticated user; callback session→connection→organization; migrations FK/index | Tidak ada test/DB audit baru. |
| DB | PostgreSQL pada `phpunit.xml`; migrations untuk org/users/clients/WA/conversations/messages/AI/departments/CS/campaigns/webhook/handoff/jobs/tokens | Isi DB/schema terpasang tidak di-query. |
| Queue/scheduler | `config/queue.php`; `routes/console.php` menjadwalkan `DispatchDueCampaigns` setiap menit; job campaign/AI/handoff | Worker/cron tidak diverifikasi. |
| External providers | `WhatsAppProviderInterface` registry Meta Cloud + WPPConnect; AI provider/orchestrator Gemini | Kredensial dan delivery live tidak diperiksa. |
| Tests | Laravel `tests/Feature` mencakup auth, AI, campaign, client, conversation/handoff, CS, connection/outbound/webhook/provider; Flutter tests mencakup auth/campaign/client/AI/CS/Inbox/QR | Test discovery bukan test run. |

## 3. Matriks fitur lengkap

Kolom bukti menggunakan path/class/API/migration/test nyata. Prioritas P0 = keamanan/blocker produksi, P1 = fondasi, P2 = fungsi penting, P3 = tambahan. `R` berarti laporan test historis; bukan dijalankan ulang.

| ID | Kelompok | Fitur spesifik | Status | Bukti file/API/DB/test | Yang sudah ada | Kekurangan | Pekerjaan tersisa | Prioritas |
|---|---|---|---|---|---|---|---|---|
| A1 | WhatsApp | Tambah koneksi organisasi | TERSEDIA SEBAGIAN | BE `WhatsAppConnectionController::store`, `POST /api/whatsapp/connections`; FE connection service/page; `whatsapp_connections` | Auth + tenant-scoped creation | Belum runtime-verified dari device | Uji API terisolasi | P1 |
| A2 | WhatsApp | Start dan minta QR via Flutter→Laravel→WPP | TERSEDIA SEBAGIAN | Controller start/qr; `WppConnectGateway`; FE QR service; QR widget; `WhatsAppConnectionApiTest`, `whatsapp_connection_qr_test.dart` | QR API dan tampilan tersedia; secret backend-side | Tidak ada gateway runtime/pairing test | Synthetic local lifecycle; pairing manual terpisah | P1 |
| A3 | WhatsApp | QR benar-benar terlihat pada Flutter device | BELUM TERVERIFIKASI | FE QR page/widget dan API service | UI menampilkan payload QR | Widget test bukan device/runtime proof; tak dijalankan kini | Device test dengan QR sintetis | P2 |
| A4 | WhatsApp | Scan QR / login / nomor connected terdeteksi | BELUM TERVERIFIKASI | Gateway session lifecycle, connection status/phone fields; Phase 8.2 reports | Kode simpan status/nomor tersedia | Tidak scan/login; no live paired number | Manual approved pairing only when requested | P2 |
| A5 | WhatsApp | Status connected/disconnected/connecting/QR/error | TERSEDIA SEBAGIAN | Migration status check; controller status/refresh; FE status UI | State model dan API tersedia | Tidak ada status runtime real | Local gateway status smoke test, no pairing | P1 |
| A6 | WhatsApp | Banyak nomor dan sesi terisolasi | TERSEDIA SEBAGIAN | `gateway_session_ref` unique; session→connection resolver; organization scope | Per connection mapping ada | Multi-session runtime isolation belum diuji | Synthetic two-session/two-tenant test | P1 |
| A7 | WhatsApp | Reconnect dan disconnect | TERSEDIA SEBAGIAN | `WppConnectGateway` reconnect/status/disconnect; connection controller/FE | Actions tersambung lewat API | Tidak ada live lifecycle test | Fake provider + runtime local status | P2 |
| A8 | WhatsApp | Endpoint management/security gateway | TERSEDIA SEBAGIAN | `launcher-loopback.js` gates start-all/clear-session-data; loopback bind | Guard source aktif | Tidak cek listener/firewall runtime saat audit | Operator verify deployment binding | P0 |
| A9 | WhatsApp | Callback WPP→Laravel | TERSEDIA SEBAGIAN | WPP `dist/config.js`, `dist/util/functions.js`; BE webhook handler/session mapping | Event payload route dan persistence source ada | Runtime belum diverifikasi; URL-token risk | HTTP synthetic runtime setelah isolation dan log redaction | P0 |
| A10 | WhatsApp | Auth gateway/current & previous credential | BELUM TERVERIFIKASI | 8.2A/8.2B reports; comparison statis config previously | Rotasi lokal dicatat | Current acceptance/old rejection tidak pernah runtime-tested | Redacted verification using safe endpoint when approved | P0 |
| B1 | Campaign | Create/edit/delete draft | SUDAH ADA — TERVERIFIKASI | FE `CampaignApiService`/page; `CampaignController`, `CampaignService`; DB migrations; `CampaignApiTest` R | CRUD API-backed; edit/delete draft guards | No rerun in this audit | Re-run serial when backend available | P1 |
| B2 | Campaign | Recipients, phone normalization, tenant ownership | SUDAH ADA — TERVERIFIKASI | `CampaignService::create/update`, campaign recipient schema, provider job; R `CampaignApiTest` | Connection/client ownership and duplicate recipient protection | Opt-in/consent not found in inspected schema/model | Add consent policy before production broadcast | P0 |
| B3 | Campaign | Immediate send through queue/provider | SUDAH ADA — TERVERIFIKASI | `sendNow`, `SendCampaignRecipient`, registry; `CampaignQueueRuntimeTest` R | Queue/provider result updates recipient state | Real delivery/worker not verified | Repeat fake-provider test; deploy worker | P1 |
| B4 | Campaign | Scheduled campaign | TERSEDIA SEBAGIAN | `schedule`, `DispatchDueCampaigns`, `routes/console.php` every minute; FE schedule UI | Persistent schedule + scheduler source | No cron/scheduler service evidence | Deploy/monitor scheduler and run staging test | P1 |
| B5 | Campaign | Status, recipient progress, history | SUDAH ADA — TERVERIFIKASI | migrations/models; `GET /campaigns`, recipients pagination; FE page | Campaign and per-recipient states include pending/sending/sent/failed/unknown and terminal campaign statuses | Runtime statuses not checked; delivery receipt support provider-dependent | Verify per provider | P2 |
| B6 | Campaign | Retry, timeout, duplicate, ambiguous outcome | SUDAH ADA — TERVERIFIKASI | stale recipient reconcile, job guards, fake provider tests R | Ambiguous state `unknown`, no blind retry | Manual result reconciliation/unknown can require operator action | Document operations and re-run tests | P1 |
| B7 | Campaign | Consent/opt-out/frequency cap/anti-spam | ADA MASALAH | Client and campaign schema/model inspected; no consent/opt-out fields found | No explicit control evidenced | Broadcast can select recipients absent consent state; provider rate policy not established | Define consent, suppression, per-provider throttling | P0 |
| B8 | Campaign | Flutter campaign list/create/detail/send | SUDAH ADA — TERVERIFIKASI | `CampaignApiService`, page; endpoints list/store/show/send/schedule/cancel/recipients; tests R | API-backed flow; client/connection selection calls APIs | No fresh Flutter/API test | Re-run UI/service tests | P1 |
| C1 | AI | Incoming webhook→AI processor | TERSEDIA SEBAGIAN | `WhatsAppWebhookController::receiveWppConnect`; `AiMessageProcessor::process`; `WhatsAppWebhookTest` R | Valid new inbound path persists and invokes AI | No runtime callback or live model call | Synthetic HTTP/fake AI; no WhatsApp | P1 |
| C2 | AI | Activation/deactivation per organization | SUDAH ADA — TERVERIFIKASI | AI settings controller/page/model; processor checks settings `enabled`; tests R | Organization-level toggle | Per-connection toggle not found | Decide whether needed per number | P2 |
| C3 | AI | Conversation context and institution knowledge | SUDAH ADA — TERVERIFIKASI | `AiContextBuilder::build`; active history generation; settings model; `AiMessageProcessorTest` R | Scoped context includes history + organization settings | No runtime model grounding test | Context-focused tests and model eval | P1 |
| C4 | AI | AI reply persisted, queued, sent through provider | TERSEDIA SEBAGIAN | `AiMessageProcessor`, `SendAiConversationMessage`, `WhatsAppMessageSender`, provider registry; Phase 7.3 R | Outbound row and queued provider path exist | Live credentials/delivery absent; no fresh test | Fake-provider regression then approved staging provider | P1 |
| C5 | AI | Duplicate/stale AI suppression and delivery status | SUDAH ADA — TERVERIFIKASI | source message id/unique guards; current status/generation checks; Phase 7.3 R | `sent/failed/unknown`; takeover/reset suppresses stale job | No exactly-once provider guarantee; lock held during provider call | Monitor lock time; handle unknown manually | P1 |
| C6 | AI | AI fallback / unknown facts | TERSEDIA SEBAGIAN | `GeminiAiProvider`, deterministic orchestrator, `AiResponseSafetyPolicy` | Prompt says authoritative facts only/no invention; fallback path exists | Live Gemini failures not tested; model behavior probabilistic | Fake error tests, controlled model evaluation | P1 |
| C7 | AI | AI key/model configuration | MENUNGGU KONFIGURASI EKSTERNAL | `config/services.php`, Gemini provider | Server-side env/config reference; no Flutter key | Current key validity/model access not inspected | Operator validates secret manager without exposing values | P1 |
| D1 | AI Settings | Nama/deskripsi lembaga | TERSEDIA SEBAGIAN | model/request/controller/migration/UI; `AiContextBuilder` | Persisted and inserted in context | No runtime update→answer proof | Test settings update/context | P1 |
| D2 | AI Settings | Count siswa/pengasuh/guru | TERSEDIA SEBAGIAN | `student_count`, `caregiver_count`, `teacher_count` across model/request/UI/context | Data fields available to AI | No runtime response verification | Context grounding tests | P1 |
| D3 | AI Settings | Jenis/jenjang pendidikan | BELUM ADA | inspected settings model/request/UI field inventory | Could be placed in FAQ/free text | Dedicated structured field not found | Define taxonomy/schema only if required | P2 |
| D4 | AI Settings | Alamat, maps, phone, website, hours | TERSEDIA SEBAGIAN | fields in settings model/request/page/context | Stored and passed to AI | Live freshness/response unverified | API/context regression tests | P1 |
| D5 | AI Settings | Program dan fasilitas | TERSEDIA SEBAGIAN | `services`, `additional_information`, FAQs | Free-text knowledge available | Not represented as distinct structured fields | Decide whether structure required | P2 |
| D6 | AI Settings | Biaya dan payment details | TERSEDIA SEBAGIAN | FAQs/additional_information and anti-invention prompt | Can be configured as text facts | No dedicated fee/payment schema and validation | Define official fee source/fields | P1 |
| D7 | AI Settings | Syarat/prosedur pendaftaran | TERSEDIA SEBAGIAN | registration description/FAQ/additional info | Facts can be stored as text | No structured admission requirements/procedure model | Define fields or use documented FAQ policy | P1 |
| D8 | AI Settings | Google Form/registration URL | SUDAH ADA — TERVERIFIKASI | `registration_url`; settings UI/request/controller/context/orchestrator; tests R | Uses org persisted URL, absent link not invented | Runtime answer not live-tested | Repeat fake provider/intent tests | P1 |
| D9 | AI Settings | FAQ CRUD | TERSEDIA SEBAGIAN | AI settings page FAQ editor; `faqs` validation/model/context | UI CRUD and settings payload exist | No test executed now; model consumption not runtime verified | Run settings/context tests | P1 |
| D10 | AI Settings | Model/provider/system prompt controls | ADA MASALAH | `GeminiAiProvider`, settings request/UI | Admin has additional instructions/style/language | Provider/model selected through backend config; no explicit model admin control | Decide controlled provider selection requirement | P2 |
| D11 | AI Settings | AI toggle per connection | BELUM ADA | Processor uses org setting; `WhatsAppConnection` inspected | Org toggle available | No connection-level `ai_enabled` path found | Decide product policy | P2 |
| E1 | Pendaftaran | Intent pendaftaran dari bahasa alami | TERSEDIA SEBAGIAN | `RegistrationIntentClassifier::classify`; regex unit/orchestrator tests R | Daftar/mendaftar/registrasi serta beberapa “ingin/mau masuk/jadi/join” tercakup | Frasa “syarat”, “jadwal dibuka”, atau “minta formulir” saja mungkin tidak terdeteksi | Tambah contoh bahasa dan false-positive tests | P1 |
| E2 | Pendaftaran | Link resmi atau link belum dikonfigurasi | SUDAH ADA — TERVERIFIKASI | deterministic orchestrator + `registration_url` + `AiResponseSafetyPolicy`; tests R | Link dari settings organisasi; data kosong ditangani tanpa URL rekaan | Tidak ada runtime/live model proof | Re-run fake tests and safety checks | P1 |
| E3 | Pendaftaran | Lead/prospect, ticket, tindak lanjut | BELUM ADA | migrations/models inventory; no lead/ticket entity found | Conversation/Client bisa menahan konteks dasar | Tidak ada status pendaftar/lead/follow-up tersendiri | Tentukan lifecycle lead | P2 |
| E4 | Pendaftaran | Routing ke divisi dan CS aktif | TERSEDIA SEBAGIAN | `RegistrationHandoffResolver`, orchestrator, department/member models | Konfigurasi department dapat memilih member aktif | Jika tidak ada CS aktif, fallback menunggu manusia; antrean lead tidak ada | Tetapkan SOP no-CS case | P1 |
| F1 | Inbox | List/detail dari backend | SUDAH ADA — TERVERIFIKASI | `ConversationController`; `ConversationApiService`; FE Inbox/detail; `ConversationApiTest` R | API-backed list/detail | No fresh test | Re-run when environment restored | P1 |
| F2 | Inbox | Riwayat inbound/outbound, timestamp/status/source | TERSEDIA SEBAGIAN | messages endpoint serializes direction/sender/type/time/status; FE detail renders sender/time | Messages stored and differentiated | Media, receipts, and source semantics not established for all providers | Define supported message types/statuses | P2 |
| F3 | Inbox | Search client name/phone | SUDAH ADA — TERVERIFIKASI | `ListConversationsRequest`; controller PostgreSQL `ILIKE`; FE search | API-backed search | No DB runtime test now | Focused query test | P2 |
| F4 | Inbox | Filter connection/status/unread/division/CS | SUDAH ADA — TERVERIFIKASI | controller query + FE filters; tests R | Connection/status/unread/department/member filters in API/UI | Date filter absent; current test not run | Re-run filters and permission tests | P1 |
| F5 | Inbox | Filter tanggal | BELUM ADA | `ListConversationsRequest`/controller filter inspection | Sorting by recent activity | Date-range filter not found | Add only if product requires | P3 |
| F6 | Inbox | Conversation/message pagination | SUDAH ADA — TERVERIFIKASI | API `paginate(20)`/`paginate(50)`; FE page controls; tests R | Server pagination | No current runtime query | Re-run ordering/no-missing tests | P1 |
| F7 | Inbox | Unread/read state | TERSEDIA SEBAGIAN | webhook increments `unread_count`; markRead API zeroes it; FE badge | Idempotent inbound + read endpoint | Refresh/realtime behavior not confirmed; no active polling observed | Decide refresh SLA and test unread lifecycle | P2 |
| F8 | Inbox | Duplicate webhook/message protection | SUDAH ADA — TERVERIFIKASI | webhook event table/idempotency; unique external identity; `WhatsAppWebhookTest` R | Same event guarded | Gateway callback runtime not tested | Repeat synthetic callback test | P1 |
| F9 | Inbox | Reset history | SUDAH ADA — TERVERIFIKASI | reset endpoint increments `history_generation`; context builder scopes active generation; UI confirms old history retained | Old rows preserved; AI context boundary moves | No separate historical archive UI; reset isn’t destructive erase | Clarify retention and admin-history access | P1 |
| F10 | Inbox | Organization isolation | TERSEDIA SEBAGIAN | Controller scoping + historical tenant tests | User organization scopes resources | No exhaustive fresh test across all endpoint paths | Run full cross-tenant suite safely | P0 |
| G1 | CS | Department create/edit/activate/deactivate | TERSEDIA SEBAGIAN | `DepartmentController`, model/migration, FE CS management | API-backed department config | Fresh test not run; archive/delete referenced item semantics require regression | Re-run CRUD/constraints | P1 |
| G2 | CS | CS member, name/phone/division/active | TERSEDIA SEBAGIAN | `CsMemberController`, request, model, normalizer, FE CS management | Member settings persist and phone normalized | No per-member workload/SLA view | Test CRUD/deactivation assignment effects | P1 |
| G3 | CS | Link CS to authenticated account | SUDAH ADA — TERVERIFIKASI | `linkableUsers`, `update`; organization-scoped user validation/locks; Phase 6.2/7.3 tests R | Admin links existing active same-tenant user; duplicate guarded | No current fresh test; full account provisioning not implied | Re-run identity authorization tests | P1 |
| G4 | CS | Invite/provision new account | BELUM TERVERIFIKASI | Auth routes/User model inspected; no invitation route found | Linking existing identity works | External provisioning outside project unknown | Confirm organization onboarding mechanism | P3 |
| G5 | CS | Roles/permissions, division ACL | TERSEDIA SEBAGIAN | Sanctum, User roles, gates `manage/access-organization-operations` | Owner/admin/staff gates exist | No granular division-level policy evidenced | Decide CS role and division visibility boundaries | P0 |
| G6 | CS | Assign/reassign and responsibility list | TERSEDIA SEBAGIAN | conversation assignment API/service; Inbox filters by assigned member | Assigned member/division persist; reassign guarded | No separate workload/SLA/“my queue” management flow identified | Define needed queue view | P2 |
| G7 | CS | Follow-up and operational statuses | TERSEDIA SEBAGIAN | conversation state transitions/handoff fields | AI/human/resolve/archive statuses exist | Separate follow-up task/status not found | Define follow-up model if needed | P2 |
| H1 | Handoff | Intent→department→assigned CS | TERSEDIA SEBAGIAN | orchestrator/resolver/handoff service | Registration routing configured and server-resolved | Limited intent/routing rule set | Add routing rules only after product policy | P1 |
| H2 | Handoff | AI stops after transfer / human reply guard | SUDAH ADA — TERVERIFIKASI | processor eligibility, `ConversationReplyAuthorizer`, transition and sender state checks; Phase 7.3 fake tests R | Human states stop autonomous response; assigned CS auth required | Not live-race-tested in this audit | Repeat fake-provider race tests | P0 |
| H3 | Handoff | Persisted notification and sender/recipient | TERSEDIA SEBAGIAN | `SendConversationHandoffNotification`; notification table/job; provider abstraction | Organization connection sends to configured CS recipient number | Fake provider only; no real delivery/receipt | Re-run fake delivery and operation metrics | P1 |
| H4 | Handoff | Notification context: client/division/CS/reason/history/link | TERSEDIA SEBAGIAN | job composes client number/name, department/member, reason/time, recent active-generation snippets, safe configured URL | Important context included | Recent snippets, not generated summary; no formal ticket | Confirm privacy and context policy | P2 |
| H5 | Handoff | sent/failed/unknown/sending recovery | TERSEDIA SEBAGIAN | notification status model/migration/controller/Flutter recovery | Persisted states and manual recovery controls | unknown retry may duplicate; stale sending heuristic cannot prove provider did not accept | Keep operator-confirmed retry and document duplicate risk | P0 |
| H6 | Handoff | Transfer customer thread to CS-owned WA session | BELUM ADA | Handoff retains same conversation and notifies CS via org connection | Assignment and notification function | No evidence customer conversation physically moves to CS device/session | Confirm requirement; current semantics are routed Inbox + notify | P2 |
| H7 | Handoff | Audit trail for all transitions | TERSEDIA SEBAGIAN | notification attempt metadata; log calls | Some actor/time/reason recorded | No general immutable conversation audit event framework found | Define audit retention/security | P1 |
| I1 | Security | Login/logout/token storage | TERSEDIA SEBAGIAN | AuthController, Sanctum routes; Flutter `AuthService`, `FlutterSecureStorage`; auth tests R | Login and logout/token paths exist | No fresh runtime token expiry/device test | Verify production token lifecycle | P1 |
| I2 | Security | Login rate limiting | TERSEDIA SEBAGIAN | named throttle in `routes/api.php`/`AppServiceProvider`; Phase 8.2A report | normalized identity+IP, limit 10/min per historical implementation/test | Not rerun; proxy rate limiting/monitoring unknown | Confirm edge protection in deployment | P0 |
| I3 | Security | Gateway/callback credential rotation | MENUNGGU KONFIGURASI EKSTERNAL | 8.2A rotation report + 8.2B config consistency report | Local rotation recorded and values compared without disclosure | Runtime current accepted/old rejected not verified; previous audit logs reported exposure | Operator verifies secret store and incident retention | P0 |
| I4 | Security | Callback token secrecy/authentication | ADA MASALAH | URL token route + `hash_equals`; WPP helper no callback header; 8.2B raw Axios error concern | Shared bearer-token check and throttle | Token in URL path; possible gateway/proxy logging; not signature/HMAC | Redact request URL or use supported secure header contract later | P0 |
| I5 | Security | Tenant guards all operations | TERSEDIA SEBAGIAN | route middleware/gates and per-controller organization constraints; tests R | Major resource endpoints scoped | No current full adversarial test | Re-run full tenant test suite | P0 |
| I6 | Security | Flutter base URL dev/staging/prod | ADA MASALAH | `lib/core/network/api_client.dart:33` | Local loopback development value | Hardcoded URL unsuitable for physical device/public deployment; no flavor configuration found | Add reviewed build configuration in later implementation | P0 |
| I7 | Security | Gateway not public / firewall | TERSEDIA SEBAGIAN | launcher source pins loopback and gates management routes | Code boundary present | Current listener/firewall/process unavailable; no runtime check | Verify host deployment under operator control | P0 |
| I8 | Security | Validation, errors, safe responses | TERSEDIA SEBAGIAN | Form Requests/controllers and Flutter Dio errors | Main forms/routes validate data | Not all API error envelopes/log redaction audited dynamically | Staging error/security review | P2 |
| J1 | Dashboard | Metrics dari API/database | ADA MASALAH | FE `dashboard_page.dart` static `_campaigns`, `_activities`, demo counts/text | Demo summary cards | Tidak ada dashboard aggregate API; angka bukan database truth | Buat metric endpoint setelah definisi metric | P1 |
| J2 | Dashboard | Navigasi pengaturan | ADA MASALAH | `dashboard_page.dart` tab list/index dispatch | Sidebar/tab ada | Tab “Pengaturan” pada selected index 6 membuka `CsManagementPage`; route settings tidak cocok | Koreksi mapping navigasi | P1 |
| J3 | Clients | List/search/filter/status/detail nyata | ADA MASALAH | FE `ClientsPage` memanggil `Client.mockData()`; BE `ClientController` punya CRUD/pagination | Backend client API dan tests tersedia | Flutter production page local/demo | Integrasikan API repository | P1 |
| J4 | Clients | Add/edit/delete dari UI ke Laravel | ADA MASALAH | FE local `setState`; BE store/update/delete org-scoped, deletion guard untuk conversation | Backend mutation aman | UI mutations tidak persist ke server | Hubungkan form dan detail ke API; test tenant | P1 |
| J5 | Contacts | Contact list CRUD/search/filter | ADA MASALAH | FE `ContactsPage` `_contacts` hardcoded/local state; no Contacts route/controller ditemukan | Demo view/CRUD lokal | Tidak ada backend API path | Putuskan Contacts sebagai Client view atau domain terpisah | P2 |
| J6 | Analytics | Server-backed analytics | BELUM ADA | Route/API inventory dan `lib/features` inventory; no analytics endpoint found | Dashboard placeholder only | Tidak ada statistics API/source of truth | Definisikan metrik lalu endpoint/UI | P2 |
| K1 | Production | Effective environment config | BELUM TERVERIFIKASI | `config/services.php`, `.env.example`; actual `.env` tidak dibaca | Config keys/hooks ada | Runtime values/credentials sengaja tidak diekspos | Owner verifies secret store/config redacted | P0 |
| K2 | Production | Queue worker/scheduler active | TERSEDIA SEBAGIAN | `config/queue.php`; job classes; `routes/console.php` | Jobs + campaign schedule source exists | Tidak ada bukti process supervisor/cron berjalan | Deployment runbook, health, restart policy | P0 |
| K3 | Production | Logging/monitoring/alerts | TERSEDIA SEBAGIAN | Laravel log calls; gateway `prom-client` dependency | Some logs and metrics library | Dashboard, alert rules, sink retention/redaction unknown | Configure and test alerts safely | P0 |
| K4 | Production | Health/readiness | TERSEDIA SEBAGIAN | `GET /api/health` route; package metrics dependency | Liveness endpoint source exists | No runtime check/dependency readiness depth unknown | Define DB/queue readiness checks | P2 |
| K5 | Production | Backup/restore | BELUM ADA | Checked project root/docs/migrations; no backup/restore runbook found | Schema migrations exist | No repository evidence of backup schedule or restore drill | Establish RPO/RTO, backup and restore test | P0 |
| K6 | Production | Deployment/migration rollback | BELUM TERVERIFIKASI | Laravel migration set and generic README | Migration files exist | No deployment pipeline/runbook/fresh-database rehearsal verified | Stage deploy/rollback rehearsal | P0 |
| K7 | Production | Provider quotas/reconnect/failure ops | TERSEDIA SEBAGIAN | provider/job abstractions and status handling | Per-job failure/ambiguous states modeled | Global quota/backoff/reconnect operational policy unknown | Set provider limits and alerts | P1 |
| K8 | Production | Stuck queue/process recovery | TERSEDIA SEBAGIAN | stale campaign/AI/handoff reconciliation endpoints/jobs | Some stale delivery protection | General worker health/restart/recovery not verified | Runbook and staging fault test | P1 |
| K9 | Production | Gateway profile/session lifecycle | BELUM TERVERIFIKASI | Gateway includes profile/user-data directories; contents not opened | Persistent profile directories exist | Session/auth contents, encryption, isolation, backup not assessed | Secure operator inventory; no assumption session active | P0 |

## Status ringkas

Jumlah status sama dengan seluruh 87 baris matriks: 19 SUDAH ADA — TERVERIFIKASI; 43 TERSEDIA SEBAGIAN; 7 BELUM ADA; 9 ADA MASALAH; 7 BELUM TERVERIFIKASI; 2 MENUNGGU KONFIGURASI EKSTERNAL.

## 4. Fitur yang sudah ada

### Source path + test history mendukung

- Campaign draft CRUD, recipient validation, immediate queue dispatch, recipient status and ambiguity guard (`CampaignController`, `CampaignService`, recipient job/migrations; Phase 7.3 historical campaign suite: 12 tests/48 assertions).
- Conversation API, list/detail/messages pagination, Inbox API connection, reset-generation semantics, filter/search/unread (`ConversationController`, Flutter `ConversationApiService`/pages; historical conversation tests).
- Webhook event/message idempotency and connection-aware conversation identity (`WhatsAppWebhookController`, webhook events/migrations; historical webhook tests).
- CS linking to same-tenant existing user, assignment authorization, AI/human transition guards and notification intent (CS/handoff classes and historical Phase 6/7 tests).
- Institution settings/context builder and registration link deterministic path; AI outbound queued path through provider abstraction (AI service classes, settings UI, historical Phase 5/7.3 tests).

Bukti ini tidak membuktikan real WhatsApp, live Gemini, proses queue/scheduler, atau production deployment.

## 5. Implementasi sebagian / konfigurasi eksternal

- WhatsApp QR/status/disconnect API and UI: code exists; pairing, gateway process and number detection remain unverified.
- WPP callback: installed WPPConnect callback helper posts with `event` and `session`, Laravel resolves session and persists, but runtime callback was not sent; URL bearer token/logging risk remains.
- Campaign scheduling: schedule persists and scheduler code exists, but worker/cron runtime absent from evidence.
- AI: settings and active context path exist, but live Gemini credentials/model and answer quality are not verified.
- Handoff notification: database/job/provider path exists, but fake-provider evidence is historical; unknown manual retry may produce duplicate delivery.
- Client backend is implemented, but Flutter Clients page uses local mock; Contacts remains local.

## 6. Belum ada (dibatasi pada workspace yang diperiksa)

- Structured institution education type/level fields and per-WhatsApp-connection AI activation were not found.
- Separate registration lead/ticket/follow-up entity was not found.
- Conversation date filter was not found.
- Contacts backend API was not found.
- Server-backed analytics/dashboard endpoint was not found.
- Backup/restore runbook or verified backup infrastructure was not found.

## 7. Masalah dan risiko

### Bug/gap yang terkonfirmasi dari source

1. Clients screen uses `Client.mockData()` and local state despite backend CRUD API.
2. Contacts screen is hardcoded/local, with no backend API found.
3. Dashboard metrics/campaign/activity cards are demo constants, not API data.
4. Dashboard “Pengaturan” tab maps to CS management page.
5. Flutter API base URL is hardcoded `http://127.0.0.1:8000/api/` (`lib/core/network/api_client.dart:33`), unsuitable for device/production without other config.
6. Campaign consent/opt-out/rate policy is not evidenced in reviewed schema/services.
7. WPP callback token is in URL path, not an HMAC signature; installed callback helper has no custom-header path per historical package review. Raw Axios exception logging may include callback URL; proxy/access log configuration was unavailable.
8. Handoff notification `unknown` manual recovery has possible duplicate risk; no provider idempotency proof.

### Potensi dan belum terverifikasi

- `phpunit.xml` states `wabiz_test`; current effective connection/provider isolation was not revalidated. No tests or DB queries run.
- No live worker/scheduler, gateway listener, callback runtime, Gemini/Meta credentials, domain, log redaction, monitoring, backup/restore or firewall verification.
- Provider HTTP call is performed under conversation state locks in AI sender; historical report notes lock duration/timeout risk; no load test.
- Gateway profile/user-data directories exist; contents intentionally not inspected; no assertion that a WhatsApp session exists or is logged in.
- No `.git` metadata in any of the three project roots; code diffs, authorship and historical timing cannot be proven.

### Baseline

Manifest `C:\WABiz\wabiz\CURRENT-STATE-BASELINE-2026-10-07.sha256` hash: `827b71775cab29f3b6696650bb6e9f3b626b509088f17c5e4426d84a195e32b5`. It contains 196 syntactically valid SHA-256 entries (202 lines, six comments/blank lines). Audit recalculated each path: **162 matched, 34 mismatched, 0 missing, 0 unreadable**. Prior 7.3/8.1 reports documented 164 matched/32 mismatched. Current mismatch set adds `wabiz-backend/app/Providers/AppServiceProvider.php` and `wabiz-backend/tests/Feature/AuthFoundationTest.php`; Phase 8.2A report identifies login limiter and its test in those files, supporting a connection but not exact historical timing/authorship. Three earlier 29→32 mismatch additions remain UNVERIFIABLE per previous report: no prior per-file list or snapshot. Historical pre-Pint baseline unavailable. No file restored; manifest unchanged.

## 8. Peta dependensi

```text
Auth + organization tenant scope
 ├─ WhatsApp connection/session → gateway callback → client/conversation/message
 │   ├─ Inbox/history/read/reset
 │   └─ AI settings/context/provider → queued AI response
 └─ department + linked CS identity + assignment authorization
     └─ registration intent → handoff → persisted notification → CS reply
Campaign requires tenant-owned client/connection + consent policy + queue + scheduler + provider.
Production requires deployment URL/secrets + worker/scheduler + log redaction/alerts + tested backup/restore.
```

## 9. Roadmap implementasi (belum dilakukan)

| Urutan | Tujuan / modul | DB/API/Flutter | Acceptance | Dependensi dan risiko |
|---|---|---|---|---|
| 1. Security + campaign safety | Callback URL redaction, production API base URL, consent/opt-out, provider frequency caps | Consent schema only after policy; campaign API/UI and build config | Opted-out recipients blocked; token never logged; tenant tests | P0; owner defines consent and deployment ingress |
| 2. Safe verification restore | Authorized fix to PHP read access in vendor tree; no workaround from this audit | Avoid app schema changes | Serial tests only after verified DB/provider isolation | Permission owner action required |
| 3. API-backed Clients/Contacts | Replace demo Clients; define Contacts as client view or separate domain | Existing Clients API likely sufficient; Flutter repo/pages | API CRUD/search/tenant tests, no mocks as production source | Avoid duplicate domain |
| 4. Dashboard/navigation | Correct tab mapping; metrics from source of truth | Metric API after metric definitions; Flutter API page | Seeded DB aggregates match cards | Decide timezone and metric semantics |
| 5. Gateway callback runtime | Test session isolation, QR/status/callback HTTP | Existing API/schema likely; gateway boundary unchanged | Synthetic callback; unknown session rejected; no AI/provider side effect | Requires safe running local environment |
| 6. Institution/AI completeness | Decide structured program/fees/admission fields, per-connection AI policy | Reuse existing settings; migrations only if approved | Settings update reaches context; fake AI safety tests | Gemini credentials external; model evaluation separate |
| 7. Registration/CS operations | Decide lead/ticket/follow-up and handoff semantics | Add lead model/API only if owner approves; improve Inbox as needed | intent variants→assignment→history/notification | Depends on CS policy and linked users |
| 8. Production ops | worker/scheduler, health, alerts, log redaction, backup/restore, deployment rollback | Ops config/runbook | staging restart, restore drill, alert verification | Requires production environment/operator access |

## 10. Keputusan pemilik proyek

1. Apakah campaign wajib menolak penerima tanpa consent dan menyediakan opt-out; bagaimana consent dicatat?
2. Apakah Contacts berbeda dari Client atau hanya segment/view client?
3. Apakah AI harus dapat dinonaktifkan per nomor WA?
4. Apakah biaya, jenjang, program, fasilitas, syarat dan jadwal pendaftaran perlu field terstruktur atau cukup FAQ/knowledge teks?
5. Apakah handoff saat ini (assign CS + notification, conversation tetap pada koneksi organisasi) sudah sesuai, atau harus memindahkan percakapan ke session CS?
6. Apakah notification `unknown` boleh di-retry manual dengan risiko pesan duplicate?
7. Definisi dashboard metrics dan tanggal/timezone apa yang dibutuhkan?

## 11. Rekomendasi tahap berikutnya

Pertama pulihkan prasyarat verifikasi backend melalui pemilik environment yang berwenang; jangan ubah permission/menyiasati akses. Setelah Laravel dapat bootstrap dan `wabiz_test` serta fake provider dibuktikan, jalankan suite secara serial. Sebelum production campaign/WhatsApp, tutup consent/opt-out, callback URL log redaction, dan base URL production. Setelah itu prioritaskan integrasi Clients Flutter ke API existing serta koreksi Dashboard/navigation. Audit ini tidak mengimplementasikan perubahan tersebut.

## Pemeriksaan akhir

- Laporan lama dibaca/diperiksa: Phase 7.2 Baseline Audit, Phase 7.3 Final Acceptance, Phase 8.1 Readiness, Phase 8.2A Credential Rotation dan Implementation, Phase 8.2B Runtime Verification, Phase 8.2B1 Prerequisite.
- Source diperiksa: Flutter `lib/`, `test/`, `pubspec.yaml`; Laravel route/controller/request/service/job/model/migrations/config/manifests/`phpunit.xml`; Gateway `package.json`, `launcher-loopback.js`, installed WPPConnect callback config/helper. `.env` tidak dibuka.
- Dilakukan: listing file, `rg`, inspeksi laporan, version/manifest metadata, SHA-256 per 196 manifest paths, cek `.git` presence. Tidak dilakukan: Laravel/Flutter tests/analyze, artisan/migration, DB query, server/service start, process restart, QR/session/login, campaign/provider send.
- Tidak ada `.git` metadata di roots Flutter/backend/gateway; histori author/commit tak tersedia.
- File yang dibuat: `C:\WABiz\wabiz\FEATURE-COMPLETENESS-AUDIT.md`. Tidak ada source, database, migration, configuration, dependency, lockfile, gateway, atau manifest yang diubah.
- Tidak scan QR, pairing/login, create session, mengirim pesan, menjalankan campaign, atau memanggil live provider.

