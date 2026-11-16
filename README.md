# Portal Web Perkuliahan — Universitas Indramayu

Aplikasi Learning Management System (LMS) / Portal Akademik ringan berbasis **PHP 8.2+** dan **SQLite** (tanpa perlu MySQL/database eksternal). Dirancang responsif untuk desktop, tablet, dan ponsel dengan tampilan penuh, navigasi drawer di sebelah kiri pada layar kecil, serta dukungan peran terpisah antara **Mahasiswa** dan **Dosen**.

- **Situs Produksi**: https://portal-unira.up.railway.app

## Fitur Utama

- **16 Pertemuan di Semua Mata Kuliah**: Setiap dari 8 mata kuliah memiliki silabus terstruktur **Pertemuan 1–16**, dengan jadwal **UTS pada Pertemuan 6** dan **UAS pada Pertemuan 16**.
- **Pengumpulan Tugas di Tiap Pertemuan**: Setiap pertemuan (`Pertemuan 1–16`) menyediakan kolom pengumpulan tugas/ujian dengan jawaban tertulis dan/atau lampiran berkas (maks. 8 MB). Mahasiswa dapat memperbarui pengumpulan selama belum dinilai dosen.
- **Peran Mahasiswa & Dosen Terpisah**:
  - **Mode Mahasiswa**: `Meja` ringkasan semester, `Kuliah` & detail kelas per pertemuan, `Tugas` dengan filter kategori (`Semua`, `Tugas Mingguan`, `UTS (P6) & UAS (P16)`), dan `Nilai` beserta IPS sementara.
  - **Mode Dosen**: `Meja Dosen`, `Kelola Kelas`, `Penilaian` (pemeriksaan pengumpulan tugas & input nilai UTS/UAS satuan maupun massal), `Rekap Nilai`, serta halaman khusus dosen:
    - `Kelola Materi & Tugas` (`?p=dosen_materi`) — menyunting materi pertemuan 1–16, membuat/menghapus tugas, dan mereset status pengumpulan.
    - `Presensi Pertemuan` (`?p=dosen_presensi`) — mencatat kehadiran mahasiswa per pertemuan 1–16.
    - `Pengumuman & Mahasiswa` (`?p=dosen_pengumuman`) — menerbitkan/menghapus pengumuman kelas serta melihat daftar mahasiswa.
- **Edit Profil & Foto Profil (`?p=profil`)**: Akun **Mahasiswa** maupun **Dosen** dapat mengubah foto profil (JPG/PNG/WebP/GIF maks. 4 MB dengan pratinjau langsung, serta opsi hapus foto), nama lengkap, nama pengguna, NIM/NIDN, program studi, email, nomor HP, bio singkat, dan sandi akun.
- **Live Clock Real-Time**: Penunjuk tanggal & waktu aktif di header setiap halaman dan kartu masuk, dengan format Indonesia (`HH:MM:SS` tabular) serta deteksi zona waktu (`WIB`/`WITA`/`WIT`).
- **UI Responsif & Drawer Kiri**: Sidebar permanen pada layar lebar (`> 900px`) dan slide-in drawer di sebelah kiri melalui tombol `☰` pada mobile/tablet (`<= 900px`), latar batik tetap diam saat scroll, serta metadata PWA (`manifest.webmanifest`).

## Daftar Mata Kuliah Bawaan (Gasal 2026/2027)

| Kode | Mata Kuliah | SKS | Pengampu | Jadwal |
|------|-------------|-----|----------|--------|
| `IF301` | Pemrograman Berbasis Platform | 3 | Sugiyanto | Senin · 10.00–12.30 (`Lab Mobile 1`) |
| `IF302` | Analisis dan Design PL | 3 | Dian Novianti | Senin · 13.00–15.30 (`Ruang D-105`) |
| `UN210` | Tata Tulis Ilmiah (Metode Penelitian) | 2 | Harry Gunawan | Selasa · 09.10–11.40 (`Ruang B-012`) |
| `IF320` | Teknologi AR/VR | 3 | Muhammad Imam | Selasa · 13.00–15.30 (`Lab Immersive`) |
| `IF330` | Manajemen Proyek Teknologi Informasi | 3 | Gunadi Dwi Hantoro | Selasa · 18.30–21.00 (`Ruang C-301`) |
| `IF340` | AI Computing Platform | 3 | Freddy Wicaksono | Rabu · 18.00–22.00 (`Lab AI`) |
| `AIK4` | AIK 4 | 2 | Masdum Mustaqwa | Kamis · 10.00–12.30 (`Ruang A-101`) |
| `IF318` | Interaksi Manusia & Komputer | 3 | Muhammad Imam | Minggu · 13.00–15.30 (`Studio Desain`) |

## Akun Demo

| Peran | Nama Pengguna | Sandi | NIM / NIDN |
|-------|---------------|-------|------------|
| Mahasiswa | `Bagus Anugrah Dinata` | `Bagoesaja` | `240511001` |
| Mahasiswa | `siti` | `mhs123` | `240511002` |
| Mahasiswa | `rizky` | `mhs123` | `240511003` |
| Dosen (Utama) | `dosen` | `dosen123` | `NIDN-0412088501` |
| Dosen | `laksmi` | `serambi` | `NIDN-001` |

> Kotak akun demo pada halaman masuk disembunyikan secara bawaan. Untuk menampilkannya saat pengujian lokal, atur environment variable `SHOW_DEMO=1`.

## Menjalankan Secara Lokal

Prasyarat: **PHP 8.1+** dengan ekstensi `pdo_sqlite` dan `mbstring`.

```bash
git clone https://github.com/gustttnvm1003/Portal-Web-Perkuliahan.git
cd Portal-Web-Perkuliahan
php -S localhost:8000 router.php
```

Lalu buka http://localhost:8000 di peramban. Basis data SQLite akan dibuat dan diisi otomatis pada direktori `data/` saat akses pertama (`SCHEMA_VERSION = 5`). Hapus folder `data/` jika ingin mengatur ulang data awal.

## Deployment (Docker / Railway)

Proyek ini sudah menyertakan [Dockerfile](Dockerfile) berbasis `php:8.2-cli` dan [router.php](router.php) untuk lingkungan produksi:

- **Endpoint Healthcheck**: `GET /health` mengembalikan HTTP `200` (`ok`).
- **Penyimpanan Persisten**: Atur `SERAMBI_DATA_DIR` (atau `RAILWAY_VOLUME_MOUNT_PATH`, misalnya volume yang dipasang pada `/serambi-data`) agar SQLite dan berkas unggahan (`uploads/`) bertahan antar-deploy.
- **Proteksi Berkas Internal**: [router.php](router.php) memblokir akses HTTP langsung (`403`) ke direktori `/data`, dotfile, berkas `.sqlite`/`.db`/`.md`/`.json`/`.lock`, serta modul internal (`lib.php`, `seed_data.php`, `dosen_pages.php`, `router.php`, `Dockerfile`).

## Struktur Berkas

```text
.
├── index.php             # Router utama halaman & alur mahasiswa
├── lib.php               # Koneksi SQLite, skema, sesi/CSRF, layout, drawer & live clock
├── dosen_pages.php       # Handler & tampilan khusus dosen
├── seed_data.php         # Seed 8 mata kuliah, silabus P1–P16, tugas & ujian
├── router.php            # Front-controller PHP built-in server & endpoint /health
├── app.css               # Gaya antarmuka, tema ungu-batik, drawer kiri & responsivitas
├── app.js                # Drawer menu kiri, live clock real-time, interaksi & efek UI
├── Dockerfile            # Konfigurasi container produksi PHP 8.2 CLI
├── manifest.webmanifest  # Konfigurasi PWA & ikon aplikasi
├── .gitignore            # Pengecualian data lokal, sqlite & log
└── README.md             # Dokumentasi proyek
```
