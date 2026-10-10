# WABiz Phase 8.4 — Verification Blockers Report

Tanggal pemeriksaan: 2026-10-10 (Asia/Bangkok)  
Mode: inspeksi lokal read-only. Tidak ada source, konfigurasi, dependency, database, gateway, atau baseline yang diubah.

## Ringkasan

- **Kesimpulan keseluruhan: PARTIAL / BLOCKED untuk verifikasi lanjutan.**
- vendor/autoload.php ada dan berhasil dibuka read-only oleh probe PHP berulang. Hasil is_readable() yang false tidak selaras dengan fopen() yang berhasil; penyebab internal PHP/Windows belum dipastikan.
- Pasangan nilai konfigurasi tersimpan untuk gateway secret Laravel↔gateway dan callback token Laravel↔URL callback gateway MATCH saat ini. Ini hanya membuktikan kesesuaian file saat inspeksi, bukan bahwa rotasi historis terjadi atau proses aktif menggunakan/menerima nilai itu.
- Laporan Phase 8.2A bertentangan: satu menyatakan rotasi tidak dilakukan (BLOCKED), satu menyatakan rotasi lokal telah dilakukan (PARTIAL). Bukti historis independen dan uji autentikasi aktif tidak tersedia; status rotasi tidak dapat direkonsiliasi.
- PHPUnit mengatur database wabiz_test, tetapi isolasi database aktual belum dibuktikan. Tes dan koneksi database sengaja tidak dijalankan.

## A. Investigasi vendor/autoload.php

Target absolut: C:\\WABiz\\wabiz-backend\\vendor\\autoload.php  
Working directory pada pemeriksaan PHP: C:\\WABiz\\wabiz-backend. PHP CLI 8.5.11, executable C:\\PHP\\php-8.5.11-Win32-vs17-x64\\php.exe. PHP memakai konfigurasi CLI yang sama pada probe berhasil; open_basedir tidak disetel.

| Pemeriksaan | Temuan |
|---|---|
| file_exists | true pada lima probe berulang |
| is_file | true pada probe terperinci berhasil |
| is_readable | false pada lima probe berulang |
| stat | berhasil pada probe berulang |
| ukuran | 748 byte |
| fopen(..., "rb") | berhasil pada lima probe; handle langsung ditutup, tidak ada byte dibaca |
| metadata Windows | file dan direktori vendor ditemukan; ACL yang diperiksa mengizinkan read dan tidak menunjukkan deny eksplisit |

Satu probe awal melaporkan semua pemeriksaan PHP sebagai false, tetapi tidak berulang. Probe berikutnya dengan executable dan path absolut yang sama konsisten menunjukkan file ada dan dapat dibuka. Kesimpulan yang didukung bukti: file dapat dibuka oleh proses PHP CLI saat pemeriksaan; is_readable() memberikan hasil berbeda dan anomali awal tidak tereproduksi. Ini tidak membuktikan Composer bootstrap dapat dieksekusi; file sengaja tidak di-include karena itu menjalankan kode.

Pemeriksaan lintas user Windows atau proses/service dengan identitas berbeda tidak dilakukan. Perbandingan shell cmd.exe gagal pada quoting/perintah probe PHP (exit 255), sehingga bukan bukti file rusak. Satu perintah PowerShell metadata memiliki ekspresi inline try tidak valid; tidak ada perubahan filesystem. Kemungkinan tersisa: semantik API is_readable pada PHP/Windows, cache/metadata PHP/OS, atau konteks user/proses berbeda. Penyebab spesifik UNVERIFIABLE.

Langkah aman berikutnya: jalankan probe minimal yang sama secara read-only dari proses/user Laravel yang sebenarnya, tanpa require file dan tanpa menampilkan isi. Tidak perlu mengubah ACL atau memasang ulang dependency.

## B. Rekonsiliasi rotasi kredensial

Laporan yang dibaca:
- PHASE-8.2A-CREDENTIAL-ROTATION-REPORT.md: BLOCKED; menyatakan rotasi tidak diterapkan setelah operasi tulis ditolak. Autentikasi runtime baru/lama dan callback runtime tidak diverifikasi.
- PHASE-8.2A-IMPLEMENTATION-REPORT.md: PARTIAL; menyatakan pasangan konfigurasi lokal telah dirotasi dan konsisten, serta tes callback sintetis lulus; tetap mengakui autentikasi gateway nyata/lama dan callback WPPConnect runtime belum diverifikasi.

| Perbandingan / bukti | Hasil |
|---|---|
| Laravel WPPCONNECT_SECRET_KEY vs gateway SECRET_KEY tersimpan | MATCH |
| Laravel WPPCONNECT_WEBHOOK_TOKEN vs segmen token pada gateway WEBHOOK_URL tersimpan | MATCH |
| Kedua file .env ada dan dapat dibuka read-only | tersedia |
| Riwayat apakah nilai itu benar-benar hasil rotasi | UNVERIFIABLE |
| Nilai yang dimuat saat proses terakhir mulai | UNVERIFIABLE |
| Nilai efektif pada proses Laravel/gateway yang sedang berjalan | UNVERIFIABLE |
| Kredensial baru diterima endpoint aktif | UNVERIFIABLE — tidak diuji |
| Kredensial lama ditolak | UNVERIFIABLE — tidak diuji; nilai lama tidak digunakan/dicoba |

Pemeriksaan dilakukan dengan membandingkan pasangan konfigurasi di memori dan hanya mengeluarkan MATCH/MISMATCH; nilai, hash, URL callback, dan fingerprint tidak ditulis ke output/laporan. Kesesuaian file saat ini tidak menyelesaikan konflik historis dan tidak membuktikan restart atau penerapan runtime. Laporan 8.2A menyebut WPPConnect 2.10.30 membaca SECRET_KEY dan WEBHOOK_URL dari environment; helper callback yang ditinjau di laporan itu mengirim callback ke URL konfigurasi tanpa custom Authorization header. Laravel menggunakan route callback berbasis token path. Ini bukti code/config contract, bukan bukti request runtime berhasil.

Tidak ada request autentikasi, callback HTTP, restart, maupun inspeksi environment proses yang dilakukan. Pemeriksaan listener tidak tersedia karena query Get-NetTCPConnection gagal/tidak tersedia; pemeriksaan nama proses hanya memastikan tidak ada proses bernama php atau node yang terlihat saat itu, bukan inventaris lengkap layanan. Status listener gateway/API karena itu UNVERIFIABLE.

## C. Prasyarat pengujian dan isolasi database

Pemeriksaan statis phpunit.xml menunjukkan:
- APP_ENV=testing
- DB_CONNECTION=pgsql
- DB_DATABASE=wabiz_test
- queue sync, cache dan session array, mail array
- .env.testing tidak ditemukan.
- Nama database pada Laravel .env berbeda dari target nama database PHPUnit.
- File Laravel .env dan gateway .env ada; nilainya tidak dicantumkan.
- Tidak ada koneksi PostgreSQL atau query dilakukan.

Perbedaan nama database dan nilai PHPUnit belum cukup membuktikan isolasi: environment/process override, DB_URL, konfigurasi koneksi efektif, host/cluster, atau database salah nama masih perlu dieliminasi. Prasyarat database test BLOCKED; tidak ada test atau migrasi dijalankan.

### Prosedur aman sebelum test
1. Pemilik environment mengonfirmasi wabiz_test adalah database disposable/test, bukan alias development/production.
2. Verifikasi konfigurasi efektif tanpa mencetak password, URL, atau credential: nama koneksi/database dan host/cluster diperiksa tersamarkan; pastikan tidak ada override DB_URL/environment yang mengalihkan target.
3. Gunakan akun test hak minimum dan pastikan tidak ada queue/provider nyata; seluruh pengiriman WhatsApp/AI harus fake serta egress eksternal diblokir atau diuji dalam harness tanpa akses provider.
4. Setelah bukti isolasi ditinjau, minta persetujuan terpisah untuk test database-backed serial. Migrasi memerlukan persetujuan tersendiri.
5. Jangan jalankan test jika salah satu verifikasi target/provider gagal.

## Perintah/pemeriksaan dan hasil

- php --version: exit 0; PHP CLI 8.5.11.
- composer --version: exit 0; Composer 2.10.3. Tidak ada install/update.
- where.exe php, where.exe composer: exit 0; executable/shim teridentifikasi.
- Probe PHP metadata/open read-only: probe berulang berhasil; satu hasil false awal tidak tereproduksi. Percobaan probe quoting lintas shell: exit 255, kesalahan sintaks perintah, bukan kesalahan aplikasi.
- Metadata file konfigurasi: backend .env, gateway .env, phpunit.xml ada; .env.testing tidak ada. Tidak ada isi rahasia dicetak.
- Perbandingan pasangan konfigurasi: dua hasil MATCH; tidak ada nilai/fingerprint dicetak.
- Parser PHPUnit: berhasil membaca hanya setting aman di atas.
- Listener query: UNAVAILABLE (query gagal); tidak digunakan untuk menyimpulkan layanan mati.
- Cek manifest SHA-256: sama sebelum/sesudah inspeksi, 827B71775CAB29F3B6696650BB6E9F3B626B509088F17C5E4426D84A195E32B5. Manifest tidak diubah.
- Tidak menjalankan test, migrasi, koneksi DB, endpoint autentikasi/callback, server, queue, scheduler, gateway, atau proses WhatsApp.

## Langkah lanjutan, prasyarat, dan persetujuan

1. Buktikan DB test disposable melalui pemilik database dan pemeriksaan konfigurasi efektif tersamarkan. Setelah itu minta persetujuan eksplisit sebelum test database-backed.
2. Verifikasi runtime credential/callback memerlukan identifikasi proses/port, persetujuan request HTTP sintetis non-mutatif, dan kemungkinan restart layanan. Sebelum restart, ajukan rencana spesifik: proses yang dimuat ulang, dampak downtime, command, dan cara pemulihan. Tidak ada restart dilakukan di sini.
3. Uji token baru/lama hanya jika endpoint dipastikan non-mutatif dan token lama tersedia aman. Tanpa nilai lama yang dapat diverifikasi atau bukti historis, hasil lama tetap UNVERIFIABLE. Jangan menaruh token dalam command history/log.
4. Callback sintetis hanya setelah dipastikan tidak memicu AI, queue, atau outbound effect; gunakan HTTP boundary aktual, fake provider, dan database terisolasi. Jangan akses WhatsApp nyata.

## Hal yang tetap UNVERIFIABLE

- Penyebab pasti perbedaan is_readable() dan fopen(), serta hasil awal yang tidak tereproduksi.
- Perilaku file di bawah identitas user/proses service sebenarnya.
- Apakah rotasi benar-benar terjadi dan kapan.
- Credential aktif di proses yang sedang berjalan.
- Penerimaan credential baru dan penolakan credential lama.
- Callback runtime WPPConnect ke Laravel.
- Status listener/service saat ini.
- Isolasi fisik/efektif wabiz_test dari database non-test.
- Keamanan efek samping callback sintetis tanpa pemeriksaan/harness tambahan.

## Kesimpulan

**PARTIAL — verifikasi runtime dan pengujian tetap diblokir.** Bukti statis menunjukkan pasangan konfigurasi tersimpan cocok dan target PHPUnit bernama wabiz_test; bukti itu tidak cukup untuk menyatakan rotasi runtime atau isolasi database aman. Tidak ada perubahan proyek selain laporan ini, dan tidak ada WhatsApp nyata yang diakses.
