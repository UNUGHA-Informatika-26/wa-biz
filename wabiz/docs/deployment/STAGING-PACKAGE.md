# WABiz Staging Deployment Package

Status dokumen: persiapan berdasarkan inspeksi source statis. Dokumen ini bukan bukti deployment, isolasi, atau integrasi runtime.

## 1. Arsitektur yang diusulkan

```text
Android staging app
  └─ HTTPS API base URL (WABIZ_API_BASE_URL, build-time; tanpa credential)
       └─ Laravel API + Sanctum bearer authentication
            ├─ PostgreSQL staging khusus
            ├─ queue worker + scheduler
            ├─ private WPPConnect gateway (backend-to-gateway saja)
            └─ Gemini / Meta provider (backend saja, hanya setelah persetujuan terpisah)
```

Android dipilih sebagai target awal karena source memiliki konfigurasi Android dan shared API client. Flutter Web tetap opsi terpisah: belum ada konfigurasi deployment web yang ditemukan. Hosting vendor, hostname, region, kapasitas, dan pemilik resource belum ditentukan dan tidak dibuat-buat dalam dokumen ini.

Laravel source menyediakan health routes `/up` (framework bootstrap) dan `/api/health`; keberadaan route bukan bukti bahwa staging berjalan atau aman. Backend README mendokumentasikan queue worker dan scheduler dispatcher kampanye setiap menit. WPPConnect dikonfigurasi sebagai layanan backend; batas loopback/private harus dipertahankan dan gateway tidak boleh menjadi endpoint Flutter atau internet publik. Source client AI/provider berada di backend.

## 2. Resource dan pemilik

| Resource | Pemilik/penanggung jawab yang harus ditunjuk | Bukti sebelum staging |
|---|---|---|
| HTTPS ingress, DNS, TLS certificate | Administrator hosting/jaringan | Hostname yang disetujui, cakupan sertifikat dan aturan ingress |
| Laravel application host | Administrator aplikasi | Runtime PHP/extension yang didukung source, deployment identity, process supervision |
| PostgreSQL staging | Pemilik database | Identitas cluster/instance, database khusus, hak minimum, bukti terpisah dari development/production |
| Queue backend dan worker | Operator aplikasi/database | Driver, persistence, worker supervision, failed-job handling |
| Scheduler | Operator aplikasi | Satu scheduler terkelola dan bukti jadwal; campaign dispatch tetap dinonaktifkan sampai disetujui |
| WPPConnect gateway | Administrator gateway | Host private/loopback, tidak ada public listener, secret disimpan backend-side |
| Secret manager / deployment injection | Pemilik keamanan/hosting | Mekanisme injection tanpa menaruh credential di repo, Flutter, chat, atau log |
| Backup dan pemulihan | Pemilik database/operasi | Backup sebelum migration serta bukti restore ke target terisolasi |
| Logging, monitoring, alerting | Operator aplikasi/keamanan | Retensi, redaksi, alert untuk queue dan status delivery `unknown` |
| Android staging artifact | Pemilik produk/distribusi | Package identity staging dan kanal distribusi privat yang disetujui |

Semua pemilik resource masih perlu ditetapkan. Identitas Android saat ini menggunakan application ID `com.example.wabiz`; keputusan package ID staging/produk harus dibuat sebelum distribusi, bukan diubah pada persiapan ini.

## 3. Environment variable inventory — nama saja

Nama berikut ditemukan pada source config atau API client. Ini bukan daftar nilai yang telah dikonfigurasi. Jangan menyalin nilai rahasia ke repository atau laporan.

### Flutter build-time

- `WABIZ_API_BASE_URL` — `String.fromEnvironment`; API client memerlukan URL HTTP(S) absolut yang berakhir `/api/`. Source menerapkan persyaratan HTTPS/non-loopback pada build non-debug. Isi dengan URL HTTPS staging yang diberikan dan disetujui administrator saat build; tidak ada credential di dalamnya.

### Laravel application

- `APP_NAME`, `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_KEY`
- Database: `DB_CONNECTION`, `DB_URL`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_SSLMODE`
- Queue: `QUEUE_CONNECTION`, `DB_QUEUE_CONNECTION`, `DB_QUEUE_TABLE`, `DB_QUEUE`, `DB_QUEUE_RETRY_AFTER`; Redis-backed queue configuration is conditional if that driver is selected.
- Cache/session/files: `CACHE_STORE`, `SESSION_DRIVER`, `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE`, `FILESYSTEM_DISK`
- Logging: `LOG_CHANNEL`, `LOG_LEVEL`
- Sanctum browser-stateful configuration: `SANCTUM_STATEFUL_DOMAINS` (only if a browser cookie-auth flow is deliberately used).

### Integrasi backend-only, aktifkan hanya setelah persetujuan

- WPPConnect: `WPPCONNECT_BASE_URL`, `WPPCONNECT_SECRET_KEY`, `WPPCONNECT_WEBHOOK_TOKEN`
- Gemini: `GEMINI_API_KEY`, `GEMINI_MODEL`, `GEMINI_BASE_URL`, `GEMINI_CONNECT_TIMEOUT`, `GEMINI_TIMEOUT`
- Meta WhatsApp: `WHATSAPP_META_BASE_URL`, `WHATSAPP_META_GRAPH_API_VERSION`, `WHATSAPP_META_CONNECT_TIMEOUT`, `WHATSAPP_META_TIMEOUT`

Credential nyata untuk provider tidak diperlukan untuk tes fake-provider awal. Jangan gunakan credential produksi pada staging. WPPConnect base URL harus mengarah ke layanan private yang memang disetujui; source default loopback bukan bukti topologi staging.

## 4. Android, HTTPS, Sanctum, dan Flutter Web

1. Backend harus tersedia hanya melalui HTTPS ingress yang dikelola administrator.
2. Build Android menerima `WABIZ_API_BASE_URL` sebagai `--dart-define` dan hanya berisi base API staging yang disetujui, bukan bearer token atau secret.
3. Flutter native memakai pola autentikasi bearer pada shared API client. Jangan memasukkan token tetap/provider credential ke build artifact.
4. Validasi base URL dan certificate di perangkat staging setelah deployment disetujui; hal ini belum dilakukan.
5. Jika Flutter Web dipilih kemudian, lakukan desain terpisah untuk exact-origin CORS, credentials/cookie policy, HTTPS, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`, dan secure cookies. Tidak ditemukan `config/cors.php` pada inspeksi source ini; jangan menganggap CORS siap. Jangan memakai wildcard origin dengan credential.

## 5. Operasi backend

- **Database:** staging harus terpisah secara operasional dari development database `wabiz` dan produksi. Nama database saja tidak membuktikan identitas cluster.
- **Migrations:** backup dan uji pemulihan harus disetujui sebelum perubahan schema. Consent history migration memiliki kebijakan rollback yang dapat menolak penghapusan data audit; jangan menjadikan rollback sebagai prosedur pemulihan otomatis.
- **Queue:** gunakan driver persisten yang dipilih operator, bukan `sync` untuk operasi staging operasional. Jalankan worker melalui supervisor dan siapkan inspeksi failed jobs. Campaign dispatch tidak boleh aktif mengirim ke provider nyata selama verifikasi awal.
- **Scheduler:** source menjadwalkan dispatch due campaigns setiap menit. Scheduler staging harus tetap berada di bawah kontrol operator; gunakan fake/no-send gate untuk verifikasi awal dan jangan menjalankan campaign nyata.
- **Storage:** pilih disk dan lokasi privat yang memiliki backup/retensi. Source default local; object storage hanya jika disetujui dan dikonfigurasi. Pastikan direktori storage/log dapat ditulis oleh identitas service.
- **Health:** route `/up` dan `/api/health` tersedia di source; setelah deployment, verifikasi hanya dengan prosedur runtime yang disetujui dan jangan mengekspos detail internal.
- **Callback:** callback WPPConnect harus menggunakan URL/path/token yang dihasilkan konfigurasi efektif. Proxy, ingress, tracing, dan access log wajib meredaksi path/header sensitif. Jangan menaruh token callback di Flutter. Callback sintetis untuk acceptance harus dibatasi agar tidak menjalankan AI/outbound side effect.
- **Logging/monitoring:** source menyediakan Laravel logging configuration, tetapi redaksi, retensi, alert, dan pemantauan proses belum diverifikasi. Jangan log token, provider credential, full callback URL, atau payload pesan sensitif. Buat alert untuk `unknown` dan macetnya queue; jangan otomatis retry hasil eksternal ambigu.

## 6. Fake-provider verification awal

Sebelum integrasi provider nyata, gunakan fake/in-memory provider dari jalur aplikasi yang sama dan test database disposable. Verifikasi consent grant/withdrawal, suppression, tenant authorization, queue/job decisions, callback sintetis, dan status `unknown`. Pengaman Laravel/fake tidak menggantikan egress default-deny di OS/VM/container. Tidak ada test atau callback yang dijalankan saat dokumen ini dibuat.

Kriteria awal: pesan WhatsApp nyata = tidak ada; Gemini/Meta/WPPConnect eksternal = tidak ada; queue campaign tidak boleh mencapai provider nyata. Jika jalur fake tidak terbukti menutup jalur yang diuji, hentikan.

## 7. Runbook operator dan go/no-go

### Gate 1 — Persetujuan target

- Tetapkan pemilik produk, aplikasi, database, jaringan, gateway, secret, dan backup.
- Setujui target staging, Android sebagai klien awal, hostname HTTPS, region, kapasitas, serta package identity.
- **STOP** jika target atau pemilik tidak jelas.

### Gate 2 — Isolasi dan pemulihan

- Administrator membuktikan identitas cluster PostgreSQL, lokasi/instance, database staging khusus, akun least-privilege, dan pemisahan dari `wabiz` development/production.
- Administrator membuktikan egress default-deny yang mencakup Laravel/test runner beserta child process; hanya koneksi internal eksplisit yang boleh dikecualikan.
- Buat backup/snapshot dan buktikan restore ke lingkungan terisolasi tanpa menimpa data development/production.
- **STOP** jika target database atau egress tidak terbukti.

### Gate 3 — Secret staging

- Masukkan secret staging melalui secret manager atau mekanisme deployment yang disetujui; tidak melalui chat, source, artifact Flutter, atau command/log yang mencetak nilai.
- Pastikan tidak ada credential provider produksi. Mulai dengan provider fake/deny; provider nyata tetap disabled.
- Tetapkan log redaction untuk header/token callback dan payload sensitif.
- **STOP** jika secret placement atau redaksi belum diverifikasi.

### Gate 4 — Backend deployment tanpa delivery

- Deploy release ke host staging dan validasi konfigurasi efektif tanpa mencetak nilainya.
- Pastikan `APP_DEBUG` nonaktif, HTTPS ingress aktif, tenancy/authentication berjalan sesuai kebijakan, dan database menunjuk target yang disetujui.
- Periksa health endpoint melalui prosedur yang disetujui. Jangan mulai worker/scheduler/provider delivery sebelum izin gate terkait.
- **STOP** bila health/configuration/tenant boundary gagal atau ada secret di log.

### Gate 5 — Migration dengan rencana pemulihan

- Pastikan backup dan restore telah disetujui serta diuji di lingkungan terisolasi.
- Review urutan migration dan dampaknya pada consent/suppression/unknown; simpan bukti versi schema sebelum perubahan.
- Jalankan migration hanya setelah persetujuan operator database dan release owner. Jangan mengandalkan rollback destruktif untuk migration consent. Bila gagal, hentikan deployment, pertahankan database/snapshot dan log tersensor, lalu pulihkan ke clone terisolasi atau lakukan forward-fix yang direview.
- Jangan melanjutkan jika status migration tidak jelas atau data audit berisiko hilang.

### Gate 6 — Android staging build

- Build hanya setelah URL HTTPS staging dan package identity disetujui; masukkan base API melalui `WABIZ_API_BASE_URL`.
- Verifikasi artifact tidak berisi backend/provider secrets dan dibagikan melalui kanal staging privat.
- Tidak ada build yang dilakukan pada fase persiapan ini.

### Gate 7 — Acceptance dengan fake provider

- Jalankan tes hanya setelah database disposable dan egress isolation mendapat persetujuan eksplisit.
- Verifikasi consent grant/withdrawal, client tanpa consent, suppression, cross-tenant access, campaign recipient eligibility, duplicate protection, queue decisions, dan ambiguous `unknown` menggunakan fake provider.
- Callback sintetis harus membuktikan jalur HTTP staging tanpa memicu AI/outbound. Periksa log redaction dan audit status.
- **STOP** pada kebocoran tenant, pengiriman provider, retry otomatis atas `unknown`, atau hasil yang tidak dapat direkonsiliasi.

### Gate 8 — Operasi staging terkendali

- Setelah acceptance, operasikan worker/scheduler dengan campaign sending tetap diblokir sampai ada persetujuan terpisah.
- Tetapkan dashboard/alert untuk queue lag, failed jobs, callback errors, health, consent suppression, dan `unknown` delivery. Rekonsiliasi `unknown` secara manual; jangan blind retry.
- Provider nyata atau broadcast hanya melalui perubahan scope dan persetujuan baru, dengan recipient uji yang secara eksplisit diizinkan. Fase ini tidak mengirim pesan.

### Kriteria berhenti dan pemulihan

Hentikan bila backup/restore gagal, target DB/egress berbeda atau tidak terbukti, migration gagal/parsial, consent/suppression berubah tak terduga, token muncul di log, health/auth/tenant checks gagal, queue menjalankan job yang tak disetujui, atau delivery menjadi ambigu. Nonaktifkan dispatch melalui prosedur operator yang disetujui, jangan hapus/rollback data audit secara membabi buta, simpan snapshot/log yang sudah disensor, dan minta review sebelum melanjutkan. Prosedur stop/restart yang sebenarnya harus disesuaikan oleh pemilik hosting; tidak ada layanan yang diubah dalam fase ini.

## 8. Input administrator

| Input wajib | Bukti yang diterima | Penyedia |
|---|---|---|
| Target staging dan pemilik setiap service | Dokumen change/ticket yang menyebut scope, owner, approval | Pemilik proyek + administrator hosting |
| Hostname/API HTTPS dan sertifikat | DNS/TLS inventory atau deployment record; tanpa credential | Administrator jaringan/hosting |
| Identitas PostgreSQL dan database disposable | Metadata cluster/instance, lokasi data, nama DB, role privilege summary, pemisahan target; tanpa password | Administrator database |
| Egress default-deny | Policy/rule inventory dengan process/VM/container scope dan bukti enforcement independen | Administrator jaringan/OS |
| Backup dan pemulihan | Backup job record serta restore drill pada clone terisolasi | Administrator database/backup |
| Secret injection | Nama secret dan lokasi secret manager/owner, tanpa nilainya | Administrator keamanan/hosting |
| Queue/scheduler ownership | Process supervision plan, driver choice, failed-job and disable procedure | Operator aplikasi |
| WPPConnect boundary/callback | Topology diagram, private bind/ingress policy, callback redaction plan | Administrator gateway/jaringan |
| Logging/monitoring/incident path | Retention, redaction, alert rules, on-call owner | Operator aplikasi/keamanan |
| Kebijakan consent dan retensi | Persetujuan tertulis untuk bukti consent, suppression, deletion, dan penanganan unknown | Pemilik produk/data |

Jangan meminta nilai secret ditempel di chat. Nilai diberikan langsung melalui kanal secret management yang disetujui.

### Keputusan yang dapat ditunda

- Flutter Web: tidak diperlukan untuk staging Android awal; bila dipilih, CORS/Sanctum browser flow menjadi prasyarat tersendiri.
- Pemilihan Redis vs database queue dan object storage: dapat diputuskan oleh pemilik hosting sebelum deployment, setelah kapasitas/operasi dinilai.
- Aktivasi Gemini, Meta Cloud, atau delivery WPPConnect: ditunda sampai verifikasi fake-provider lulus dan persetujuan integrasi provider terpisah diberikan.
- Domain final, vendor, region, dan skala: belum diputuskan; perlu ditetapkan oleh pemilik proyek/admin sebelum membuat resource.

## 9. Keputusan saat ini

- **GO/NO-GO staging: NO-GO untuk deployment operasional saat ini.** Paket ini siap menjadi checklist operator, tetapi target hosting, bukti database/egress, backup/restore, secret injection, dan approval belum tersedia.
- **GO/NO-GO production: NO-GO.** Consent/migration, integrasi provider, operational monitoring, delivery ambiguity handling, serta runtime/build/test belum diverifikasi pada staging.
- Tidak ada real provider, WhatsApp login, QR pairing, campaign, atau message delivery dilakukan.

## 10. Bukti source yang diperiksa dan batas

Inspeksi statis mencakup `lib/core/network/api_client.dart`, Android manifest/build configuration, Laravel `config/app.php`, `config/database.php`, `config/queue.php`, `config/cache.php`, `config/session.php`, `config/filesystems.php`, `config/logging.php`, `config/sanctum.php`, `config/services.php`, `bootstrap/app.php`, `routes/api.php`, `routes/console.php`, Laravel `README.md`, dan struktur file deployment yang tersedia. Tidak ada deployment manifest Docker/Compose/Procfile/reverse-proxy yang ditemukan pada pencarian terbatas.

Yang didukung source: nama konfigurasi, API URL validation, route health, scheduler/queue configuration, provider configuration, dan Android project scaffold. Yang belum diverifikasi: hosting nyata, nilai konfigurasi efektif, runtime process, TLS, database identity/isolation, egress enforcement, backup restoration, callback delivery, queue operation, Android artifact, serta integrasi external provider.

Tidak ada source aplikasi, konfigurasi, dependency, database, baseline, atau environment yang diubah. Tidak ada build, test, PHP/Composer/Artisan, migration, database access, jaringan, worker, scheduler, gateway, webhook, QR/login, atau pesan WhatsApp yang dijalankan.
