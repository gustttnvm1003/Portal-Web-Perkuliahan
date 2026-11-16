<?php
declare(strict_types=1);

if (!defined('SERAMBI_APP')) {
    define('SERAMBI_APP', true);
}

require_once __DIR__ . '/seed_data.php';

const SCHEMA_VERSION = 5;

function data_dir(): string
{
    $env = getenv('SERAMBI_DATA_DIR') ?: getenv('RAILWAY_VOLUME_MOUNT_PATH');
    $dir = ($env !== false && $env !== '') ? rtrim($env, "/\\") : (__DIR__ . '/data');
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

function db_path(): string
{
    return data_dir() . DIRECTORY_SEPARATOR . 'serambi.sqlite';
}

function uploads_dir(): string
{
    return data_dir() . DIRECTORY_SEPARATOR . 'uploads';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $path = db_path();
    $fresh = !file_exists($path);
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    if ($fresh) {
        seed($pdo);
    }
    ensure_schema($pdo);
    return $pdo;
}

function seed(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL,
            nim TEXT NOT NULL
        );
        CREATE TABLE courses (
            id TEXT PRIMARY KEY,
            code TEXT NOT NULL,
            name TEXT NOT NULL,
            sks INTEGER NOT NULL,
            lecturer TEXT NOT NULL,
            room TEXT NOT NULL,
            day_name TEXT NOT NULL,
            time_label TEXT NOT NULL,
            summary TEXT NOT NULL
        );
        CREATE TABLE materials (
            id TEXT PRIMARY KEY,
            course_id TEXT NOT NULL,
            week INTEGER NOT NULL,
            kind TEXT NOT NULL DEFAULT 'materi',
            title TEXT NOT NULL,
            minutes INTEGER NOT NULL,
            body TEXT NOT NULL,
            FOREIGN KEY (course_id) REFERENCES courses(id)
        );
        CREATE TABLE assignments (
            id TEXT PRIMARY KEY,
            course_id TEXT NOT NULL,
            kind TEXT NOT NULL DEFAULT 'tugas',
            week INTEGER NOT NULL DEFAULT 0,
            title TEXT NOT NULL,
            weight INTEGER NOT NULL,
            max_score INTEGER NOT NULL,
            due TEXT NOT NULL,
            brief TEXT NOT NULL,
            rubric TEXT NOT NULL,
            in_class INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (course_id) REFERENCES courses(id)
        );
        CREATE TABLE reads (
            user_id INTEGER NOT NULL,
            material_id TEXT NOT NULL,
            PRIMARY KEY (user_id, material_id)
        );
        CREATE TABLE submissions (
            id INTEGER PRIMARY KEY,
            assignment_id TEXT NOT NULL,
            user_id INTEGER NOT NULL,
            body TEXT NOT NULL,
            attachment TEXT NOT NULL DEFAULT '',
            attachment_name TEXT NOT NULL DEFAULT '',
            submitted_at TEXT NOT NULL,
            score INTEGER,
            feedback TEXT NOT NULL DEFAULT '',
            UNIQUE (assignment_id, user_id)
        );
        CREATE TABLE attendance (
            id INTEGER PRIMARY KEY,
            course_id TEXT NOT NULL,
            week INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'hadir',
            note TEXT NOT NULL DEFAULT '',
            updated_at TEXT NOT NULL,
            UNIQUE (course_id, week, user_id),
            FOREIGN KEY (course_id) REFERENCES courses(id),
            FOREIGN KEY (user_id) REFERENCES users(id)
        );
        CREATE TABLE announcements (
            id INTEGER PRIMARY KEY,
            course_id TEXT NOT NULL DEFAULT '',
            author_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (author_id) REFERENCES users(id)
        );
    SQL);

    $insUser = $pdo->prepare('INSERT INTO users (name, username, password_hash, role, nim) VALUES (?, ?, ?, ?, ?)');
    $insUser->execute(['Bagus Anugrah Dinata', 'Bagus Anugrah Dinata', password_hash('Bagoesaja', PASSWORD_DEFAULT), 'mahasiswa', '240511001']);
    $bagusId = (int) $pdo->lastInsertId();
    $insUser->execute(['Dr. Laksmi Wardhana', 'laksmi', password_hash('serambi', PASSWORD_DEFAULT), 'dosen', 'NIDN-001']);

    $c = $pdo->prepare('INSERT INTO courses (id, code, name, sks, lecturer, room, day_name, time_label, summary) VALUES (?,?,?,?,?,?,?,?,?)');
    foreach (serambi_courses_seed() as $row) {
        $c->execute($row);
    }

    $m = $pdo->prepare('INSERT INTO materials (id, course_id, week, kind, title, minutes, body) VALUES (?,?,?,?,?,?,?)');
    foreach (serambi_meetings_seed() as $row) {
        $m->execute([$row['id'], $row['course_id'], $row['week'], $row['kind'], $row['title'], $row['minutes'], $row['body']]);
    }

    $a = $pdo->prepare('INSERT INTO assignments (id, course_id, kind, week, title, weight, max_score, due, brief, rubric, in_class) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    foreach (serambi_all_week_tasks_seed() as $row) {
        $a->execute($row);
    }

    $pdo->prepare('INSERT INTO reads (user_id, material_id) VALUES (?, ?)')->execute([$bagusId, 'pbp-m7']);
    $pdo->prepare('INSERT INTO reads (user_id, material_id) VALUES (?, ?)')->execute([$bagusId, 'tti-m7']);
    $pdo->prepare('INSERT INTO reads (user_id, material_id) VALUES (?, ?)')->execute([$bagusId, 'aik-m7']);
}

/**
 * Memastikan skema database, 16 pertemuan (UTS P6 & UAS P16), dan akun khusus dosen tersedia.
 * Aman dijalankan pada database lama maupun baru.
 */
function ensure_schema(PDO $pdo): void
{
    $ver = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    if ($ver >= SCHEMA_VERSION) {
        return;
    }

    $pdo->beginTransaction();
    try {
        $matCols = array_column($pdo->query('PRAGMA table_info(materials)')->fetchAll(), 'name');
        if (!in_array('kind', $matCols, true)) {
            $pdo->exec("ALTER TABLE materials ADD COLUMN kind TEXT NOT NULL DEFAULT 'materi'");
        }

        $taskCols = array_column($pdo->query('PRAGMA table_info(assignments)')->fetchAll(), 'name');
        if (!in_array('kind', $taskCols, true)) {
            $pdo->exec("ALTER TABLE assignments ADD COLUMN kind TEXT NOT NULL DEFAULT 'tugas'");
        }
        if (!in_array('week', $taskCols, true)) {
            $pdo->exec('ALTER TABLE assignments ADD COLUMN week INTEGER NOT NULL DEFAULT 0');
        }

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS attendance (
                id INTEGER PRIMARY KEY,
                course_id TEXT NOT NULL,
                week INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT 'hadir',
                note TEXT NOT NULL DEFAULT '',
                updated_at TEXT NOT NULL,
                UNIQUE (course_id, week, user_id),
                FOREIGN KEY (course_id) REFERENCES courses(id),
                FOREIGN KEY (user_id) REFERENCES users(id)
            );
            CREATE TABLE IF NOT EXISTS announcements (
                id INTEGER PRIMARY KEY,
                course_id TEXT NOT NULL DEFAULT '',
                author_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                body TEXT NOT NULL,
                created_at TEXT NOT NULL,
                FOREIGN KEY (author_id) REFERENCES users(id)
            );
        SQL);

        // Pastikan akun khusus dosen dan akun mahasiswa contoh tersedia.
        $demoUsers = [
            ['Dr. Hendra Wijaya, M.Kom.', 'dosen', 'dosen123', 'dosen', 'NIDN-0412088501'],
            ['Dr. Laksmi Wardhana', 'laksmi', 'serambi', 'dosen', 'NIDN-001'],
            ['Siti Rahmawati', 'siti', 'mhs123', 'mahasiswa', '240511002'],
            ['Rizky Pratama', 'rizky', 'mhs123', 'mahasiswa', '240511003'],
        ];
        $findUser = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $addUser = $pdo->prepare('INSERT INTO users (name, username, password_hash, role, nim) VALUES (?, ?, ?, ?, ?)');
        foreach ($demoUsers as [$name, $username, $pass, $role, $nim]) {
            $findUser->execute([$username]);
            if (!$findUser->fetch()) {
                $addUser->execute([$name, $username, password_hash($pass, PASSWORD_DEFAULT), $role, $nim]);
            }
        }

        // Pada DB lama, pindahkan materi biasa yang kebetulan menempati week = 6 ke week = 5 agar Pertemuan 6 khusus UTS.
        $oldW6 = $pdo->query("SELECT id, course_id FROM materials WHERE week = 6 AND kind != 'uts'")->fetchAll();
        $chkW5 = $pdo->prepare('SELECT id FROM materials WHERE course_id = ? AND week = 5');
        $moveToW5 = $pdo->prepare('UPDATE materials SET week = 5 WHERE id = ?');
        foreach ($oldW6 as $row) {
            $chkW5->execute([$row['course_id']]);
            if (!$chkW5->fetch()) {
                $moveToW5->execute([$row['id']]);
            } else {
                $pdo->prepare('DELETE FROM materials WHERE id = ?')->execute([$row['id']]);
            }
        }

        // Lengkapi seluruh 16 pertemuan (P6 = UTS, P16 = UAS) untuk semua mata kuliah.
        $hasSlot = $pdo->prepare('SELECT id FROM materials WHERE course_id = ? AND week = ?');
        $hasId = $pdo->prepare('SELECT 1 FROM materials WHERE id = ?');
        $insMat = $pdo->prepare('INSERT INTO materials (id, course_id, week, kind, title, minutes, body) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $updKind = $pdo->prepare('UPDATE materials SET kind = ? WHERE course_id = ? AND week = ?');
        foreach (serambi_meetings_seed() as $m) {
            $hasSlot->execute([$m['course_id'], $m['week']]);
            $existing = $hasSlot->fetch();
            if (!$existing) {
                $id = $m['id'];
                $hasId->execute([$id]);
                if ($hasId->fetch()) {
                    $id = $m['course_id'] . '-p' . $m['week'];
                }
                $insMat->execute([$id, $m['course_id'], $m['week'], $m['kind'], $m['title'], $m['minutes'], $m['body']]);
            } elseif ($m['week'] === 6 || $m['week'] === 16) {
                $updKind->execute([$m['kind'], $m['course_id'], $m['week']]);
            }
        }

        // Lengkapi tugas untuk seluruh Pertemuan 1..16 pada setiap mata kuliah + buka kolom pengumpulan UTS & UAS.
        $pdo->exec("UPDATE assignments SET week = 4 WHERE id LIKE '%-1' AND week = 0");
        $pdo->exec("UPDATE assignments SET week = 10 WHERE id LIKE '%-2' AND week = 0");
        $pdo->exec('UPDATE assignments SET in_class = 0 WHERE in_class != 0');
        $insTask = $pdo->prepare('INSERT OR IGNORE INTO assignments (id, course_id, kind, week, title, weight, max_score, due, brief, rubric, in_class) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach (serambi_all_week_tasks_seed() as $taskRow) {
            $insTask->execute($taskRow);
        }

        // Kosongkan seluruh pengumpulan tugas & lampiran agar semua berstatus belum dikumpulkan (versi skema 4).
        if ($ver < 4) {
            reset_all_submissions($pdo);
        }

        // Seed presensi awal Pertemuan 1..6 agar fitur Presensi Dosen langsung terisi riwayat awal.
        if ((int) $pdo->query('SELECT COUNT(*) FROM attendance')->fetchColumn() === 0) {
            $students = $pdo->query("SELECT id, username FROM users WHERE role = 'mahasiswa' ORDER BY id")->fetchAll();
            $courses = $pdo->query('SELECT id FROM courses')->fetchAll();
            $insAtt = $pdo->prepare('INSERT OR IGNORE INTO attendance (course_id, week, user_id, status, note, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($courses as $cRow) {
                for ($w = 1; $w <= 6; $w++) {
                    foreach ($students as $idx => $stu) {
                        $status = 'hadir';
                        $note = $w === 6 ? 'Mengikuti UTS' : '';
                        if ($w === 3 && $idx === 2) {
                            $status = 'izin';
                            $note = 'Kegiatan lomba kampus';
                        } elseif ($w === 5 && $idx === 1 && $cRow['id'] === 'tti') {
                            $status = 'sakit';
                            $note = 'Surat keterangan dokter';
                        }
                        $insAtt->execute([$cRow['id'], $w, (int) $stu['id'], $status, $note, '2026-11-08T10:00:00+07:00']);
                    }
                }
            }
        }

        // Seed pengumuman awal dari dosen jika belum ada.
        if ((int) $pdo->query('SELECT COUNT(*) FROM announcements')->fetchColumn() === 0) {
            $findUser->execute(['dosen']);
            $dosenId = (int) $findUser->fetchColumn();
            if ($dosenId > 0) {
                $insAnn = $pdo->prepare('INSERT INTO announcements (course_id, author_id, title, body, created_at) VALUES (?, ?, ?, ?, ?)');
                $insAnn->execute([
                    '',
                    $dosenId,
                    'Ketentuan UTS (Pertemuan 6) & UAS (Pertemuan 16)',
                    "Seluruh mata kuliah melaksanakan Ujian Tengah Semester (UTS) pada Pertemuan 6 dan Ujian Akhir Semester (UAS) pada Pertemuan 16 sesuai jadwal ruang masing-masing.\n\nPastikan seluruh materi Pertemuan 1–5 telah dipelajari sebelum UTS dan cek rincian tugas secara berkala.",
                    '2026-11-01T08:00:00+07:00',
                ]);
                $insAnn->execute([
                    'pbp',
                    $dosenId,
                    'Persiapan Praktikum API & Demo Proyek UAS',
                    'Mulai Pertemuan 8 kita masuk integrasi API resmi dan persiapan proyek akhir yang akan diujikan saat UAS pada Pertemuan 16 di Lab Mobile 1.',
                    '2026-11-10T09:30:00+07:00',
                ]);
            }
        }

        $pdo->exec('PRAGMA user_version = ' . SCHEMA_VERSION);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function reset_all_submissions(PDO $pdo): void
{
    $pdo->exec('DELETE FROM submissions');
    $uploadDir = uploads_dir();
    if (is_dir($uploadDir)) {
        foreach (glob($uploadDir . '/*') ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function start_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (PHP_SAPI !== 'cli') {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            ini_set('session.use_strict_mode', '1');
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        session_start();
    }
}

function current_user(): ?array
{
    start_session();
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([(int) $_SESSION['uid']]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function is_dosen(array $user): bool
{
    return ($user['role'] ?? '') === 'dosen';
}

function require_user(): array
{
    $user = current_user();
    if (!$user) {
        header('Location: index.php?p=masuk');
        exit;
    }
    return $user;
}

function require_dosen(): array
{
    $user = require_user();
    if (!is_dosen($user)) {
        http_response_code(403);
        $html = '<p class="kicker">Akses Dibatasi</p>';
        $html .= '<h1>Khusus akun Dosen.</h1>';
        $html .= '<p class="lede">Halaman atau aksi ini hanya tersedia untuk dosen pengampu. Masuk menggunakan akun dosen untuk mengelola materi pertemuan, presensi, pengumuman, dan penilaian kelas.</p>';
        $html .= '<div class="card"><p>Anda saat ini masuk sebagai <strong>' . h($user['name']) . '</strong> (' . h($user['role']) . ').</p><p><a class="btn-link" href="index.php?p=beranda">Kembali ke Meja</a></p></div>';
        layout('Akses Khusus Dosen', $html, $user);
        exit;
    }
    return $user;
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function check_csrf(): void
{
    start_session();
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals((string) ($_SESSION['csrf'] ?? ''), $sent)) {
        http_response_code(400);
        exit('Sesi tidak cocok. Muat ulang halaman.');
    }
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function due_label(string $iso): array
{
    $due = strtotime($iso);
    if ($due === false) {
        return ['calm', $iso];
    }
    $diff = $due - time();
    $day = 86400;
    if ($diff < 0) {
        $late = max(1, (int) ceil(-$diff / $day));
        return ['late', $late === 1 ? 'Terlambat' : 'Lewat ' . $late . ' hari'];
    }
    if ($diff < $day) {
        return ['soon', 'Hari ini'];
    }
    $days = (int) ceil($diff / $day);
    if ($days === 1) {
        return ['soon', 'Besok'];
    }
    if ($days < 8) {
        return ['soon', $days . ' hari lagi'];
    }
    return ['calm', date('j M Y', $due)];
}

function letter_grade(float $score): array
{
    if ($score >= 85) return ['A', 4.0];
    if ($score >= 80) return ['A-', 3.7];
    if ($score >= 75) return ['B+', 3.3];
    if ($score >= 70) return ['B', 3.0];
    if ($score >= 65) return ['B-', 2.7];
    if ($score >= 60) return ['C+', 2.3];
    if ($score >= 55) return ['C', 2.0];
    if ($score >= 45) return ['D', 1.0];
    return ['E', 0.0];
}

function attendance_labels(): array
{
    return [
        'hadir' => 'Hadir',
        'izin' => 'Izin',
        'sakit' => 'Sakit',
        'alpa' => 'Alpa',
    ];
}

/**
 * Menghitung rekap nilai seluruh mata kuliah & IPS seorang mahasiswa.
 */
function student_course_stats(PDO $pdo, int $studentId): array
{
    $courses = $pdo->query('SELECT * FROM courses')->fetchAll();
    $taskStmt = $pdo->prepare('SELECT * FROM assignments WHERE course_id = ? ORDER BY week, due');
    $subStmt = $pdo->prepare('SELECT * FROM submissions WHERE assignment_id = ? AND user_id = ?');

    $results = [];
    $sks = 0;
    $points = 0.0;
    $gradedCount = 0;

    foreach ($courses as $course) {
        $taskStmt->execute([$course['id']]);
        $tasks = $taskStmt->fetchAll();
        $weight = 0;
        $acc = 0.0;
        $taskRows = [];

        foreach ($tasks as $task) {
            $subStmt->execute([$task['id'], $studentId]);
            $got = $subStmt->fetch() ?: null;
            if ($got && $got['score'] !== null) {
                $maxScore = max(1, (int) $task['max_score']);
                $w = (int) $task['weight'];
                $weight += $w;
                $acc += ((int) $got['score'] / $maxScore) * $w;
                $gradedCount++;
            }
            $taskRows[] = ['task' => $task, 'sub' => $got];
        }

        $score = $weight === 0 ? null : round(($acc / $weight) * 100, 1);
        $grade = null;
        if ($score !== null) {
            $grade = letter_grade($score);
            $sks += (int) $course['sks'];
            $points += $grade[1] * (int) $course['sks'];
        }

        $results[] = [
            'course' => $course,
            'tasks' => $taskRows,
            'score' => $score,
            'grade' => $grade,
        ];
    }

    return [
        'courses' => $results,
        'sks' => $sks,
        'ips' => $sks === 0 ? '—' : number_format($points / $sks, 2, ',', ''),
        'graded_count' => $gradedCount,
    ];
}

function detect_device(): string
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    header('Vary: User-Agent');
    if (preg_match('/iPhone|iPod|iPad/i', $ua)) {
        return 'ios';
    }
    if (preg_match('/Android|Mobile|Windows Phone|IEMobile|Opera Mini/i', $ua)) {
        return 'mobile';
    }
    return 'desktop';
}

function render_live_clock(string $extraClass = ''): string
{
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
    $days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $months = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $dateText = $days[(int) $now->format('w')] . ', ' . (int) $now->format('j') . ' ' . $months[(int) $now->format('n')] . ' ' . $now->format('Y');
    $timeText = $now->format('H:i:s');
    $isoText = $now->format('Y-m-d\TH:i:s');
    $cls = 'live-clock' . ($extraClass !== '' ? ' ' . $extraClass : '');

    return '<div class="' . h($cls) . '" data-live-clock aria-label="Jam langsung">'
        . '<span class="live-clock-dot" aria-hidden="true"></span>'
        . '<span class="live-clock-date" data-clock-date>' . h($dateText) . '</span>'
        . '<span class="live-clock-sep" aria-hidden="true">·</span>'
        . '<time class="live-clock-time" data-clock-time datetime="' . h($isoText) . '">' . h($timeText) . '</time>'
        . '<span class="live-clock-tz" data-clock-tz>WIB</span>'
        . '</div>';
}

function layout(string $title, string $body, ?array $user): void
{
    $device = detect_device();
    $isDosen = $user ? is_dosen($user) : false;
    $page = $_GET['p'] ?? 'beranda';

    $nav = '';
    if ($user) {
        $primaryItems = $isDosen
            ? [
                'beranda' => ['Meja Dosen', '⌂'],
                'kuliah' => ['Kelola Kelas', '☰'],
                'tugas' => ['Penilaian', '✎'],
                'nilai' => ['Rekap Nilai', '★'],
            ]
            : [
                'beranda' => ['Meja', '⌂'],
                'kuliah' => ['Kuliah', '☰'],
                'tugas' => ['Tugas', '✎'],
                'nilai' => ['Nilai', '★'],
            ];

        foreach ($primaryItems as $id => $meta) {
            $on = ($page === $id || ($id === 'tugas' && $page === 'tugas_detail') || ($id === 'kuliah' && ($page === 'kelas' || $page === 'materi'))) ? ' is-on' : '';
            $nav .= '<a class="' . $on . '" href="index.php?p=' . $id . '"><span class="nav-ico" aria-hidden="true">' . $meta[1] . '</span><span>' . $meta[0] . '</span></a>';
        }

        if ($isDosen) {
            $nav .= '<span class="nav-group-label">Khusus Dosen</span>';
            $dosenItems = [
                'dosen_materi' => ['Kelola Materi & Tugas', '✦'],
                'dosen_presensi' => ['Presensi Pertemuan', '✓'],
                'dosen_pengumuman' => ['Pengumuman & Mahasiswa', '📢'],
            ];
            foreach ($dosenItems as $id => $meta) {
                $on = $page === $id ? ' is-on' : '';
                $nav .= '<a class="' . $on . '" href="index.php?p=' . $id . '"><span class="nav-ico" aria-hidden="true">' . $meta[1] . '</span><span>' . $meta[0] . '</span></a>';
            }
        }
    }

    $roleClass = $user ? (' role-' . ($isDosen ? 'dosen' : 'mahasiswa')) : '';
    echo '<!doctype html><html lang="id" class="dev-' . $device . $roleClass . '"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
    echo '<title>' . h($title) . ' · Universitas Indramayu</title>';
    echo '<meta name="theme-color" content="#4c1d95">';
    echo '<meta name="apple-mobile-web-app-capable" content="yes">';
    echo '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">';
    echo '<meta name="apple-mobile-web-app-title" content="UNIRA">';
    echo '<meta name="format-detection" content="telephone=no">';
    echo '<meta name="mobile-web-app-capable" content="yes">';
    echo '<link rel="apple-touch-icon" sizes="180x180" href="icon-180.png">';
    echo '<link rel="manifest" href="manifest.webmanifest">';
    echo '<link rel="icon" type="image/svg+xml" href="logo.svg">';
    echo '<link rel="stylesheet" href="app.css?v=' . (int) @filemtime(__DIR__ . '/app.css') . '">';
    echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,520;9..144,620&family=Outfit:wght@400;500;600&display=swap">';
    echo '</head><body>';

    if ($user) {
        $roleBadge = $isDosen
            ? '<span class="role-badge role-badge-dosen">Dosen Pengampu</span>'
            : '<span class="role-badge role-badge-mhs">Mahasiswa</span>';
        $portalLabel = $isDosen ? 'Portal Dosen · Pengampu' : 'Portal Mahasiswa';

        echo '<div class="app">';
        echo '<aside id="side-drawer">';
        echo '<div class="aside-head"><a class="brand" href="index.php?p=beranda"><img class="logo" src="logo.svg" width="36" height="36" alt=""><span class="brand-text"><span class="brand-top">Universitas</span><span class="brand-bottom">Indramayu</span></span></a><button type="button" class="drawer-close" aria-label="Tutup menu">×</button></div>';
        echo '<p class="muted term">Gasal 2026/2027</p>';
        echo '<nav class="side-nav" aria-label="Menu utama">' . $nav . '</nav>';
        echo '<div class="who">' . $roleBadge . '<strong>' . h($user['name']) . '</strong><span>' . h($user['nim']) . ' · ' . h($user['role']) . '</span></div>';
        echo '</aside>';
        echo '<div class="drawer-backdrop" aria-hidden="true"></div>';

        $back = $page !== 'beranda'
            ? '<a class="hdr-back" href="index.php?p=beranda" data-back>‹ Kembali</a>'
            : '';
        $mobileWho = '<div class="mobile-who">' . $roleBadge . '<span class="mobile-who-name">' . h($user['name']) . '</span><span class="mobile-who-id">' . h($user['nim']) . '</span></div>';
        echo '<div class="main"><header><div class="hdr-left"><button type="button" class="nav-toggle" aria-label="Buka menu" aria-controls="side-drawer" aria-expanded="false">☰</button>' . $back . '<span class="muted hide-sm">' . $portalLabel . '</span><span class="hdr-title">' . h($title) . '</span></div><div class="hdr-right">' . render_live_clock() . '<a class="out" href="index.php?p=keluar">Keluar</a></div></header><div class="content">' . $mobileWho;
        echo $body;
        echo '</div></div>';
        echo '</div>';
    } else {
        echo '<div class="auth">' . $body . '</div>';
    }
    echo '<script src="app.js?v=' . (int) @filemtime(__DIR__ . '/app.js') . '" defer></script>';
    echo '</body></html>';
}
