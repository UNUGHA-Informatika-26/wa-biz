# WABiz Phase 8.7 — Safe Verification Runbook

Tanggal penyusunan: 2026-10-10 (Asia/Bangkok)  
Scope: prosedur dan rencana saja. Perintah bertanda **BELUM DIJALANKAN** tidak memperoleh izin untuk dieksekusi pada Phase 8.7.

## Status awal berdasarkan bukti

- PHP: perbedaan direproduksi dalam satu invocation PHP CLI/user yang sama. File ada dan berhasil dibuka read-only, sementara is_readable() false. Penyebab sistemik **belum diketahui**.
- Database: PHPUnit menunjuk pgsql/127.0.0.1/wabiz_test, tetapi elemen env tidak memaksa override dan isolasi host/cluster/disposable belum dibuktikan. Status **BLOCKED**.
- Credential: pasangan nilai tersimpan MATCH. Nilai yang dibaca proses aktif dan penerimaan/penolakan auth **UNVERIFIABLE**.
- Tidak ada test, DB query, migrasi, request runtime, restart, atau pengiriman WhatsApp dilakukan dalam penyusunan runbook ini.
- Baseline tidak diubah; hash terakhir yang tercatat adalah 827B71775CAB29F3B6696650BB6E9F3B626B509088F17C5E4426D84A195E32B5.

## A. Diagnostik PHP

### Fakta yang sudah terverifikasi

Pada C:\\WABiz\\wabiz-backend, user interaktif desktop-v6v7j7m\\p, executable C:\\PHP\\php-8.5.11-Win32-vs17-x64\\php.exe (PHP 8.5.11), target absolut C:\\WABiz\\wabiz-backend\\vendor\\autoload.php:

| Check | Hasil |
|---|---|
| file_exists | true |
| is_file | true |
| is_readable | false |
| stat | berhasil, ukuran 748 byte |
| fopen read-only | berhasil; langsung fclose, tidak membaca byte |

Semua hasil tersebut keluar dari invocation PHP yang sama. Hasil awal berbeda yang tercatat pada Phase 8.4 tidak tereproduksi pada probe berikutnya. Bukti tidak menentukan apakah penyebabnya implementasi PHP/Windows, context token, security filter, atau keadaan sementara filesystem.

### Perintah read-only untuk operator

Jalankan dari PowerShell biasa, tanpa elevated/admin shell, di backend. Ini tidak meng-include file dan tidak membaca isinya:

```powershell
Set-Location -LiteralPath 'C:\WABiz\wabiz-backend'
whoami
Get-Location
$php = 'C:\PHP\php-8.5.11-Win32-vs17-x64\php.exe'
& $php --version | Select-Object -First 1
& $php -r 'echo "PHP_BINARY=",PHP_BINARY,PHP_EOL,"PHP_VERSION=",PHP_VERSION,PHP_EOL,"CWD=",getcwd(),PHP_EOL; $p="C:/WABiz/wabiz-backend/vendor/autoload.php"; echo "exists=",(file_exists($p)?"true":"false"),PHP_EOL,"is_file=",(is_file($p)?"true":"false"),PHP_EOL,"is_readable=",(is_readable($p)?"true":"false"),PHP_EOL; $s=@stat($p); echo "stat=",(is_array($s)?"true":"false"),PHP_EOL; if(is_array($s)) echo "size=",$s["size"],PHP_EOL; $h=@fopen($p,"rb"); echo "fopen=",(is_resource($h)?"true":"false"),PHP_EOL; if(is_resource($h)) fclose($h);'
```

Expected result under the previously tested interactive context: exists/is_file/stat/fopen true; is_readable false. If the executable is missing or results differ, stop and record exit code, user, cwd, PHP path/version, and only boolean outcomes. Never use `Get-Content`, `type`, `cat`, `require`, or `include` on autoload.php.

### Perbandingan service context — operator manual, belum dilakukan

1. Operator mengidentifikasi service yang benar-benar menjalankan Laravel dan account pemiliknya melalui service manager. Catat nama service, PID, account, working directory, dan executable path saja. Jangan mengambil command line atau environment block karena dapat berisi credential.
2. Jika service tidak menyediakan safe diagnostic entry point, jangan menjalankan PHP tambahan sebagai service atau mengubah service. Operator/admin perlu memilih metode yang disetujui untuk memeriksa effective access.
3. Jalankan probe identik di bawah account/filesystem context yang sama hanya setelah operator menyetujui cara menjalankannya. Jangan elevasi hak hanya untuk memaksa akses.
4. Jika perbedaan tetap ada, administrator membandingkan effective ACL untuk account tersebut, volume/path mapping, dan log kebijakan antivirus/Controlled Folder Access pada waktu yang sama. Catat hanya temuan denial/event code, bukan payload atau isi file.
5. Perubahan ACL, antivirus exclusion, dependency, atau service setting adalah tindakan terpisah yang perlu bukti penyebab, dampak, rollback, dan persetujuan.

Interpretasi:
- Semua API file gagal: periksa dahulu path, user, drive mapping, dan filesystem context.
- fopen gagal tetapi metadata true: catat error code yang sudah disanitasi; operator memeriksa effective access dan kebijakan OS.
- fopen sukses/is_readable false lagi: anomali terkonfirmasi pada konteks itu, tetapi jangan menyimpulkan akar masalah tanpa pemeriksaan administrator.

## B. Isolasi database pengujian

### Konfigurasi statis yang telah dibaca

- phpunit.xml: APP_ENV=testing; DB_CONNECTION=pgsql; DB_HOST=127.0.0.1; DB_DATABASE=wabiz_test; DB_URL kosong; queue=sync; cache/session/mail=array.
- Atribut force tidak disetel pada elemen phpunit env. Environment runner dapat memengaruhi nilai yang dipakai.
- .env.testing tidak ditemukan pada inspeksi sebelumnya.
- config/database.php membaca DB_URL, DB_CONNECTION, DB_HOST, dan DB_DATABASE dari environment.
- bootstrap/cache/config.php tidak ditemukan saat diperiksa sebelumnya, tetapi keadaan ini harus dicek ulang dalam runner yang sama sebelum test.
- Nama database bukan bukti bahwa host, cluster, alias, atau data di dalamnya aman.

### Prosedur fail-closed — BELUM DIJALANKAN

**Gate 0 — persetujuan dan target yang diizinkan**

Pemilik database harus menyatakan secara tertulis identitas test host/cluster dan nama database yang disposable, serta mengonfirmasi bahwa target bukan production/development. Jika pemilik tidak dapat membuktikan pemisahan, berhenti.

**Gate 1 — periksa runner yang sama dengan test**

Jalankan pemeriksaan metadata pada PowerShell runner yang nantinya akan menjalankan PHPUnit. Tampilkan hanya status APP_ENV/DB_* override hadir/tidak hadir, bukan nilai rahasia atau URL. Parse hanya setting yang diizinkan dari phpunit.xml. Gagal jika DB_URL hadir, ada override tidak dikenal, PHPUnit config berbeda dari target yang disetujui, atau force/boot behavior tidak dipahami.

```powershell
Set-Location -LiteralPath 'C:\WABiz\wabiz-backend'
foreach ($n in 'APP_ENV','DB_CONNECTION','DB_HOST','DB_DATABASE','DB_URL') {
  '{0}_present={1}' -f $n, ($null -ne [Environment]::GetEnvironmentVariable($n))
}
$p = [xml](Get-Content -LiteralPath '.\phpunit.xml')
foreach ($n in 'APP_ENV','DB_CONNECTION','DB_HOST','DB_DATABASE','DB_URL') {
  $e = $p.SelectSingleNode("//php/env[@name='$n']")
  if ($null -eq $e) { "phpunit_$($n)=ABSENT" } else { "phpunit_$($n)=PRESENT; force=$($e.force)" }
}
Test-Path -LiteralPath '.\bootstrap\cache\config.php'
```

Perintah ini hanya membaca process environment presence, XML, dan file metadata. Output `True` untuk cache tidak membuktikan cache aman; jika cache ada, berhenti sampai asal/target cache diverifikasi. Jangan menjalankan config:clear/config:cache.

**Gate 2 — verifikasi effective config sebelum test**

Setelah izin khusus untuk diagnostic bootstrap, gunakan probe yang sama dengan runner test dan hanya allowlist output: APP_ENV, driver, database allowlist result, host classification (loopback/approved alias saja), DB_URL-present, config-cache-present. Jangan memakai `config:show database`, `phpinfo()`, dump config, Tinker, atau output yang dapat memuat password/URL. Jika bootstrap provider dapat membuka koneksi atau melakukan efek samping, audit provider dahulu; jika tidak aman, jangan boot.

**Gate 3 — bukti identitas server/database**

Setelah izin koneksi read-only yang terpisah, gunakan akun minimum untuk melakukan satu query identitas PostgreSQL yang read-only, misalnya nilai current database, server address/port, dan current user. Jangan membaca tabel aplikasi. Bandingkan hasil tersanitasi dengan target yang disetujui pemilik. Nama wabiz_test saja tidak cukup. Jika query identity tidak mungkin tanpa akses tulis atau target tidak cocok, berhenti.

**Gate 4 — no-write dan no-external-effects**

Pastikan role/credential test hanya dapat mengakses database test; review test yang akan dijalankan untuk transaksi/cleanup dan efek di luar DB. Audit provider registry/fakes, HTTP stray request guard, job queue, callback/webhook, AI, dan provider WhatsApp. Jika jalur apa pun dapat mencapai jaringan/provider nyata atau menyentuh database non-test, jangan menjalankan test.

**Gate 5 — persetujuan test terpisah**

Hanya setelah Gate 0–4 terbukti dan dilaporkan, minta persetujuan eksplisit untuk focused tests serial. Persetujuan itu tidak mengizinkan migrasi, seeder, reset, test lain, queue worker, atau suite paralel. Pada mismatch/exception, test harus dihentikan sebelum operasi database; jangan mencoba target fallback.

**Hasil yang diharapkan:** semua nilai efektif sesuai target yang disetujui, target identity cocok, DB_URL/override/cache tidak ambigu, dan outbound providers terbukti fake. Jika salah satu tidak terpenuhi: **STOP / BLOCKED**, tidak ada test.

## C. Verifikasi credential Laravel–gateway

### Checklist lapisan bukti

| Layer | Laravel | Gateway | Bukti saat ini |
|---|---|---|---|
| Config tersimpan | WPPCONNECT_SECRET_KEY dan WPPCONNECT_WEBHOOK_TOKEN berpasangan dengan konfigurasi gateway | SECRET_KEY dan WEBHOOK_URL tersimpan berpasangan | MATCH yang diuji in-memory; nilai tidak ditampilkan |
| Mekanisme pemuatan | config/services.php mengambil nilai WPPCONNECT_* dari environment; Laravel mungkin membaca config cache | package config.js membaca SECRET_KEY dan WEBHOOK_URL; source menyediakan fallback credential jika env tidak tersedia | Source-level mechanism diperiksa; tidak membuktikan nilai runtime |
| Akan dimuat pada startup berikutnya | Belum diverifikasi terhadap environment service aktual/cache saat start | Belum diverifikasi terhadap launcher/service environment aktual | UNVERIFIABLE |
| Digunakan proses aktif | proses Laravel tidak teridentifikasi | proses gateway tidak teridentifikasi; listener check terdahulu tidak tersedia | UNVERIFIABLE |
| Autentikasi baru/lama | tidak ada request aktif | tidak ada request aktif | UNVERIFIABLE |
| Callback | route callback token path menurut source/laporan terdahulu; belum diuji runtime | callback URL dari config; belum diuji runtime | UNVERIFIABLE |

Jangan memakai fallback package credential sebagai bukti konfigurasi aman. Preflight nanti harus membuktikan env wajib tersedia tanpa mencetak nilainya; jika tidak, hentikan startup/test.

### Prosedur terkendali — BELUM DIJALANKAN, sebagian memerlukan approval

1. **Read-only process discovery:** operator mengidentifikasi service/PID/account, cwd, executable, bind address dan port. Jangan dump process command line/environment. Pastikan gateway tetap bind ke 127.0.0.1:21465 dan management gate tetap ada. Jika listener atau pemiliknya tak dikenal, jangan request/restart.
2. **Static endpoint audit:** cocokkan versi @wppconnect/server terpasang dan baca route/controller source untuk menemukan endpoint status yang benar-benar non-mutatif. Pastikan endpoint tidak start session, QR, clear session, logout, atau send. Jangan menebak URL atau membuat token URL terlihat.
3. **Laravel route audit:** pastikan callback middleware/token validation, handler side effects, queue/AI dispatch, DB persistence, dan provider send behavior. Jika callback dapat memicu AI/outbound, siapkan isolasi fake serta network egress block sebelum request.
4. **Approval gate restart:** sebelum restart, laporkan service name/PID/account, port, exact sanitized command, downtime, expected effect, health check, dan rollback. Minta approval eksplisit untuk proses yang disebut. Tidak ada persetujuan tersirat dari runbook ini.
5. **Startup config proof:** setelah restart disetujui, periksa readiness tanpa mengeluarkan environment. Aplikasi harus gagal closed jika required credential absent; jangan cetak env/process command line. Catat process identity, start time, binary, bind, dan boolean config-present/match result saja.
6. **New credential acceptance:** dengan approval terpisah, kirim satu request ke endpoint status non-mutatif yang telah diverifikasi. Credential disuplai melalui mekanisme input terlindungi yang tidak menaruhnya di command history/output/log; catat status code saja. Jika tidak ada jalur aman untuk menyuplai credential, jangan tes.
7. **Old credential rejection:** hanya jika credential lama tersedia dari pemilik melalui channel aman dan endpoint non-mutatif. Jalankan satu percobaan terpisah; catat status diterima/ditolak tanpa nilai. Jika nilai lama tidak ada, hasil tetap UNVERIFIABLE.
8. **Synthetic callback:** hanya setelah database target aman, endpoint callback dan payload package-shape dibuktikan, serta AI/queue/provider eksternal di-fake atau diblokir. Request harus melalui HTTP boundary yang sebenarnya; jangan memanggil controller/service langsung. Jangan mengirim pesan nyata.

### Hasil dan interpretasi

- Credential baru sukses pada endpoint status non-mutatif + invalid/old ditolak: bukti autentikasi untuk proses/endpoint spesifik dan waktu pengujian; bukan bukti konfigurasi deployment lain.
- Credential baru ditolak: STOP; jangan ubah credential/config. Periksa proses/startup source dan ajukan pemulihan setelah approval.
- Credential lama tidak tersedia atau endpoint tidak aman: UNVERIFIABLE, bukan PASS.
- Callback menghasilkan 2xx saja tidak cukup; konfirmasi event sintetis tersimpan sesuai tenant/connection tanpa AI/outbound effect dan tidak ada credential/message payload di log.
- Jangan menyebut callback sebagai signed/HMAC jika hanya token bersama.

### Risiko dan rollback runtime

- Restart dapat menimbulkan downtime, memutus koneksi WhatsApp, atau membaca konfigurasi yang berbeda. Tidak dilakukan pada phase ini.
- Callback sintetis berpotensi membuat conversation/message, memicu AI/job, atau mengirim pesan. Jangan lakukan sebelum isolation dibuktikan.
- Uji token path dapat tercatat di access/reverse-proxy/tracing logs. Pastikan redaction lokal dan gunakan transport/prosedur yang tidak mencetak URL/header. Jika tidak dapat menjamin, jangan uji callback runtime.
- Bila restart disetujui namun health check gagal: hentikan semua request; jalankan rollback operasional yang telah disetujui untuk mengembalikan service ke startup/configuration state sebelumnya; verifikasi health saja. Jangan mengedit atau merotasi secret sebagai tindakan darurat tanpa approval terpisah.
- Tidak ada perubahan runtime pada Phase 8.7, jadi tidak ada rollback yang diperlukan saat ini.

## D. Izin dan titik berhenti

| Item | Status saat ini |
|---|---|
| Jalankan diagnostik PHP read-only di konteks CLI saat ini | sudah dilakukan pada phase sebelumnya; tidak ada perubahan |
| Jalankan test / query DB | **BELUM DIIZINKAN; BLOCKED oleh isolasi** |
| Auth request baru/lama | **BELUM DIIZINKAN; runtime endpoint/process belum diverifikasi** |
| Synthetic callback HTTP | **BELUM DIIZINKAN; DB/effect/log isolation belum dibuktikan** |
| Start/restart/stop service | **MEMERLUKAN approval eksplisit dan rencana dampak/rollback spesifik** |
| Ubah ACL, file, dependency, config, credential, atau baseline | **DI LUAR SCOPE; tidak dilakukan** |
| Implementasi fitur | **TIDAK DIMULAI** |

Berhenti jika: executable/user/path berbeda dari yang disetujui; ada environment override/cached config tak dikenal; DB target identity tidak cocok; proses/port tidak dapat diidentifikasi; endpoint dapat memodifikasi sesi/mengirim; token dapat bocor ke log; provider/effect tidak dapat diisolasi; atau hasil request tidak sesuai harapan.

## Perintah aktual vs prosedur tertunda

**Aktual pada Phase 8.7:** baca penuh laporan Phase 8.6; inspeksi aman phpunit.xml/config/database.php/config/services.php dan source pemuatan WPPConnect; tidak ada operasi runtime/database/test. Hash baseline tidak berubah pada pengecekan terakhir.

**Tertunda dan tidak dijalankan:** perintah diagnostik A di bawah account service; Gate B preflight/bootstrap/query; process discovery yang butuh hak tambahan; auth request; callback HTTP; restart; test.

## Batasan yang belum terverifikasi

- Akar penyebab is_readable() berbeda dari fopen() pada konteks service.
- Identitas dan environment proses aktif Laravel/gateway.
- Host/cluster serta isolasi fisik database wabiz_test.
- Penerimaan credential baru dan penolakan credential lama.
- Callback runtime dan log redaction aktual.
- Riwayat rotasi 8.2A, karena dua laporan sebelumnya bertentangan dan snapshot historis pembanding tidak tersedia.

## Gerbang akhir

Status Phase 8.7 adalah **RUNBOOK PREPARED — VERIFICATION NOT RUN**. Jangan menganggap ini sebagai izin test atau runtime. Lanjut hanya setelah pemilik database dan operator proses menyediakan bukti/prasyarat, kemudian minta persetujuan terpisah untuk setiap tindakan yang dapat menyentuh DB atau runtime. Baseline harus tetap tidak berubah.
