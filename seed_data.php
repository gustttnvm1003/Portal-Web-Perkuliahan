<?php
declare(strict_types=1);

if (!defined('SERAMBI_APP')) {
    http_response_code(403);
    exit;
}

/**
 * Daftar 8 mata kuliah semester Gasal 2026/2027.
 */
function serambi_courses_seed(): array
{
    return [
        ['pbp', 'IF301', 'Pemrograman Berbasis Platform', 3, 'Sugiyanto', 'Lab Mobile 1', 'Senin', '10.00–12.30', 'Membangun aplikasi di atas platform yang dipakai pengguna sehari-hari, dengan API dan siklus hidup yang jelas.'],
        ['adpl', 'IF302', 'Analisis dan Design PL', 3, 'Dian Novianti', 'Ruang D-105', 'Senin', '13.00–15.30', 'Menganalisis kebutuhan perangkat lunak lalu merancang model yang bisa diuji sebelum kode ditulis.'],
        ['tti', 'UN210', 'Tata Tulis Ilmiah (Metode Penelitian)', 2, 'Harry Gunawan', 'Ruang B-012', 'Selasa', '09.10–11.40', 'Menyusun proposal dan laporan ilmiah dengan struktur, sitasi, dan metode yang bisa dipertanggungjawabkan.'],
        ['arvr', 'IF320', 'Teknologi AR/VR', 3, 'Muhammad Imam', 'Lab Immersive', 'Selasa', '13.00–15.30', 'Merancang pengalaman augmented dan virtual reality yang nyaman dipakai, dari tracking hingga interaksi 3D.'],
        ['mpti', 'IF330', 'Manajemen Proyek Teknologi Informasi', 3, 'Gunadi Dwi Hantoro', 'Ruang C-301', 'Selasa', '18.30–21.00', 'Merencanakan, menjalankan, dan mengendalikan proyek TI dengan ruang lingkup, jadwal, dan risiko yang jelas.'],
        ['aicp', 'IF340', 'AI Computing Platform', 3, 'Freddy Wicaksono', 'Lab AI', 'Rabu', '18.00–22.00', 'Membangun dan mengoperasikan platform komputasi untuk workload kecerdasan artifisial, dari data hingga deployment.'],
        ['aik', 'AIK4', 'AIK 4', 2, 'Masdum Mustaqwa', 'Ruang A-101', 'Kamis', '10.00–12.30', 'Pendalaman nilai keislaman dan penerapannya dalam kehidupan akademik serta profesional.'],
        ['imk', 'IF318', 'Interaksi Manusia & Komputer', 3, 'Muhammad Imam', 'Studio Desain', 'Minggu', '13.00–15.30', 'Merancang alur yang bisa diuji orang sungguhan.'],
    ];
}

/**
 * Materi pertemuan 1..16 untuk seluruh 8 mata kuliah.
 * Pertemuan 6 = UTS, Pertemuan 16 = UAS pada setiap mata kuliah.
 */
function serambi_meetings_seed(): array
{
    $syllabus = [
        'pbp' => [
            1 => ['Kontrak kuliah & ekosistem platform modern', 10, "Pengenalan lanskap aplikasi mobile, web progresif, dan lintas platform beserta target perangkat mahasiswa.\n\nDisepakati tata tertib praktikum, bobot tugas, UTS di Pertemuan 6, dan UAS di Pertemuan 16."],
            2 => ['Arsitektur dasar aplikasi & konfigurasi proyek', 12, "Struktur direktori proyek, manajemen dependensi, dan pemisahan lapisan tampilan dengan logika bisnis.\n\nPastikan emulator atau perangkat uji berjalan stabil sebelum masuk materi komponen antarmuka."],
            3 => ['Komponen antarmuka & tata letak adaptif', 14, "Mendesain layar yang responsif untuk beragam ukuran layar dan orientasi.\n\nGunakan hirarki komponen yang mudah dirawat dan hindari nilai ukuran kaku."],
            4 => ['Navigasi antar-layar & pengelolaan state dasar', 13, "Pola stack navigation, pengiriman parameter antar layar, dan pemulihan state sementara.\n\nLatihan membangun alur tiga layar sederhana tanpa kehilangan data masukan pengguna."],
            5 => ['Penyimpanan lokal & preferensi pengguna', 15, "Memilih penyimpanan yang tepat antara key-value ringan, berkas lokal, dan SQLite.\n\nSimpan token atau preferensi dengan aman serta uji saat aplikasi ditutup paksa."],
            6 => ['UTS — Ujian Tengah Semester Pemrograman Berbasis Platform', 90, "Ujian Tengah Semester (UTS) dilaksanakan pada Pertemuan 6 di Lab Mobile 1.\n\nCakupan materi: Pertemuan 1–5 (ekosistem platform, arsitektur proyek, tata letak adaptif, navigasi, dan penyimpanan lokal). Mahasiswa mengerjakan studi kasus implementasi layar & state secara terstruktur."],
            7 => ['Siklus hidup aplikasi platform', 13, "Setiap platform punya siklus: dibuat, tampil, sempat tertutup, lalu dihancurkan. Kode yang mengabaikan fase ini sering bocor memori atau state usang.\n\nPelajari callback dan state yang aman di antara fase. Jangan mengasumsikan layar selalu terlihat.", 'pbp-m7'],
            8 => ['API platform dan batasannya', 14, "API resmi lebih stabil daripada akses privat. Baca izin, kuota, dan versi minimum yang dijanjikan dokumentasi.\n\nUji di perangkat atau emulator yang mewakili penggunamu, bukan hanya di mesin pengembangan.", 'pbp-m8'],
            9 => ['Integrasi layanan jaringan & parsing JSON', 15, "Mengambil data dari REST API dengan timeout, retry wajar, dan pemetaan model yang aman terhadap field kosong.\n\nTampilkan status memuat dan gagal dengan pesan yang bisa ditindaklanjuti pengguna."],
            10 => ['Izin perangkat, kamera, dan sensor', 14, "Meminta izin secara kontekstual saat fitur benar-benar digunakan, bukan sekaligus di awal.\n\nTangani kondisi ketika pengguna menolak izin secara permanen."],
            11 => ['Tugas latar belakang & notifikasi lokal', 13, "Menjadwalkan sinkronisasi ringan tanpa menguras baterai dan mematuhi batasan OS.\n\nRancang notifikasi yang relevan dan tidak mengganggu."],
            12 => ['Keamanan aplikasi & perlindungan kredensial', 14, "Jangan menanam kunci rahasia di kode klien; gunakan HTTPS dan penyimpanan terenkripsi perangkat.\n\nValidasi ulang seluruh masukan sebelum dikirim ke server."],
            13 => ['Pengujian unit & integrasi alur platform', 15, "Menulis unit test untuk state dan integration test untuk skenario utama aplikasi.\n\nAutomasi pengujian membantu mencegah regresi menjelang rilis."],
            14 => ['Optimasi performa & ukuran paket rilis', 13, "Memeriksa frame drop, kebocoran memori, dan pemangkasan aset tidak terpakai.\n\nUkur waktu start awal aplikasi pada perangkat kelas menengah."],
            15 => ['Penyiapan rilis, penandatanganan & evaluasi proyek', 12, "Menyiapkan build rilis, versioning, dan catatan perubahan sebelum demo akhir.\n\nLakukan review kode akhir bersama tim."],
            16 => ['UAS — Ujian Akhir Semester Pemrograman Berbasis Platform', 120, "Ujian Akhir Semester (UAS) dilaksanakan pada Pertemuan 16 di Lab Mobile 1.\n\nCakupan materi: Pertemuan 7–15 (siklus hidup, API resmi, jaringan, izin perangkat, keamanan, pengujian, dan optimasi rilis) melalui presentasi produk akhir dan uji kasus langsung."],
        ],
        'adpl' => [
            1 => ['Kontrak kuliah & proses rekayasa perangkat lunak', 11, "Memahami mengapa analisis dan desain dilakukan sebelum menulis baris kode produksi.\n\nMeninjau model proses iteratif, peran analis sistem, serta jadwal UTS (Pertemuan 6) dan UAS (Pertemuan 16)."],
            2 => ['Elisitasi kebutuhan & wawancara pemangku kepentingan', 13, "Teknik mengumpulkan kebutuhan lewat observasi, wawancara, dan analisis dokumen berjalan.\n\nCatat asumsi dan batasan proyek sejak pertemuan awal."],
            3 => ['Use case diagram & skenario aktor', 14, "Memetakan aktor utama, tujuan sistem, serta alur normal dan alur alternatif pada use case.\n\nSatu use case harus menggambarkan nilai nyata bagi pengguna."],
            4 => ['Pemodelan proses bisnis & activity diagram', 13, "Menggambarkan alur kerja lintas peran menggunakan swimlane, percabangan, dan sinkronisasi.\n\nIdentifikasi titik hambatan pada proses manual sebelum diotomasi."],
            5 => ['Spesifikasi kebutuhan terstruktur (SRS)', 14, "Menyusun dokumen SRS yang ringkas, konsisten, dan dapat ditelusuri ke tiap fitur.\n\nPersiapan penyusunan spesifikasi untuk bahan UTS."],
            6 => ['UTS — Ujian Tengah Semester Analisis dan Design PL', 90, "Ujian Tengah Semester (UTS) dilaksanakan pada Pertemuan 6 di Ruang D-105.\n\nCakupan materi: Pertemuan 1–5 (proses RPL, elisitasi kebutuhan, use case, activity diagram, dan penyusunan SRS). Mahasiswa menganalisis studi kasus dan menyusun model kebutuhan lengkap."],
            7 => ['Kebutuhan fungsional dan non-fungsional', 13, "Kebutuhan fungsional menjawab apa yang sistem lakukan. Non-fungsional menjawab seberapa baik: waktu respons, keamanan, kemudahan diubah.\n\nTulis kebutuhan yang bisa diuji. Hindari kalimat kabur seperti “sistem harus user-friendly” tanpa ukuran.", 'adpl-m7'],
            8 => ['Model desain sebelum kode', 14, "Diagram kelas, sekuens, dan arsitektur membantu menemukan celah sebelum implementasi mahal.\n\nSatu model yang dibahas bersama lebih berguna daripada dokumentasi panjang yang tidak dibaca.", 'adpl-m8'],
            9 => ['Class diagram & relasi antar objek', 15, "Menentukan entitas domain, atribut, method, serta relasi asosiasi, agregasi, komposisi, dan pewarisan.\n\nHindari kelas gemuk yang memegang terlalu banyak tanggung jawab."],
            10 => ['Sequence diagram & kolaborasi pesan', 14, "Memodelkan urutan pesan antar objek secara kronologis untuk skenario penting.\n\nPastikan setiap pesan di sequence diagram memiliki method yang sesuai di class diagram."],
            11 => ['Perancangan basis data & normalisasi skema', 14, "Menerjemahkan model domain menjadi tabel relasional, kunci tamu, dan indeks yang efisien.\n\nUji kueri utama terhadap skema agar bebas anomali."],
            12 => ['Pola arsitektur berlapis & modularitas', 13, "Memisahkan lapisan presentasi, domain/layanan, dan akses data.\n\nMembahas contoh MVC dan arsitektur modular yang mudah diuji."],
            13 => ['Design patterns dasar (Factory, Strategy, Observer)', 15, "Menggunakan pola desain seperlunya untuk mengatasi masalah perubahan perilaku tanpa over-engineering.\n\nBandingkan implementasi sebelum dan sesudah penerapan pola."],
            14 => ['Matriks ketertelusuran & inspeksi desain', 12, "Memastikan setiap butir kebutuhan di SRS terhubung ke model desain dan rencana uji.\n\nMelakukan walkthrough desain antar kelompok."],
            15 => ['Review dokumen desain akhir & kesiapan implementasi', 13, "Finalisasi paket desain perangkat lunak termasuk diagram arsitektur dan skenario uji penerimaan.\n\nSimulasi tanya jawab desain menjelang UAS."],
            16 => ['UAS — Ujian Akhir Semester Analisis dan Design PL', 120, "Ujian Akhir Semester (UAS) dilaksanakan pada Pertemuan 16 di Ruang D-105.\n\nCakupan materi: Pertemuan 7–15 (kebutuhan non-fungsional, class & sequence diagram, desain basis data, arsitektur, pola desain, dan inspeksi rancangan)."],
        ],
        'tti' => [
            1 => ['Kontrak kuliah & hakikat penulisan ilmiah', 10, "Membedakan tulisan opini populer dengan karya ilmiah yang berbasis bukti dan metode.\n\nMengenal peta tugas proposal, UTS di Pertemuan 6, dan UAS di Pertemuan 16."],
            2 => ['Menemukan topik & merumuskan masalah penelitian', 12, "Menyaring topik luas menjadi pertanyaan penelitian yang spesifik, layak, dan relevan dengan informatika.\n\nMenghindari rumusan masalah yang hanya dijawab dengan ya atau tidak."],
            3 => ['Penelusuran literatur & evaluasi sumber kredibel', 13, "Mencari artikel jurnal bereputasi, prosiding terindeks, dan menyaring sumber yang dapat dipertanggungjawabkan.\n\nMenggunakan kata kunci terstruktur pada basis data ilmiah."],
            4 => ['Manajemen referensi & gaya sitasi ilmiah', 12, "Mengelola pustaka menggunakan manajer referensi dan menerapkan gaya sitasi IEEE/APA secara konsisten.\n\nMenyelaraskan kutipan teks dengan daftar pustaka."],
            5 => ['Menyusun latar belakang & tujuan yang tajam', 13, "Menulis alur piramida terbalik dari konteks umum, kesenjangan penelitian, hingga kontribusi yang diajukan.\n\nMenjaga koherensi antar paragraf dengan kalimat topik yang jelas.", 'tti-m6'],
            6 => ['UTS — Ujian Tengah Semester Tata Tulis Ilmiah', 90, "Ujian Tengah Semester (UTS) dilaksanakan pada Pertemuan 6 di Ruang B-012.\n\nCakupan materi: Pertemuan 1–5 (hakikat karya ilmiah, perumusan masalah, penelusuran literatur, sitasi, dan penulisan latar belakang proposal)."],
            7 => ['Metode dan instrumen', 14, "Metode menjelaskan bagaimana data dikumpulkan dan dianalisis. Instrumen (angket, wawancara, observasi) harus selaras dengan pertanyaan penelitian.\n\nJelaskan populasi, sampel, dan alasan pemilihannya tanpa mengarang angka.", 'tti-m7'],
            8 => ['Sitasi dan etika akademik', 11, "Parafrase tetap membutuhkan sumber. Menyalin struktur kalimat sumber dengan ganti beberapa kata masih terlalu dekat.\n\nDaftar pustaka hanya memuat karya yang benar-benar dikutip di tubuh teks.", 'tti-m8'],
            9 => ['Teknik parafrase & sintesis tinjauan pustaka', 13, "Membandingkan dan mengelompokkan hasil beberapa penelitian terdahulu, bukan sekadar merangkum satu per satu.\n\nMenunjukkan posisi penelitian sendiri di antara literatur."],
            10 => ['Desain penelitian kuantitatif, kualitatif & R&D', 14, "Memilih pendekatan penelitian yang sesuai dengan objek kajian sistem informasi atau rekayasa perangkat lunak.\n\nMenentukan variabel, metrik evaluasi, dan skenario pengujian."],
            11 => ['Penyusunan instrumen validasi & uji kelayakan', 13, "Merancang lembar pengujian pengguna, skala penilaian, dan teknik validasi instrumen.\n\nMemastikan indikator instrumen menjawab rumusan masalah."],
            12 => ['Analisis data & penyajian tabel/grafik ilmiah', 14, "Menyajikan data hasil eksperimen atau survei dalam tabel dan grafik yang ringkas serta mudah dibaca.\n\nMenafsirkan temuan secara objektif tanpa melebih-lebihkan angka."],
            13 => ['Penulisan pembahasan, simpulan & abstrak', 12, "Menghubungkan hasil dengan tinjauan pustaka pada bagian pembahasan dan menulis simpulan yang tepat menjawab tujuan.\n\nMenyusun abstrak informatif dalam satu paragraf padat."],
            14 => ['Penyuntingan bahasa Indonesia baku & PUEBI/EYD V', 11, "Memperbaiki kalimat tidak efektif, ejaan serapan istilah TI, dan kepaduan antar subbab.\n\nPeer-review draf naskah antar mahasiswa."],
            15 => ['Klinik proposal & simulasi seminar rencana penelitian', 13, "Presentasi singkat naskah proposal lengkap dan penerimaan umpan balik metodologis sebelum ujian akhir.\n\nFinalisasi format dokumen ilmiah."],
            16 => ['UAS — Ujian Akhir Semester Tata Tulis Ilmiah', 120, "Ujian Akhir Semester (UAS) dilaksanakan pada Pertemuan 16 di Ruang B-012.\n\nCakupan materi: Pertemuan 7–15 (metode, instrumen, sintesis pustaka, penyajian hasil, serta pengumpulan dan pertanggungjawaban naskah proposal penelitian lengkap)."],
        ],
        'arvr' => [
            1 => ['Kontrak kuliah & spektrum realitas imersif (XR)', 11, "Membedakan Augmented Reality, Virtual Reality, dan Mixed Reality beserta contoh penerapan industri.\n\nPengenalan perangkat Lab Immersive serta jadwal UTS (Pertemuan 6) dan UAS (Pertemuan 16)."],
            2 => ['Matematika ruang 3D, transformasi & kamera', 14, "Memahami koordinat, vektor, rotasi quaternion vs Euler, serta sistem kamera virtual.\n\nLatihan menyusun hierarki objek 3D di scene editor."],
            3 => ['Pencahayaan, material PBR & optimasi aset 3D', 14, "Mengatur tekstur, shader dasar, dan batas poligon agar frame rate tetap tinggi pada perangkat XR.\n\nMengimpor aset 3D ringan untuk lingkungan real-time."],
            4 => ['Dasar tracking dan ruang 3D', 14, "AR menempel objek digital pada dunia nyata; VR mengganti seluruh medan pandang. Keduanya bergantung pada pelacakan posisi kepala dan, bila ada, tangan.\n\nNyaman tidak hanya soal FPS: latensi, skala dunia, dan titik acuan horison menentukan apakah pengguna mual.", 'arvr-m6'],
            5 => ['Marker-based & markerless AR (SLAM)', 15, "Cara kerja deteksi gambar, deteksi bidang datar, dan penempatan anchor di dunia nyata.\n\nUji kestabilan objek AR pada kondisi pencahayaan berbeda."],
            6 => ['UTS — Ujian Tengah Semester Teknologi AR/VR', 90, "Ujian Tengah Semester (UTS) dilaksanakan pada Pertemuan 6 di Lab Immersive.\n\nCakupan materi: Pertemuan 1–5 (konsep XR, ruang 3D, aset PBR, dasar tracking, dan mekanisme AR). Evaluasi pemahaman teknis serta demonstrasi scene dasar."],
            7 => ['Interaksi di lingkungan imersif', 15, "Raycast, grab, dan UI di ruang 3D membutuhkan umpan balik yang jelas. Jangan meniru pola layar sentuh mentah-mentah.\n\nUji dengan pengguna berdiri dan bergerak; skenario duduk di meja tidak cukup.", 'arvr-m7'],
            8 => ['Lokomosi VR & pencegahan motion sickness', 14, "Membandingkan teleportasi, smooth locomotion, dan vignette kenyamanan.\n\nMengukur keterbacaan jarak aman (guardian/boundary) untuk pengguna."],
            9 => ['Audio spasial & umpan balik haptik', 13, "Memanfaatkan arah datang suara 3D dan getaran kontroler untuk memperkuat rasa hadir (presence).\n\nDesain isyarat audio saat interaksi berhasil atau gagal."],
            10 => ['Antarmuka diegetik & UI dalam ruang 3D', 14, "Menempatkan panel kontrol, teks terbaca, dan tombol pada jarak pandang ergonomis.\n\nMenghindari teks terlalu kecil atau menempel kaku di wajah pengguna."],
            11 => ['Fisika objek & interaksi tangan langsung', 15, "Mengatur collider, rigidbody, dan genggaman berbasis fisika agar objek tidak menembus meja virtual.\n\nMenjaga respons tangan terasa alami."],
            12 => ['Kinerja rendering XR & profiling frame time', 14, "Mengenali batas 72–90 FPS, draw call batching, dan baked lighting untuk perangkat standalone.\n\nMembaca profiler GPU/CPU pada scene latihan."],
            13 => ['Pengujian kenyamanan & usability aplikasi imersif', 13, "Menyusun protokol uji coba bersama responden, observasi gerakan tubuh, dan kuesioner kenyamanan.\n\nMengumpulkan temuan masalah interaksi nyata."],
            14 => ['Integrasi proyek AR/VR terapan', 15, "Menggabungkan interaksi, audio, UI 3D, dan aset teroptimasi dalam satu prototipe utuh.\n\nUji ketahanan aplikasi untuk demonstrasi berulang."],
            15 => ['Gladi demo & penyempurnaan pengalaman pengguna', 12, "Simulasi presentasi proyek di Lab Immersive dan perbaikan temuan teknis terakhir menjelang UAS."],
            16 => ['UAS — Ujian Akhir Semester Teknologi AR/VR', 120, "Ujian Akhir Semester (UAS) dilaksanakan pada Pertemuan 16 di Lab Immersive.\n\nCakupan materi: Pertemuan 7–15 melalui demonstrasi langsung prototipe AR/VR, pengujian kenyamanan pengguna, dan tanya jawab arsitektur interaksi."],
        ],
        'mpti' => [
            1 => ['Kontrak kuliah & kerangka manajemen proyek TI', 11, "Mengenali tiga kendala utama proyek (ruang lingkup, waktu, biaya) serta kualitas produk TI.\n\nPenjelasan studi kasus proyek semester, UTS di Pertemuan 6, dan UAS di Pertemuan 16."],
            2 => ['Pemilihan proyek, studi kelayakan & analisis pemangku kepentingan', 13, "Menilai kelayakan teknis, operasional, dan finansial proyek TI.\n\nMemetakan kepentingan dan pengaruh tiap stakeholder proyek."],
            3 => ['Piagam proyek dan WBS', 13, "Piagam menetapkan tujuan, sponsor, dan batas ruang lingkup. WBS memecah pekerjaan sampai paket yang bisa diestimasi.\n\nTanpa WBS yang disepakati, jadwal dan biaya hanya tebakan.", 'mpti-m6'],
            4 => ['Estimasi durasi, biaya & penyusunan jadwal', 14, "Teknik estimasi tiga titik, ketergantungan tugas, dan penyusunan Gantt chart yang realistis.\n\nMenghitung cadangan waktu untuk aktivitas berisiko."],
            5 => ['Jalur kritis (CPM) & penyeimbangan sumber daya', 14, "Menentukan aktivitas dengan slack nol melalui perhitungan forward/backward pass.\n\nMengatasi alokasi anggota tim yang bertumpuk pada minggu yang sama."],
            6 => ['UTS — Ujian Tengah Semester Manajemen Proyek TI', 90, "Ujian Tengah Semester (UTS) dilaksanakan pada Pertemuan 6 di Ruang C-301.\n\nCakupan materi: Pertemuan 1–5 (kelayakan proyek, stakeholder, piagam proyek, WBS, estimasi jadwal, dan analisis jalur kritis)."],
            7 => ['Risiko, jadwal, dan komunikasi', 14, "Daftar risiko memuat peluang, dampak, dan respons. Jalur kritis menunjukkan aktivitas yang tidak boleh molor.\n\nRencana komunikasi menjelaskan siapa menerima status apa, seberapa sering, dan lewat kanal mana.", 'mpti-m7'],
            8 => ['Manajemen kualitas & penerimaan hasil (UAT)', 13, "Menetapkan standar kualitas sejak awal serta kriteria serah terima bersama pengguna.\n\nMembedakan verifikasi internal dan validasi oleh klien."],
            9 => ['Manajemen tim proyek, kepemimpinan & konflik', 12, "Pembagian peran RACI, dinamika tim perangkat lunak, dan penyelesaian hambatan lintas divisi."],
            10 => ['Metodologi Agile, Scrum & proyek hibrida', 14, "Kapan memakai pendekatan prediktif vs adaptif dalam proyek TI kampus dan perusahaan.\n\nMengelola backlog, sprint planning, dan retrospektif."],
            11 => ['Pengadaan vendor, kontrak & lisensi TI', 13, "Memahami spesifikasi pengadaan perangkat lunak/cloud, SLA, dan mitigasi ketergantungan vendor."],
            12 => ['Pemantauan kemajuan dengan Earned Value (EVM)', 15, "Menghitung PV, EV, AC, SPI, dan CPI untuk mendeteksi keterlambatan atau pembengkakan biaya lebih dini."],
            13 => ['Manajemen perubahan ruang lingkup (change control)', 13, "Prosedur menangani permintaan fitur tambahan di tengah proyek agar tidak terjadi scope creep tak terkendali."],
            14 => ['Pelaporan status eksekutif & audit proyek berjalan', 12, "Menyusun laporan progres ringkas berbasis data untuk sponsor proyek.\n\nLatihan presentasi status proyek bermasalah."],
            15 => ['Penutupan proyek, serah terima & lessons learned', 12, "Checklist penutupan proyek TI, dokumentasi operasional, dan evaluasi pembelajaran tim menjelang UAS."],
            16 => ['UAS — Ujian Akhir Semester Manajemen Proyek TI', 120, "Ujian Akhir Semester (UAS) dilaksanakan pada Pertemuan 16 di Ruang C-301.\n\nCakupan materi: Pertemuan 7–15 (manajemen risiko, kualitas, tim, Agile/Scrum, EVM, kontrol perubahan, dan penutupan proyek)."],
        ],
        'aicp' => [
            1 => ['Kontrak kuliah & arsitektur platform komputasi AI', 12, "Memahami kebutuhan komputasi modern untuk workload machine learning dan deep learning.\n\nPengenalan fasilitas Lab AI, jadwal UTS di Pertemuan 6, dan UAS di Pertemuan 16."],
            2 => ['Perangkat keras akselerasi (CPU, GPU, TPU) & memori', 14, "Bagaimana paralelisme matriks, VRAM, dan bandwidth memori menentukan kecepatan pelatihan model.\n\nMembaca spesifikasi perangkat keras untuk workload AI."],
            3 => ['Lingkungan terkontainerisasi untuk AI (Docker & GPU runtime)', 15, "Mengisolasi dependensi CUDA, Python, dan framework ML agar eksperimen dapat direproduksi lintas mesin.\n\nPraktik membuat image kontainer ringan."],
            4 => ['Pipeline data untuk model', 16, "Platform AI membutuhkan alur data yang berulang: ingest, bersih, versi, dan sajikan ke pelatihan atau inferensi.\n\nDokumentasikan skema dan kualitas data; model bagus tidak menolong data yang salah.", 'aicp-m6'],
            5 => ['Manajemen eksperimen, feature store & pelacakan metrik', 14, "Mencatat hiperparameter, versi dataset, dan artefak model secara otomatis.\n\nMembandingkan hasil percobaan tanpa kebingungan versi file."],
            6 => ['UTS — Ujian Tengah Semester AI Computing Platform', 90, "Ujian Tengah Semester (UTS) dilaksanakan pada Pertemuan 6 di Lab AI.\n\nCakupan materi: Pertemuan 1–5 (arsitektur platform AI, akselerator GPU, kontainerisasi, pipeline data, dan pelacakan eksperimen)."],
            7 => ['Serving dan monitoring model', 15, "Deployment model mencakup endpoint, skala, dan batasan latensi. Monitoring menangkap drift dan kegagalan diam-diam.\n\nSiapkan rollback sederhana sebelum trafik produksi masuk.", 'aicp-m7'],
            8 => ['Pelatihan terdistribusi (data parallel & model parallel)', 15, "Mengatur distribusi batch ke beberapa GPU serta memahami overhead komunikasi gradien."],
            9 => ['Optimasi model untuk inferensi (kuantisasi & pruning)', 14, "Mengompres ukuran model dan mempercepat latensi inferensi tanpa menurunkan akurasi secara drastis.\n\nUji perbandingan FP32 vs FP16/INT8."],
            10 => ['Orkestrasi workload AI & antrean job', 14, "Menjadwalkan job pelatihan terjadwal, mengelola kuota GPU, dan menangani kegagalan node."],
            11 => ['Batch inference vs real-time streaming inference', 13, "Memilih pola layanan prediksi sesuai SLA latensi dan pola kedatangan data produksi."],
            12 => ['Deteksi data drift, model drift & evaluasi berkelanjutan', 14, "Memantau pergeseran distribusi input dan penurunan performa model di lingkungan nyata.\n\nMenentukan ambang pemicu retraining."],
            13 => ['Keamanan model, privasi data & tata kelola AI', 13, "Melindungi endpoint model dari penyalahgunaan, menjaga anonimitas data latih, dan audit akses."],
            14 => ['Efisiensi biaya komputasi & arsitektur hybrid cloud', 13, "Menghitung biaya GPU on-premise vs cloud, spot instance, dan penskalaan otomatis."],
            15 => ['Tinjauan sistem end-to-end MLOps', 12, "Integrasi pipeline data, pelatihan otomatis, serving, dan monitoring dalam satu demo platform menjelang UAS."],
            16 => ['UAS — Ujian Akhir Semester AI Computing Platform', 120, "Ujian Akhir Semester (UAS) dilaksanakan pada Pertemuan 16 di Lab AI.\n\nCakupan materi: Pertemuan 7–15 melalui ujian analisis arsitektur MLOps dan demonstrasi deployment serta monitoring model."],
        ],
        'aik' => [
            1 => ['Kontrak kuliah & Islam sebagai rahmatan lil alamin', 10, "Pengantar tujuan mata kuliah AIK 4 dalam membentuk sarjana muslim yang berilmu dan berakhlak.\n\nKesepakatan perkuliahan, refleksi mingguan, UTS Pertemuan 6, dan UAS Pertemuan 16."],
            2 => ['Tauhid dan integrasi ilmu pengetahuan serta teknologi', 11, "Memandang ilmu komputer dan rekayasa sebagai sarana mengenal kebesaran Allah dan memberi manfaat bagi manusia."],
            3 => ['Etos kerja islami, ihsan & profesionalisme', 11, "Prinsip itqan (kesungguhan dan ketuntasan) dalam mengerjakan proyek perangkat lunak dan amanah pekerjaan."],
            4 => ['Nilai iman dalam praktik akademik', 10, "Integritas akademik adalah bagian dari amanah. Menulis jujur, mengutip dengan benar, dan bekerja sama tanpa menipu adalah latihan adab sehari-hari.\n\nDiskusikan satu contoh pelanggaran akademik dan cara menghindarinya.", 'aik-m6'],
            5 => ['Adab menuntut ilmu & relasi guru-mahasiswa', 10, "Membiasakan disiplin waktu, santun berdiskusi, dan menghargai proses belajar di era serba instan."],
            6 => ['UTS — Ujian Tengah Semester AIK 4', 90, "Ujian Tengah Semester (UTS) dilaksanakan pada Pertemuan 6 di Ruang A-101.\n\nCakupan materi: Pertemuan 1–5 (Islam rahmatan lil alamin, integrasi ilmu, etos ihsan, integritas akademik, dan adab menuntut ilmu)."],
            7 => ['Etika digital dan tanggung jawab', 12, "Ruang digital memperluas jangkauan kata dan karya. Tanggung jawab atas konten, privasi orang lain, dan cara berdiskusi tetap berlaku.\n\nBuat prinsip pribadi singkat untuk berkegiatan daring sebagai mahasiswa.", 'aik-m7'],
            8 => ['Kontribusi sosial dari ilmu', 11, "Ilmu yang dipelajari di kampus diharapkan kembali ke masyarakat. Rancang satu kegiatan kecil yang menghubungkan kompetensi prodi dengan kebutuhan sekitar.", 'aik-m8'],
            9 => ['Privasi, data pribadi & amanah informasi dalam Islam', 11, "Larangan tajassus (memata-matai) dan kewajiban menjaga kerahasiaan data pengguna dalam sistem informasi."],
            10 => ['Muamalah digital, fintech syariah & kejujuran transaksi', 12, "Prinsip kejelasan akad, bebas riba, dan transparansi dalam aplikasi perdagangan elektronik."],
            11 => ['Tabayyun terhadap informasi, hoaks & kecerdasan buatan', 11, "Sikap kritis melakukan verifikasi sebelum menyebarkan berita atau output AI yang belum teruji."],
            12 => ['Keadilan sosial & teknologi ramah aksesibilitas', 11, "Membangun solusi teknologi yang inklusif bagi kelompok rentan dan daerah tertinggal."],
            13 => ['Kepemimpinan profetik di organisasi & dunia kerja', 11, "Meneladani sifat shiddiq, amanah, tabligh, dan fathanah saat memimpin tim proyek TI."],
            14 => ['Pelestarian lingkungan (ekoteologi) & green computing', 10, "Tanggung jawab menjaga bumi sebagai khalifah termasuk efisiensi energi dan pengurangan limbah elektronik."],
            15 => ['Refleksi amal ilmiah & rencana pengabdian mahasiswa', 11, "Presentasi gagasan pengabdian berbasis kompetensi prodi dan evaluasi pemahaman menyeluruh menjelang UAS."],
            16 => ['UAS — Ujian Akhir Semester AIK 4', 120, "Ujian Akhir Semester (UAS) dilaksanakan pada Pertemuan 16 di Ruang A-101.\n\nCakupan materi: Pertemuan 7–15 (etika digital, amanah data, muamalah digital, tabayyun, kepemimpinan profetik, dan rencana kontribusi sosial)."],
        ],
        'imk' => [
            1 => ['Kontrak kuliah & mengapa interaksi manusia-komputer penting', 10, "Mengenali biaya dari desain antarmuka yang membingungkan dan prinsip dasar desain berpusat pada manusia.\n\nPengenalan Studio Desain, jadwal UTS di Pertemuan 6, dan UAS di Pertemuan 16."],
            2 => ['Kemampuan kognitif manusia, persepsi & beban memori', 12, "Memahami keterbatasan perhatian visual, hukum Fitts, hukum Hick, dan pengelompokan informasi.\n\nMenerapkan prinsip Gestalt pada tata letak layar."],
            3 => ['Riset pengguna awal & observasi kontekstual', 13, "Mengamati cara orang benar-benar bekerja di lingkungannya alih-alih menebak kebutuhan di meja kerja."],
            4 => ['Pengguna bukan rata-rata', 11, "Persona memuat tujuan, batasan, dan konteks — bukan biografi khayalan.\n\nSkenario adalah persona yang mengerjakan satu tugas. Gesekan ada di selisih langkah yang diharapkan dan yang dipaksakan sistem.", 'imk-m6'],
            5 => ['Arsitektur informasi & alur tugas (task flow)', 13, "Menyusun struktur navigasi yang masuk akal bagi pengguna baru maupun pengguna mahir.\n\nMemangkas langkah berlebih pada alur utama."],
            6 => ['UTS — Ujian Tengah Semester Interaksi Manusia & Komputer', 90, "Ujian Tengah Semester (UTS) dilaksanakan pada Pertemuan 6 di Studio Desain.\n\nCakupan materi: Pertemuan 1–5 (faktor manusia, riset pengguna, persona & skenario, serta arsitektur informasi)."],
            7 => ['Heuristik, dipakai pelan-pelan', 12, "Tandai masalah, sebut heuristik, perkirakan keparahan.\n\nLima temuan tajam lebih berguna daripada sepuluh yang diada-adakan. Sertakan lokasi persis dan saran satu kalimat.", 'imk-m7'],
            8 => ['Sketsa cepat, wireframe & prototipe kertas', 13, "Menguji ide struktur layar secepat mungkin sebelum menghabiskan waktu pada warna dan animasi."],
            9 => ['Prototipe interaktif & desain sistem komponen', 14, "Menjaga konsistensi tombol, formulir, status error, dan umpan balik di seluruh aplikasi."],
            10 => ['Tipografi, kontras warna & hierarki visual', 12, "Memastikan keterbacaan di berbagai kondisi cahaya layar dan menjaga fokus mata pada aksi utama."],
            11 => ['Aksesibilitas (WCAG) & desain inklusif', 13, "Navigasi keyboard, label pembaca layar, ukuran area sentuh memadai, dan tidak bergantung pada warna saja."],
            12 => ['UX writing & pesan kesalahan yang membantu', 11, "Menulis teks tombol, petunjuk formulir, dan pesan gagal yang ramah, jujur, dan memberi jalan keluar."],
            13 => ['Perancangan skenario usability testing', 13, "Menulis tugas pengujian tanpa mengarahkan responden dan menyiapkan metrik keberhasilan tugas."],
            14 => ['Pelaksanaan usability testing & analisis temuan', 14, "Mengamati keraguan pengguna saat uji coba langsung, mencatat akar masalah, dan memprioritaskan perbaikan."],
            15 => ['Iterasi desain akhir & kritik studio', 12, "Memperbaiki prototipe berdasarkan data pengujian dan menyiapkan laporan portofolio IMK menjelang UAS."],
            16 => ['UAS — Ujian Akhir Semester Interaksi Manusia & Komputer', 120, "Ujian Akhir Semester (UAS) dilaksanakan pada Pertemuan 16 di Studio Desain.\n\nCakupan materi: Pertemuan 7–15 melalui pemaparan studi kasus evaluasi heuristik, hasil usability testing, dan demonstrasi prototipe teriterasi."],
        ],
    ];

    $rows = [];
    foreach ($syllabus as $cid => $weeks) {
        for ($w = 1; $w <= 16; $w++) {
            $entry = $weeks[$w];
            $kind = $w === 6 ? 'uts' : ($w === 16 ? 'uas' : 'materi');
            $id = $w === 6 ? ($cid . '-p6-uts') : ($w === 16 ? ($cid . '-p16-uas') : ($entry[3] ?? ($cid . '-m' . $w)));
            $rows[] = [
                'id' => $id,
                'course_id' => $cid,
                'week' => $w,
                'kind' => $kind,
                'title' => $entry[0],
                'minutes' => $entry[1],
                'body' => $entry[2],
            ];
        }
    }
    return $rows;
}

/**
 * Daftar tugas awal (non-ujian) beserta nomor pertemuannya.
 */
function serambi_tasks_seed(): array
{
    return [
        ['pbp-1', 'pbp', 4, 'Prototipe layar platform', 20, 100, '2026-10-23T23:59:00+07:00', 'Buat tiga layar terhubung di platform pilihanmu. Jelaskan siklus hidup dan satu API yang dipakai.', 'Siklus hidup disebut|API resmi|Alur antar layar jelas', 0],
        ['pbp-2', 'pbp', 10, 'Integrasi API & penanganan error', 20, 100, '2026-11-23T23:59:00+07:00', 'Sambungkan satu endpoint resmi, tampilkan loading/error, dan jelaskan batasan kuota atau izin.', 'Error ditangani|Izin/kuota disebut|Cuplikan respons', 0],
        ['adpl-1', 'adpl', 4, 'Spesifikasi kebutuhan singkat', 20, 100, '2026-10-30T23:59:00+07:00', 'Tulis 8 kebutuhan (5 fungsional, 3 non-fungsional) untuk sistem peminjaman lab. Setiap butir harus bisa diuji.', 'Bisa diuji|Ada non-fungsional|Tanpa kalimat kabur', 0],
        ['adpl-2', 'adpl', 10, 'Model desain (kelas & sekuens)', 20, 100, '2026-11-24T23:59:00+07:00', 'Gambar diagram kelas inti dan satu diagram sekuens untuk alur peminjaman. Sertakan penjelasan keputusan desain.', 'Ada kelas inti|Sekuens lengkap|Keputusan dijelaskan', 0],
        ['tti-1', 'tti', 4, 'Outline proposal penelitian', 20, 100, '2026-10-21T23:59:00+07:00', 'Susun outline proposal: latar, rumusan masalah, tujuan, dan metode singkat (maks. 800 kata).', 'Rumusan jelas|Metode selaras|Sitasi awal ada', 0],
        ['tti-2', 'tti', 10, 'Draft tinjauan pustaka', 20, 100, '2026-11-21T23:59:00+07:00', 'Tulis tinjauan pustaka 1000–1500 kata dari minimal lima sumber, dengan parafrase dan daftar pustaka.', 'Bukan tempel kutipan|Sumber memadai|Pustaka cocok', 0],
        ['aik-1', 'aik', 4, 'Refleksi integritas akademik', 20, 100, '2026-10-24T23:59:00+07:00', 'Tulis refleksi 600–800 kata tentang satu godaan pelanggaran akademik dan cara menolaknya secara praktis.', 'Contoh konkret|Nilai keislaman|Rencana pribadi', 0],
        ['aik-2', 'aik', 10, 'Rencana kontribusi sosial', 20, 100, '2026-11-26T23:59:00+07:00', 'Rancang satu kegiatan kecil berbasis ilmu prodi untuk masyarakat sekitar (tujuan, langkah, indikator sederhana).', 'Tujuan jelas|Langkah realistis|Ada indikator', 0],
        ['imk-1', 'imk', 4, 'Persona dan satu skenario', 20, 100, '2026-11-05T23:59:00+07:00', 'Persona mahasiswa di ponsel, satu skenario revisi saat sinyal jelek.', 'Batasan memengaruhi desain|Gesekan menunjuk layar', 0],
        ['imk-2', 'imk', 10, 'Laporan evaluasi heuristik', 20, 100, '2026-11-25T23:59:00+07:00', 'Evaluasi satu alur aplikasi kampus dengan 5–7 temuan. Tiap temuan: lokasi, heuristik, keparahan, saran singkat.', 'Lokasi jelas|Heuristik disebut|Saran satu kalimat', 0],
        ['arvr-1', 'arvr', 4, 'Prototipe scene AR/VR', 20, 100, '2026-10-28T23:59:00+07:00', 'Buat satu scene sederhana (AR atau VR) dengan interaksi dasar dan catatan kenyamanan pengguna.', 'Ada interaksi|Catatan latensi/nyaman|Cuplikan scene', 0],
        ['arvr-2', 'arvr', 10, 'Evaluasi usability imersif', 20, 100, '2026-11-24T23:59:00+07:00', 'Uji scene pada 3 pengguna: tugas, kendala, dan perbaikan prioritas.', 'Protokol uji|Temuan konkret|Perbaikan berurutan', 0],
        ['mpti-1', 'mpti', 4, 'Piagam & WBS proyek TI', 20, 100, '2026-10-27T23:59:00+07:00', 'Susun piagam proyek singkat dan WBS hingga paket kerja untuk satu proyek sistem informasi kampus.', 'Ruang lingkup jelas|WBS rapi|Estimasi awal', 0],
        ['mpti-2', 'mpti', 10, 'Rencana risiko & komunikasi', 20, 100, '2026-11-24T23:59:00+07:00', 'Buat register risiko (min. 8) dan rencana komunikasi stakeholder untuk proyek yang sama.', 'Risiko + respons|Jalur kritis disebut|Kanal komunikasi', 0],
        ['aicp-1', 'aicp', 4, 'Desain pipeline data AI', 20, 100, '2026-10-29T23:59:00+07:00', 'Rancang pipeline dari sumber data hingga fitur siap latih, termasuk versi dan cek kualitas.', 'Tahapan jelas|Versi data|Cek kualitas', 0],
        ['aicp-2', 'aicp', 10, 'Rencana serving model', 20, 100, '2026-11-25T23:59:00+07:00', 'Jelaskan endpoint inferensi, metrik monitoring, dan prosedur rollback.', 'Endpoint|Metrik drift|Rollback', 0],
    ];
}

/**
 * Daftar ujian UTS (Pertemuan 6) & UAS (Pertemuan 16) untuk semua 8 mata kuliah.
 * Memiliki kolom pengumpulan daring agar mahasiswa dapat mengunggah lembar/proyek ujian maupun dinilai dosen.
 */
function serambi_exams_seed(): array
{
    $details = [
        'pbp' => [
            'uts' => ['Ujian Tengah Semester (UTS) · Pertemuan 6', '2026-11-02T23:59:00+07:00', 'Ujian praktik dan konsep Pemrograman Berbasis Platform meliputi arsitektur, tata letak adaptif, navigasi, dan penyimpanan lokal (Pertemuan 1–5).', 'Ketepatan alur navigasi|Manajemen state & penyimpanan|Kerapian struktur komponen'],
            'uas' => ['Ujian Akhir Semester (UAS) · Pertemuan 16', '2027-01-11T23:59:00+07:00', 'Presentasi dan unggah berkas aplikasi lintas platform terintegrasi API resmi, pengujian, keamanan, dan paket rilis (Pertemuan 7–15).', 'Integrasi API & error handling|Keamanan & pengujian|Kelayakan rilis aplikasi'],
        ],
        'adpl' => [
            'uts' => ['Ujian Tengah Semester (UTS) · Pertemuan 6', '2026-11-02T23:59:00+07:00', 'Ujian analisis kebutuhan studi kasus menggunakan use case, activity diagram, dan penyusunan SRS (Pertemuan 1–5).', 'Ketepatan aktor & use case|Kelengkapan alur proses|Kebutuhan teruji pada SRS'],
            'uas' => ['Ujian Akhir Semester (UAS) · Pertemuan 16', '2027-01-11T23:59:00+07:00', 'Ujian perancangan berorientasi objek mencakup class diagram, sequence diagram, skema database, dan pola arsitektur (Pertemuan 7–15).', 'Konsistensi antar diagram|Normalisasi skema data|Ketertelusuran desain'],
        ],
        'tti' => [
            'uts' => ['Ujian Tengah Semester (UTS) · Pertemuan 6', '2026-11-03T23:59:00+07:00', 'Evaluasi perumusan masalah, penelusuran pustaka kredibel, gaya sitasi, dan penulisan latar belakang (Pertemuan 1–5).', 'Ketajaman rumusan masalah|Kualitas sumber literatur|Ketepatan format sitasi'],
            'uas' => ['Ujian Akhir Semester (UAS) · Pertemuan 16', '2027-01-12T23:59:00+07:00', 'Ujian akhir dan pengumpulan naskah proposal penelitian lengkap mencakup tinjauan pustaka dan metodologi (Pertemuan 7–15).', 'Kesesuaian metode & instrumen|Bebas plagiarisme & parafrase baik|Kepaduan naskah proposal'],
        ],
        'arvr' => [
            'uts' => ['Ujian Tengah Semester (UTS) · Pertemuan 6', '2026-11-03T23:59:00+07:00', 'Ujian teori dan unggah dokumentasi scene 3D dasar, pencahayaan PBR, serta pelacakan AR/VR (Pertemuan 1–5).', 'Pemahaman ruang & transformasi 3D|Kestabilan tracking|Performa dasar scene'],
            'uas' => ['Ujian Akhir Semester (UAS) · Pertemuan 16', '2027-01-12T23:59:00+07:00', 'Demonstrasi dan pengumpulan paket proyek aplikasi AR/VR akhir dengan interaksi ergonomis dan laporan uji kenyamanan (Pertemuan 7–15).', 'Kualitas interaksi 3D|Kenyamanan & frame rate|Hasil evaluasi pengguna'],
        ],
        'mpti' => [
            'uts' => ['Ujian Tengah Semester (UTS) · Pertemuan 6', '2026-11-03T23:59:00+07:00', 'Ujian studi kasus penyusunan piagam proyek, WBS, estimasi durasi, dan perhitungan jalur kritis CPM (Pertemuan 1–5).', 'Kejelasan ruang lingkup & WBS|Ketepatan hitung jalur kritis|Realisme penjadwalan'],
            'uas' => ['Ujian Akhir Semester (UAS) · Pertemuan 16', '2027-01-12T23:59:00+07:00', 'Ujian pengendalian proyek TI meliputi manajemen risiko, EVM, kontrol perubahan, dan dokumen penutupan proyek (Pertemuan 7–15).', 'Analisis risiko & mitigasi|Ketepatan hitung SPI/CPI|Rekomendasi pengendalian'],
        ],
        'aicp' => [
            'uts' => ['Ujian Tengah Semester (UTS) · Pertemuan 6', '2026-11-04T23:59:00+07:00', 'Ujian desain infrastruktur komputasi AI, kontainerisasi GPU, dan pipeline data versi (Pertemuan 1–5).', 'Arsitektur komputasi & kontainer|Kualitas pipeline & versi data|Reproduksibilitas eksperimen'],
            'uas' => ['Ujian Akhir Semester (UAS) · Pertemuan 16', '2027-01-13T23:59:00+07:00', 'Ujian akhir platform MLOps mencakup optimasi model, orkestrasi, serving endpoint, dan deteksi drift (Pertemuan 7–15).', 'Desain serving & rollback|Strategi monitoring drift|Efisiensi & tata kelola AI'],
        ],
        'aik' => [
            'uts' => ['Ujian Tengah Semester (UTS) · Pertemuan 6', '2026-11-05T23:59:00+07:00', 'Ujian pemahaman integrasi nilai tauhid, etos ihsan, adab ilmiah, dan integritas akademik (Pertemuan 1–5).', 'Kedalaman pemahaman nilai|Keterkaitan dengan praktik kuliah|Kejujuran argumentasi'],
            'uas' => ['Ujian Akhir Semester (UAS) · Pertemuan 16', '2027-01-14T23:59:00+07:00', 'Ujian akhir dan paparan rencana kontribusi sosial berbasis etika digital dan kepemimpinan profetik (Pertemuan 7–15).', 'Penerapan etika digital & amanah|Kelayakan aksi sosial prodi|Refleksi kepemimpinan'],
        ],
        'imk' => [
            'uts' => ['Ujian Tengah Semester (UTS) · Pertemuan 6', '2026-11-08T23:59:00+07:00', 'Ujian analisis kognitif, penyusunan persona, skenario pengguna, dan arsitektur informasi (Pertemuan 1–5).', 'Ketajaman batasan persona|Kejelasan task flow|Penerapan prinsip persepsi'],
            'uas' => ['Ujian Akhir Semester (UAS) · Pertemuan 16', '2027-01-17T23:59:00+07:00', 'Sidang proyek desain antarmuka berdasarkan evaluasi heuristik, aksesibilitas, dan hasil usability testing (Pertemuan 7–15).', 'Kedalaman temuan usability|Kualitas iterasi prototipe|Kepatuhan aksesibilitas'],
        ],
    ];

    $rows = [];
    foreach ($details as $cid => $pair) {
        $rows[] = [$cid . '-uts', $cid, 'uts', 6, $pair['uts'][0], 25, 100, $pair['uts'][1], $pair['uts'][2], $pair['uts'][3], 0];
        $rows[] = [$cid . '-uas', $cid, 'uas', 16, $pair['uas'][0], 35, 100, $pair['uas'][1], $pair['uas'][2], $pair['uas'][3], 0];
    }
    return $rows;
}

/**
 * Menghasilkan daftar tugas untuk seluruh Pertemuan 1..16 pada setiap mata kuliah.
 * Mempertahankan ID tugas minggu 4 (*-1), minggu 10 (*-2), UTS (P6), dan UAS (P16).
 */
function serambi_all_week_tasks_seed(): array
{
    $existingByCourseWeek = [];
    foreach (serambi_tasks_seed() as $t) {
        // [$id, $cid, $week, $title, $weight, $max, $due, $brief, $rubric, $inClass]
        $existingByCourseWeek[$t[1] . ':' . $t[2]] = [$t[0], $t[1], 'tugas', $t[2], $t[3], $t[4], $t[5], $t[6], $t[7], $t[8], 0];
    }
    foreach (serambi_exams_seed() as $ex) {
        $existingByCourseWeek[$ex[1] . ':' . $ex[3]] = $ex;
    }

    $dueByWeek = [
        1 => '2026-09-27T23:59:00+07:00',
        2 => '2026-10-04T23:59:00+07:00',
        3 => '2026-10-11T23:59:00+07:00',
        5 => '2026-10-25T23:59:00+07:00',
        7 => '2026-11-15T23:59:00+07:00',
        8 => '2026-11-22T23:59:00+07:00',
        9 => '2026-11-29T23:59:00+07:00',
        11 => '2026-12-13T23:59:00+07:00',
        12 => '2026-12-20T23:59:00+07:00',
        13 => '2026-12-27T23:59:00+07:00',
        14 => '2027-01-03T23:59:00+07:00',
        15 => '2027-01-10T23:59:00+07:00',
    ];

    $all = [];
    foreach (serambi_meetings_seed() as $m) {
        $cid = $m['course_id'];
        $w = (int) $m['week'];
        $key = $cid . ':' . $w;
        if (isset($existingByCourseWeek[$key])) {
            $all[] = $existingByCourseWeek[$key];
            continue;
        }
        $id = $cid . '-t' . $w;
        $title = 'Tugas Pertemuan ' . $w . ' · ' . $m['title'];
        $brief = 'Kerjakan latihan atau ringkasan terkait topik "' . $m['title'] . '" pada Pertemuan ' . $w . '. Tuliskan jawaban terstruktur atau lampirkan berkas pendukung (PDF/DOCX/gambar, maks. 8 MB).';
        $rubric = 'Kesesuaian dengan materi Pertemuan ' . $w . '|Kejelasan uraian atau bukti kerja|Kerapian penyajian';
        $due = $dueByWeek[$w] ?? '2026-12-06T23:59:00+07:00';
        $all[] = [$id, $cid, 'tugas', $w, $title, 5, 100, $due, $brief, $rubric, 0];
    }

    return $all;
}
