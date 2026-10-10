# WABiz Phase 8.5 — Controlled Verification Report

Tanggal: 2026-10-10 (Asia/Bangkok)  
Mode: read-only diagnostics and planning. No service operation, database connection, test, or authentication request was performed.

## Kesimpulan

**PARTIAL — runtime verification belum dapat ditutup.** Diagnostik PHP mereproduksi perbedaan is_readable()/fopen(). Pasangan credential dalam file konfigurasi tetap MATCH, tetapi konfigurasi loaded/aktif dan autentikasi baru/lama belum dibuktikan. Database test tetap BLOCKED karena target server/cluster serta konfigurasi efektif tidak dapat dipastikan secara aman.

## Tahap A — Konteks PHP

Pemeriksaan dilakukan di PowerShell pada working directory C:\\WABiz\\wabiz-backend, user interaktif Windows desktop-v6v7j7m\\p. PHP yang dipakai adalah C:\\PHP\\php-8.5.11-Win32-vs17-x64\\php.exe, versi 8.5.11.

| Pemeriksaan | Hasil |
|---|---|
| Path absolut C:\\WABiz\\wabiz-backend\\vendor\\autoload.php | ada |
| file_exists | true |
| is_file | true |
| is_readable | false |
| stat | berhasil |
| ukuran file | 748 byte |
| fopen mode rb | berhasil; handle langsung ditutup tanpa membaca isi |
| PHP exit code | 0 |
| working directory / executable | tercatat sebagaimana di atas |

Hasil mengulang temuan Phase 8.4. Ini membuktikan file dapat dibuka read-only dalam konteks CLI/user interaktif tersebut, bukan bahwa bootstrap Composer dapat di-include. User/service identity, filesystem view, atau executable PHP pada layanan Laravel belum diperiksa. Tidak ada bukti yang mengaitkan anomali dengan ACL, antivirus, atau kebijakan sistem tertentu. Penyebab pasti tetap **UNVERIFIABLE**; jangan mengubah permission/dependency. Pemeriksaan lanjutan yang aman ialah menjalankan pemeriksaan metadata/open yang sama dari konteks user proses Laravel setelah proses tersebut diidentifikasi, tanpa membaca isi file.

## Tahap B — Isolasi database

Inspeksi statis phpunit.xml menunjukkan APP_ENV=testing, DB_CONNECTION=pgsql, DB_HOST=127.0.0.1, DB_DATABASE=wabiz_test, DB_URL kosong, queue=sync, cache/session/mail=array. .env.testing tidak tersedia. Konfigurasi database Laravel mengambil nilai dari DB_URL, DB_CONNECTION, DB_HOST, dan DB_DATABASE melalui config/database.php.

Elemen PHPUnit tersebut tidak memiliki atribut force. Pada pemeriksaan shell saat ini, environment override APP_ENV, DB_CONNECTION, DB_HOST, DB_DATABASE, dan DB_URL tidak ditemukan. Ini hanya konteks shell saat inspeksi; belum membuktikan environment proses yang kelak menjalankan test. Nama database di file aplikasi berbeda dari wabiz_test, tetapi tidak ada koneksi ke PostgreSQL untuk memverifikasi server/cluster atau memastikan database tersebut disposable. Tidak ada password/URL/kredensial database yang ditampilkan.

**Status isolasi: BLOCKED — belum cukup bukti untuk menjalankan test, migrasi, seeder, atau operasi database.**

### Gerbang fail-closed sebelum test

1. Pemilik database mengonfirmasi target test yang diizinkan dan bahwa target tersebut disposable serta bukan alias development/production.
2. Dari proses runner yang akan menjalankan test, pastikan APP_ENV testing dan nilai efektif koneksi/database/host mengarah tepat ke target yang disetujui; redaksi semua credential dan URL. Jika ada DB_URL atau override tak dikenal, hentikan.
3. Gunakan akun database dengan hak minimum untuk database test saja. Verifikasi identitas target tanpa mengubah data.
4. Audit test terkait memastikan provider WhatsApp/AI fake, queue tidak mengirim keluar, dan HTTP eksternal dilarang. Fake test saja bukan bukti isolasi database.
5. Hanya setelah keempat gerbang terbukti, minta persetujuan eksplisit terpisah sebelum menjalankan tes serial. Persetujuan untuk test tidak mencakup migrasi.
6. Jika target tidak dapat dibuktikan, hentikan tanpa menjalankan test.

## Tahap C — Credential dan runtime

| Lapisan bukti | Gateway credential | Callback credential |
|---|---|---|
| Nilai tersimpan pada file Laravel dan gateway | MATCH | MATCH |
| Aplikasi memuat nilai dari konfigurasi | mekanisme berbasis environment tampak di sumber/laporan terdahulu; pemuatan runtime saat ini belum diuji | callback URL berbasis konfigurasi tampak di sumber/laporan terdahulu; pemuatan runtime saat ini belum diuji |
| Nilai yang digunakan proses aktif | UNVERIFIABLE | UNVERIFIABLE |
| Credential baru diterima endpoint aktif | UNVERIFIABLE — tidak ada request | UNVERIFIABLE — tidak ada callback HTTP |
| Credential lama ditolak | UNVERIFIABLE — tidak ada uji dan nilai lama tidak tersedia untuk pembuktian | UNVERIFIABLE — tidak ada uji dan nilai lama tidak tersedia untuk pembuktian |

Laporan Phase 8.2A yang dirujuk tetap bertentangan: credential-rotation report menyebut rotasi BLOCKED/tidak dilakukan; implementation report menyebut pasangan lokal telah dirotasi dan konsisten tetapi tetap PARTIAL tanpa verifikasi runtime. Pemeriksaan saat ini mengonfirmasi pasangan file cocok saja; tidak membuktikan kapan atau apakah rotasi historis terjadi.

Tidak ada proses PHP/Node yang terlihat melalui pemeriksaan nama proses read-only. Query listener lokal gagal/tidak tersedia, sehingga tidak ada port aktif yang dapat dikonfirmasi. Proses Laravel dan WPPConnect, PID, startup command, user service, serta konfigurasi yang sudah dimuat belum dapat diidentifikasi. Tidak ada endpoint aktif yang dipanggil. Route callback menggunakan token pada path menurut sumber/laporan sebelumnya; request callback dapat memiliki efek samping persistence/processor. Maka callback runtime belum aman untuk dites sebelum efek samping dan provider fake/egress isolation dibuktikan.

### Rencana runtime terkendali — menunggu persetujuan

Belum ada tindakan pada bagian ini yang dijalankan. Sebelum meminta persetujuan restart:

1. Identifikasi proses dan pemiliknya melalui inspeksi lokal read-only tanpa menampilkan command line/environment yang berpotensi berisi credential. Konfirmasi port/bind address dan bahwa gateway tetap loopback-only.
2. Konfirmasi endpoint pemeriksaan gateway yang benar dari versi package terpasang dan sifatnya non-mutatif. Jangan menebak route atau menaruh credential pada command line/history.
3. Untuk callback Laravel, siapkan test harness lokal dengan HTTP boundary nyata, database disposable yang telah dibuktikan, serta processor/queue/provider yang tidak dapat mengirim keluar. Jika callback memproses AI atau enqueue efek eksternal, jangan kirim request runtime sampai efek tersebut terisolasi.
4. Sebelum restart, berikan kepada pemilik proyek rencana spesifik berisi proses yang akan direstart, alasan, downtime/impact, command yang telah disanitasi, pemeriksaan health setelahnya, dan rollback (hentikan perubahan/restart dengan konfigurasi tersimpan sebelumnya; jangan mengedit secret). Tunggu persetujuan eksplisit.
5. Setelah persetujuan dan target aman, jalankan satu request baru yang valid ke endpoint non-mutatif, lalu satu request invalid, dengan secret/header disuplai secara aman dan output redacted. Untuk callback, gunakan event sintetis sesuai contract package dan pastikan tidak ada AI/outbound send. Simpan hanya status HTTP dan hasil database yang tersanitasi.
6. Uji credential lama hanya jika nilai lama dapat diperoleh secara sah dan aman tanpa menampilkannya. Jika tidak tersedia, tandai UNVERIFIABLE; jangan mengarang hasil.
7. Bila hasil tidak sesuai, hentikan pengujian, jangan rotasi atau ubah file otomatis, dan pulihkan proses melalui rencana yang disetujui.

### Risiko dan rollback

- Restart dapat menyebabkan downtime atau sesi gateway terputus; belum disetujui/dilakukan.
- Callback sintetis dapat menyimpan pesan, membuat conversation, memicu AI/queue, atau mengirim balasan jika path tidak benar-benar terisolasi. Belum diuji.
- Pengujian credential lama berisiko memaparkan credential atau menghasilkan false assurance; tidak dilakukan.
- Tidak ada perubahan keadaan runtime, sehingga tidak diperlukan rollback pada tahap ini.

## Langkah yang dijalankan dan yang menunggu

**Dijalankan:** membaca penuh laporan Phase 8.4; memeriksa konteks PHP dan metadata file read-only; memeriksa setting aman phpunit.xml dan keberadaan environment override shell tanpa menampilkan nilai; membandingkan konfigurasi credential di memori dan hanya mengeluarkan MATCH; memeriksa nama proses/listener secara read-only; memeriksa hash baseline.

**Tidak dijalankan:** koneksi/query database, Laravel tests, migration, seeder, config cache/clear, server/gateway start atau restart, endpoint auth, callback HTTP, QR/session, AI, queue, scheduler, campaign, dan WhatsApp delivery.

Baseline C:\\WABiz\\wabiz\\CURRENT-STATE-BASELINE-2026-10-07.sha256 tetap tidak diubah. Hash yang tercatat sebelum/sesudah adalah 827B71775CAB29F3B6696650BB6E9F3B626B509088F17C5E4426D84A195E32B5.

## Hal yang tetap UNVERIFIABLE

- Penyebab sistemik perbedaan is_readable() dan fopen(); konteks service dapat berbeda dari user CLI.
- Konfigurasi yang dimuat saat startup dan credential efektif pada proses aktif.
- Penerimaan credential baru dan penolakan credential lama.
- Callback sintetis melalui HTTP runtime Laravel dan efek sampingnya.
- Proses/port layanan yang aktif; listener query tidak tersedia.
- Identitas server/cluster dan isolasi efektif database wabiz_test.
- Riwayat faktual rotasi 8.2A karena laporan bertentangan dan bukti historis pembanding tidak tersedia.

## Rekomendasi gerbang penerimaan

Jangan lanjutkan implementasi fitur atau menjalankan test database-backed sampai target test disposable dibuktikan dan disetujui. Verifikasi runtime credential/callback harus menunggu identifikasi proses/endpoint, isolasi efek samping callback, dan persetujuan eksplisit untuk restart atau request aktif. Sampai bukti itu tersedia, status Phase 8.5 tetap **PARTIAL**, bukan PASS.
