<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/dosen_pages.php';

start_session();
$page = $_GET['p'] ?? 'beranda';

if ($page === 'masuk' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $user['id'];
        redirect('index.php?p=beranda');
    }
    $_SESSION['flash'] = 'Nama pengguna atau sandi tidak cocok.';
    redirect('index.php?p=masuk');
}

if ($page === 'unduh') {
    $user = require_user();
    $id = (string) ($_GET['id'] ?? '');
    $stmt = db()->prepare('SELECT s.*, a.title FROM submissions s JOIN assignments a ON a.id = s.assignment_id WHERE s.id = ?');
    $stmt->execute([(int) $id]);
    $row = $stmt->fetch();
    if (!$row || ($row['attachment'] ?? '') === '') {
        http_response_code(404);
        exit('Berkas tidak ditemukan.');
    }
    if (!is_dosen($user) && (int) $row['user_id'] !== (int) $user['id']) {
        http_response_code(403);
        exit('Tidak diizinkan.');
    }
    $path = uploads_dir() . DIRECTORY_SEPARATOR . $row['attachment'];
    if (!is_file($path)) {
        http_response_code(404);
        exit('Berkas hilang di server.');
    }
    $name = $row['attachment_name'] !== '' ? $row['attachment_name'] : $row['attachment'];
    $mime = mime_content_type($path) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($path));
    header('Content-Disposition: inline; filename="' . str_replace('"', '', $name) . '"');
    readfile($path);
    exit;
}

if ($page === 'keluar') {
    $_SESSION = [];
    session_destroy();
    redirect('index.php?p=masuk');
}

if ($page === 'kumpul' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = require_user();
    check_csrf();
    if (is_dosen($user)) {
        redirect('index.php?p=tugas');
    }
    $id = (string) ($_POST['id'] ?? '');
    $returnMat = (string) ($_POST['return_mat'] ?? '');
    $body = trim((string) ($_POST['body'] ?? ''));
    $task = db()->prepare('SELECT * FROM assignments WHERE id = ?');
    $task->execute([$id]);
    $assignment = $task->fetch();
    if (!$assignment) {
        redirect('index.php?p=tugas');
    }
    $existing = db()->prepare('SELECT * FROM submissions WHERE assignment_id = ? AND user_id = ?');
    $existing->execute([$id, $user['id']]);
    $prev = $existing->fetch() ?: null;
    if ($prev && $prev['score'] !== null) {
        redirect(safe_return_url(db(), $id, $returnMat, ''));
    }

    $attachment = $prev['attachment'] ?? '';
    $attachmentName = $prev['attachment_name'] ?? '';
    if (!empty($_FILES['berkas']['name']) && (int) ($_FILES['berkas']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $file = $_FILES['berkas'];
        $orig = (string) $file['name'];
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'zip', 'txt'];
        if (!in_array($ext, $allowed, true) || (int) $file['size'] > 8 * 1024 * 1024) {
            redirect(safe_return_url(db(), $id, $returnMat, 'err=berkas'));
        }
        $dir = uploads_dir();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $stored = 'u' . (int) $user['id'] . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $id) . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $dest = $dir . '/' . $stored;
        if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
            redirect(safe_return_url(db(), $id, $returnMat, 'err=berkas'));
        }
        if ($attachment !== '' && is_file($dir . '/' . $attachment)) {
            @unlink($dir . '/' . $attachment);
        }
        $attachment = $stored;
        $attachmentName = $orig;
    }

    if (strlen($body) < 10 && $attachment === '') {
        redirect(safe_return_url(db(), $id, $returnMat, 'err=kosong'));
    }
    if ($body === '' && $attachment !== '') {
        $body = '(Pengumpulan berkas tanpa teks tambahan)';
    }

    $save = db()->prepare('INSERT INTO submissions (assignment_id, user_id, body, attachment, attachment_name, submitted_at, score, feedback)
        VALUES (?, ?, ?, ?, ?, ?, NULL, ?)
        ON CONFLICT(assignment_id, user_id) DO UPDATE SET
            body = excluded.body,
            attachment = excluded.attachment,
            attachment_name = excluded.attachment_name,
            submitted_at = excluded.submitted_at');
    $save->execute([$id, $user['id'], $body, $attachment, $attachmentName, date('c'), '']);
    redirect(safe_return_url(db(), $id, $returnMat, 'ok=1'));
}

if ($page === 'nilai_simpan' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = require_dosen();
    check_csrf();
    $id = (string) ($_POST['id'] ?? '');
    $returnMat = (string) ($_POST['return_mat'] ?? '');
    $studentId = (int) ($_POST['user_id'] ?? 0);
    $scoreRaw = trim((string) ($_POST['score'] ?? ''));
    $feedback = trim((string) ($_POST['feedback'] ?? ''));
    $task = db()->prepare('SELECT max_score, kind FROM assignments WHERE id = ?');
    $task->execute([$id]);
    $assignment = $task->fetch();
    $score = $scoreRaw === '' ? -1 : (int) $scoreRaw;

    if (!$assignment || $studentId <= 0 || $score < 0 || $score > (int) $assignment['max_score']) {
        redirect(safe_return_url(db(), $id, $returnMat, 'err=nilai'));
    }

    $defaultBody = $assignment['kind'] === 'uts'
        ? '(Nilai UTS Pertemuan 6)'
        : ($assignment['kind'] === 'uas' ? '(Nilai UAS Pertemuan 16)' : '(Penilaian langsung oleh dosen)');

    $upsert = db()->prepare('INSERT INTO submissions (assignment_id, user_id, body, attachment, attachment_name, submitted_at, score, feedback)
        VALUES (?, ?, ?, "", "", ?, ?, ?)
        ON CONFLICT(assignment_id, user_id) DO UPDATE SET
            score = excluded.score,
            feedback = excluded.feedback');
    $upsert->execute([$id, $studentId, $defaultBody, date('c'), $score, $feedback]);
    redirect(safe_return_url(db(), $id, $returnMat, 'ok=1'));
}

if ($page === 'nilai_ujian_massal' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = require_dosen();
    check_csrf();
    $id = (string) ($_POST['id'] ?? '');
    $returnMat = (string) ($_POST['return_mat'] ?? '');
    $task = db()->prepare('SELECT max_score, kind FROM assignments WHERE id = ?');
    $task->execute([$id]);
    $assignment = $task->fetch();
    if (!$assignment) {
        redirect('index.php?p=tugas');
    }

    $scores = $_POST['score'] ?? [];
    $feedbacks = $_POST['feedback'] ?? [];
    $students = db()->query("SELECT id FROM users WHERE role = 'mahasiswa'")->fetchAll();
    $defaultBody = $assignment['kind'] === 'uts'
        ? '(Nilai UTS Pertemuan 6)'
        : ($assignment['kind'] === 'uas' ? '(Nilai UAS Pertemuan 16)' : '(Penilaian langsung oleh dosen)');

    $upsert = db()->prepare('INSERT INTO submissions (assignment_id, user_id, body, attachment, attachment_name, submitted_at, score, feedback)
        VALUES (?, ?, ?, "", "", ?, ?, ?)
        ON CONFLICT(assignment_id, user_id) DO UPDATE SET
            score = excluded.score,
            feedback = excluded.feedback');
    $now = date('c');

    foreach ($students as $stu) {
        $sid = (int) $stu['id'];
        $rawScore = trim((string) ($scores[$sid] ?? ''));
        if ($rawScore === '') {
            continue;
        }
        $val = (int) $rawScore;
        if ($val < 0 || $val > (int) $assignment['max_score']) {
            continue;
        }
        $fb = trim((string) ($feedbacks[$sid] ?? ''));
        $upsert->execute([$id, $sid, $defaultBody, $now, $val, $fb]);
    }
    redirect(safe_return_url(db(), $id, $returnMat, 'ok=1'));
}

if ($page === 'masuk') {
    if (current_user()) {
        redirect('index.php?p=beranda');
    }
    $flash = $_SESSION['flash'] ?? '';
    unset($_SESSION['flash']);
    $err = $flash ? '<p class="err">' . h($flash) . '</p>' : '';
    $demoHtml = getenv('SHOW_DEMO') === '0' ? '' : '
        <div class="demo">
            <p class="demo-head">Akun Demo Tersedia</p>
            <div class="demo-grid">
                <div class="demo-box">
                    <span class="role-badge role-badge-mhs">Mahasiswa</span>
                    <p><strong>Bagus Anugrah Dinata</strong><br>Sandi: <code>Bagoesaja</code></p>
                    <button type="button" class="btn-ghost-sm" data-fill-user="Bagus Anugrah Dinata" data-fill-pass="Bagoesaja">Isi akun mahasiswa</button>
                </div>
                <div class="demo-box">
                    <span class="role-badge role-badge-dosen">Khusus Dosen</span>
                    <p><strong>dosen</strong> (atau <code>laksmi</code>)<br>Sandi: <code>dosen123</code> / <code>serambi</code></p>
                    <button type="button" class="btn-ghost-sm" data-fill-user="dosen" data-fill-pass="dosen123">Isi akun dosen</button>
                </div>
            </div>
        </div>';
    $html = '<form class="card auth-card" method="post" action="index.php?p=masuk">
        <p class="brand lone"><img class="logo" src="logo.svg" width="44" height="44" alt=""><span class="brand-text"><span class="brand-top">Universitas</span><span class="brand-bottom">Indramayu</span></span></p>
        ' . render_live_clock('live-clock-auth') . '
        <h1>Masuk ke portal akademik.</h1>
        <p class="muted">Akses Mahasiswa atau Akun Khusus Dosen Pengampu.</p>
        ' . $err . '
        <input type="hidden" name="csrf" value="' . h(csrf_token()) . '">
        <label>Nama pengguna<input id="login-user" name="username" autocomplete="username" required></label>
        <label>Sandi<input id="login-pass" name="password" type="password" autocomplete="current-password" required></label>
        <button type="submit">Masuk</button>' . $demoHtml . '
    </form>';
    layout('Masuk', $html, null);
    exit;
}

$user = require_user();
$pdo = db();
$isDosen = is_dosen($user);

if (handle_dosen_post($page, $pdo)) {
    exit;
}

if (render_dosen_page($page, $pdo)) {
    exit;
}

if ($page === 'baca') {
    $id = (string) ($_GET['id'] ?? '');
    if (!$isDosen) {
        $pdo->prepare('INSERT OR IGNORE INTO reads (user_id, material_id) VALUES (?, ?)')->execute([$user['id'], $id]);
    }
    redirect('index.php?p=materi&id=' . urlencode($id));
}

if ($page === 'beranda') {
    $courses = $pdo->query('SELECT * FROM courses')->fetchAll();

    if ($isDosen) {
        $studentCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'mahasiswa'")->fetchColumn();
        $pendingCount = (int) $pdo->query('SELECT COUNT(*) FROM submissions WHERE score IS NULL')->fetchColumn();
        $taskCount = (int) $pdo->query('SELECT COUNT(*) FROM assignments')->fetchColumn();
        $annCount = (int) $pdo->query('SELECT COUNT(*) FROM announcements')->fetchColumn();

        $pendingByCourse = [];
        $submittedByCourse = [];
        foreach ($pdo->query('SELECT a.course_id, COUNT(*) AS tot, SUM(CASE WHEN s.score IS NULL THEN 1 ELSE 0 END) AS pend FROM submissions s JOIN assignments a ON a.id = s.assignment_id GROUP BY a.course_id') as $r) {
            $submittedByCourse[$r['course_id']] = (int) $r['tot'];
            $pendingByCourse[$r['course_id']] = (int) $r['pend'];
        }

        $cards = '';
        foreach ($courses as $course) {
            $pend = $pendingByCourse[$course['id']] ?? 0;
            $subTot = $submittedByCourse[$course['id']] ?? 0;
            if ($pend > 0) {
                $pill = '<span class="card-pill card-pill-warn">' . $pend . ' perlu nilai</span>';
            } elseif ($subTot > 0) {
                $pill = '<span class="card-pill">Semua dinilai</span>';
            } else {
                $pill = '<span class="card-pill">Belum ada masuk</span>';
            }
            $cards .= '<a class="card" href="index.php?p=kelas&id=' . h($course['id']) . '">'
                . '<div class="between"><span class="code">' . h($course['code']) . '</span>' . $pill . '</div>'
                . '<h2>' . h($course['name']) . '</h2>'
                . '<p class="muted">' . h($course['day_name']) . ' · ' . h($course['time_label']) . ' · ' . h($course['room']) . '</p>'
                . '<p class="course-meta-line"><span class="meta-chip">16 Pertemuan</span><span class="meta-chip meta-uts">UTS P6</span><span class="meta-chip meta-uas">UAS P16</span></p>'
                . '</a>';
        }

        $queue = $pdo->query('SELECT s.*, u.name AS student_name, u.nim, a.title AS task_title, c.code
            FROM submissions s
            JOIN users u ON u.id = s.user_id
            JOIN assignments a ON a.id = s.assignment_id
            JOIN courses c ON c.id = a.course_id
            WHERE s.score IS NULL
            ORDER BY s.submitted_at DESC LIMIT 5')->fetchAll();

        $qRows = '';
        foreach ($queue as $q) {
            $qRows .= '<a class="row" href="index.php?p=tugas_detail&id=' . h($q['assignment_id']) . '"><span><strong>' . h($q['student_name']) . ' · ' . h($q['task_title']) . '</strong><small>' . h($q['code']) . ' · Dikirim ' . h(date('j M H:i', strtotime($q['submitted_at']))) . '</small></span><em class="wait">Beri nilai</em></a>';
        }
        if ($qRows === '') {
            $hasAnySub = (int) $pdo->query('SELECT COUNT(*) FROM submissions')->fetchColumn();
            $qRows = '<p class="empty-note">' . ($hasAnySub > 0 ? 'Semua pengumpulan tugas mahasiswa sudah dinilai.' : 'Belum ada pengumpulan tugas yang masuk dari mahasiswa.') . '</p>';
        }

        $statsGrid = '<div class="stats-grid">'
            . '<div class="card stat-card"><span class="muted">Mahasiswa Terdaftar</span><strong>' . $studentCount . '</strong></div>'
            . '<div class="card stat-card"><span class="muted">Menunggu Dinilai</span><strong>' . $pendingCount . '</strong></div>'
            . '<div class="card stat-card"><span class="muted">Komponen Tugas & Ujian</span><strong>' . $taskCount . '</strong></div>'
            . '<div class="card stat-card"><span class="muted">Pengumuman Aktif</span><strong>' . $annCount . '</strong></div>'
            . '</div>';

        $quickTools = '<div class="action-strip">'
            . '<a class="action-btn" href="index.php?p=dosen_materi">✦ Kelola Materi 1–16 & Buat Tugas</a>'
            . '<a class="action-btn" href="index.php?p=dosen_presensi">✓ Isi Presensi Pertemuan</a>'
            . '<a class="action-btn" href="index.php?p=dosen_pengumuman">📢 Pengumuman & Mahasiswa</a>'
            . '<a class="action-btn" href="index.php?p=nilai">★ Rekap Nilai Mahasiswa</a>'
            . '</div>';

        $html = '<p class="kicker">Khusus Dosen · Minggu ke-8</p>'
            . '<h1>Meja Dosen.</h1>'
            . '<p class="lede">Kelola 8 mata kuliah dengan 16 pertemuan (UTS di Pertemuan 6, UAS di Pertemuan 16), presensi kelas, pengumuman, dan penilaian mahasiswa.</p>'
            . $statsGrid . $quickTools
            . '<h2 class="section">Mata Kuliah Pengajaran</h2>'
            . '<div class="grid">' . $cards . '</div>'
            . '<h2 class="section">Antrean Pengumpulan Perlu Dinilai</h2>'
            . '<div class="stack">' . $qRows . '</div>';

        layout('Meja Dosen', $html, $user);
        exit;
    }

    // Mode Mahasiswa
    $openStmt = $pdo->prepare("SELECT
            SUM(CASE WHEN a.kind = 'tugas' THEN 1 ELSE 0 END) AS open_tasks,
            SUM(CASE WHEN a.kind IN ('uts','uas') THEN 1 ELSE 0 END) AS open_exams
        FROM assignments a
        WHERE NOT EXISTS (
            SELECT 1 FROM submissions s WHERE s.assignment_id = a.id AND s.user_id = ?
        )");
    $openStmt->execute([$user['id']]);
    $openRow = $openStmt->fetch();
    $openTasks = (int) ($openRow['open_tasks'] ?? 0);
    $openExams = (int) ($openRow['open_exams'] ?? 0);

    $readByCourse = [];
    $rStmt = $pdo->prepare('SELECT m.course_id, COUNT(*) AS cnt FROM reads r JOIN materials m ON m.id = r.material_id WHERE r.user_id = ? GROUP BY m.course_id');
    $rStmt->execute([$user['id']]);
    foreach ($rStmt->fetchAll() as $r) {
        $readByCourse[$r['course_id']] = (int) $r['cnt'];
    }

    $cards = '';
    foreach ($courses as $course) {
        $rCnt = $readByCourse[$course['id']] ?? 0;
        $cards .= '<a class="card" href="index.php?p=kelas&id=' . h($course['id']) . '">'
            . '<div class="between"><span class="code">' . h($course['code']) . '</span><span class="card-pill">' . $rCnt . '/16 pertemuan</span></div>'
            . '<h2>' . h($course['name']) . '</h2>'
            . '<p class="muted">' . h($course['day_name']) . ' · ' . h($course['time_label']) . '</p>'
            . '<p class="course-meta-line"><span class="meta-chip">Pertemuan 1–16</span><span class="meta-chip meta-uts">UTS P6</span><span class="meta-chip meta-uas">UAS P16</span></p>'
            . '</a>';
    }

    $anns = $pdo->query('SELECT a.*, c.code FROM announcements a LEFT JOIN courses c ON c.id = a.course_id ORDER BY a.created_at DESC, a.id DESC LIMIT 2')->fetchAll();
    $annBlock = '';
    if ($anns) {
        $annCards = '';
        foreach ($anns as $ann) {
            $scope = $ann['code'] ? $ann['code'] : 'Semua Mata Kuliah';
            $annCards .= '<div class="card ann-mini"><span class="badge-soft">' . h($scope) . '</span><strong>' . h($ann['title']) . '</strong><p class="muted">' . nl2br(h($ann['body'])) . '</p></div>';
        }
        $annBlock = '<h2 class="section">Pengumuman Dosen</h2><div class="grid grid-2">' . $annCards . '</div>';
    }

    $taskStmt = $pdo->prepare('SELECT a.*, c.code FROM assignments a JOIN courses c ON c.id = a.course_id ORDER BY CASE WHEN a.due >= ? THEN 0 ELSE 1 END, a.due LIMIT 6');
    $taskStmt->execute([date('c')]);
    $tasks = $taskStmt->fetchAll();
    $rows = '';
    foreach ($tasks as $task) {
        $rows .= task_row_for_user($pdo, $task, $user, 0);
    }

    $hello = 'Meja ' . h(explode(' ', $user['name'])[0]) . '.';
    $lede = $openTasks . ' tugas & ' . $openExams . ' ujian belum dikumpulkan · 8 mata kuliah dengan Pertemuan 1–16 (UTS di Pertemuan 6, UAS di Pertemuan 16).';
    layout('Meja', '<p class="kicker">Minggu ke-8 · Mahasiswa</p><h1>' . $hello . '</h1><p class="lede">' . $lede . '</p><div class="grid">' . $cards . '</div>' . $annBlock . '<h2 class="section">Tenggat & Jadwal Ujian</h2><div class="stack">' . $rows . '</div>', $user);
    exit;
}

if ($page === 'kuliah') {
    $courses = $pdo->query('SELECT * FROM courses')->fetchAll();
    $cards = '';
    $studentTotal = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'mahasiswa'")->fetchColumn();

    foreach ($courses as $course) {
        $totalStmt = $pdo->prepare('SELECT COUNT(*) FROM materials WHERE course_id = ?');
        $totalStmt->execute([$course['id']]);
        $total = (int) $totalStmt->fetchColumn();

        if ($isDosen) {
            $subStatStmt = $pdo->prepare('SELECT COUNT(*) AS tot, SUM(CASE WHEN s.score IS NULL THEN 1 ELSE 0 END) AS pend FROM submissions s JOIN assignments a ON a.id = s.assignment_id WHERE a.course_id = ?');
            $subStatStmt->execute([$course['id']]);
            $subStat = $subStatStmt->fetch();
            $subTot = (int) ($subStat['tot'] ?? 0);
            $pend = (int) ($subStat['pend'] ?? 0);

            $taskCntStmt = $pdo->prepare('SELECT COUNT(*) FROM assignments WHERE course_id = ?');
            $taskCntStmt->execute([$course['id']]);
            $taskCnt = (int) $taskCntStmt->fetchColumn();

            $statusChip = $pend > 0
                ? '<span class="meta-chip meta-uts">' . $pend . ' perlu dinilai</span>'
                : ($subTot > 0 ? '<span class="meta-chip">Semua dinilai</span>' : '<span class="meta-chip">Belum ada masuk</span>');

            $cards .= '<a class="card" href="index.php?p=kelas&id=' . h($course['id']) . '">'
                . '<span class="code">' . h($course['code']) . ' · ' . (int) $course['sks'] . ' SKS</span>'
                . '<h2>' . h($course['name']) . '</h2>'
                . '<p class="muted">' . h($course['day_name']) . ' ' . h($course['time_label']) . ' · ' . h($course['room']) . '</p>'
                . '<p class="muted">' . $total . ' pertemuan (UTS P6 & UAS P16) · ' . $taskCnt . ' tugas/ujian</p>'
                . '<p class="course-meta-line"><span class="meta-chip">' . $studentTotal . ' mahasiswa</span>'
                . $statusChip
                . '</p></a>';
        } else {
            $readStmt = $pdo->prepare('SELECT COUNT(*) FROM reads r JOIN materials m ON m.id = r.material_id WHERE r.user_id = ? AND m.course_id = ?');
            $readStmt->execute([$user['id'], $course['id']]);
            $read = (int) $readStmt->fetchColumn();

            $attStmt = $pdo->prepare("SELECT SUM(CASE WHEN status = 'hadir' THEN 1 ELSE 0 END) AS h, COUNT(*) AS tot FROM attendance WHERE user_id = ? AND course_id = ?");
            $attStmt->execute([$user['id'], $course['id']]);
            $att = $attStmt->fetch();
            $attInfo = ((int) ($att['tot'] ?? 0)) > 0 ? 'Hadir ' . (int) $att['h'] . '/' . (int) $att['tot'] : 'Presensi belum tercatat';

            $cards .= '<a class="card" href="index.php?p=kelas&id=' . h($course['id']) . '">'
                . '<span class="code">' . h($course['code']) . ' · ' . (int) $course['sks'] . ' SKS</span>'
                . '<h2>' . h($course['name']) . '</h2>'
                . '<p class="muted">' . h($course['lecturer']) . ' · ' . h($course['room']) . '</p>'
                . '<p class="muted">Pertemuan ' . $read . '/' . $total . ' · ' . $attInfo . '</p>'
                . '<p class="course-meta-line"><span class="meta-chip">Pertemuan 1–16</span><span class="meta-chip meta-uts">UTS P6</span><span class="meta-chip meta-uas">UAS P16</span></p>'
                . '</a>';
        }
    }

    $titlePage = $isDosen ? 'Kelola Kelas' : 'Kuliah';
    $heading = $isDosen ? 'Kelola delapan kelas pengajaran.' : 'Delapan kelas semester ini.';
    $sub = $isDosen ? '<p class="lede">Setiap kelas memiliki 16 pertemuan terstruktur dengan UTS di Pertemuan 6 dan UAS di Pertemuan 16.</p>' : '';
    layout($titlePage, '<h1>' . $heading . '</h1>' . $sub . '<div class="grid">' . $cards . '</div>', $user);
    exit;
}

if ($page === 'kelas') {
    $id = (string) ($_GET['id'] ?? '');
    $stmt = $pdo->prepare('SELECT * FROM courses WHERE id = ?');
    $stmt->execute([$id]);
    $course = $stmt->fetch();
    if (!$course) {
        layout('Kosong', '<h1>Kelas tidak ditemukan.</h1>', $user);
        exit;
    }

    $mats = $pdo->prepare('SELECT * FROM materials WHERE course_id = ? ORDER BY week');
    $mats->execute([$id]);
    $matRows = $mats->fetchAll();

    $taskStmt = $pdo->prepare('SELECT * FROM assignments WHERE course_id = ? ORDER BY week, due');
    $taskStmt->execute([$id]);
    $tasksByWeek = [];
    $extraTasks = [];
    foreach ($taskStmt->fetchAll() as $t) {
        $tw = (int) $t['week'];
        if ($tw >= 1 && $tw <= 16) {
            $tasksByWeek[$tw][] = $t;
        } else {
            $extraTasks[] = $t;
        }
    }

    $studentTotal = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'mahasiswa'")->fetchColumn();
    $mySubs = [];
    $subStats = [];
    if ($isDosen) {
        $sQ = $pdo->prepare("SELECT s.assignment_id, COUNT(*) AS tot, SUM(CASE WHEN s.score IS NULL THEN 1 ELSE 0 END) AS wait FROM submissions s JOIN assignments a ON a.id = s.assignment_id JOIN users u ON u.id = s.user_id WHERE a.course_id = ? AND u.role = 'mahasiswa' GROUP BY s.assignment_id");
        $sQ->execute([$id]);
        foreach ($sQ->fetchAll() as $sr) {
            $subStats[$sr['assignment_id']] = ['tot' => (int) $sr['tot'], 'wait' => (int) $sr['wait']];
        }
    } else {
        $mQ = $pdo->prepare('SELECT s.* FROM submissions s JOIN assignments a ON a.id = s.assignment_id WHERE a.course_id = ? AND s.user_id = ?');
        $mQ->execute([$id, $user['id']]);
        foreach ($mQ->fetchAll() as $sr) {
            $mySubs[$sr['assignment_id']] = $sr;
        }
    }

    $readCountStmt = $pdo->prepare('SELECT COUNT(*) FROM reads r JOIN users u ON u.id = r.user_id WHERE r.material_id = ? AND u.role = "mahasiswa"');
    $attCountStmt = $pdo->prepare('SELECT COUNT(*) FROM attendance WHERE course_id = ? AND week = ?');
    $seenStmt = $pdo->prepare('SELECT 1 FROM reads WHERE user_id = ? AND material_id = ?');
    $attMap = [];
    if (!$isDosen) {
        $attStmt = $pdo->prepare('SELECT week, status FROM attendance WHERE course_id = ? AND user_id = ?');
        $attStmt->execute([$id, $user['id']]);
        foreach ($attStmt->fetchAll() as $aRow) {
            $attMap[(int) $aRow['week']] = $aRow['status'];
        }
    }
    $attLabels = attendance_labels();

    $tableRows = '';
    foreach ($matRows as $mat) {
        $w = (int) $mat['week'];
        $badge = $w === 6 ? ' <span class="badge-exam badge-uts">UTS</span>' : ($w === 16 ? ' <span class="badge-exam badge-uas">UAS</span>' : '');
        $rowCls = $w === 6 ? ' class="row-uts"' : ($w === 16 ? ' class="row-uas"' : '');

        if ($isDosen) {
            $readCountStmt->execute([$mat['id']]);
            $rc = (int) $readCountStmt->fetchColumn();
            $attCountStmt->execute([$id, $w]);
            $ac = (int) $attCountStmt->fetchColumn();
            $matMeta = (int) $mat['minutes'] . ' menit · ' . $rc . '/' . $studentTotal . ' mhs baca' . ($ac > 0 ? ' · Presensi ✓' : '');
        } else {
            $seenStmt->execute([$user['id'], $mat['id']]);
            $seen = (bool) $seenStmt->fetch();
            $attTxt = isset($attMap[$w]) ? ' · ' . ($attLabels[$attMap[$w]] ?? $attMap[$w]) : '';
            $matMeta = (int) $mat['minutes'] . ' menit · ' . ($seen ? 'Sudah dibaca' : 'Belum dibaca') . $attTxt;
        }

        $weekTasks = $tasksByWeek[$w] ?? [];
        $taskColHtml = '';
        $statusColHtml = '';

        foreach ($weekTasks as $wt) {
            $taskUrl = $isDosen
                ? 'index.php?p=tugas_detail&id=' . h($wt['id'])
                : 'index.php?p=materi&id=' . h($mat['id']) . '#kotak-pengumpulan';
            $taskColHtml .= '<div class="meet-task-item">'
                . '<a href="' . $taskUrl . '"><strong>' . h($wt['title']) . '</strong></a>'
                . '<small>Bobot ' . (int) $wt['weight'] . '% · Tenggat ' . h(date('j M Y', strtotime($wt['due']))) . '</small>'
                . '</div>';

            if ($isDosen) {
                $st = $subStats[$wt['id']] ?? ['tot' => 0, 'wait' => 0];
                if ($st['wait'] > 0) {
                    $em = '<em class="wait">' . $st['tot'] . '/' . $studentTotal . ' masuk · ' . $st['wait'] . ' perlu nilai</em>';
                } elseif ($st['tot'] > 0) {
                    $em = '<em class="ok">' . $st['tot'] . '/' . $studentTotal . ' dinilai</em>';
                } else {
                    $em = '<em class="calm">0/' . $studentTotal . ' masuk</em>';
                }
                $statusColHtml .= '<div class="meet-action-item">' . $em . '<a class="btn-link-sm" href="index.php?p=tugas_detail&id=' . h($wt['id']) . '">Nilai</a></div>';
            } else {
                $sub = $mySubs[$wt['id']] ?? null;
                if ($sub && $sub['score'] !== null) {
                    $em = '<em class="ok">Dinilai ' . (int) $sub['score'] . '</em>';
                    $btnTxt = 'Lihat Nilai';
                } elseif ($sub) {
                    $em = '<em class="wait">Menunggu nilai</em>';
                    $btnTxt = 'Perbarui';
                } else {
                    [$tone, $dueTxt] = due_label($wt['due']);
                    $em = '<em class="' . h($tone) . '">' . ($tone === 'late' ? 'Belum mengumpulkan · terlambat' : 'Belum mengumpulkan') . '</em>';
                    $btnTxt = 'Kumpulkan';
                }
                $statusColHtml .= '<div class="meet-action-item">' . $em . '<a class="btn-link-sm" href="' . $taskUrl . '">' . $btnTxt . '</a></div>';
            }
        }

        if ($taskColHtml === '') {
            $taskColHtml = '<span class="muted">Tidak ada tugas</span>';
            $statusColHtml = '<span class="muted">—</span>';
        }

        $tableRows .= '<tr' . $rowCls . '>'
            . '<td class="meet-col-no" data-label="Pertemuan"><strong>Pertemuan ' . $w . '</strong>' . $badge . '</td>'
            . '<td class="meet-col-mat" data-label="Materi & Bacaan"><a href="index.php?p=materi&id=' . h($mat['id']) . '"><strong>' . h($mat['title']) . '</strong></a><br><small class="muted">' . h($matMeta) . '</small></td>'
            . '<td class="meet-col-task" data-label="Kolom Pengumpulan">' . $taskColHtml . '</td>'
            . '<td class="meet-col-act" data-label="Status & Aksi">' . $statusColHtml . '</td>'
            . '</tr>';
    }

    $dosenActions = $isDosen
        ? '<div class="action-strip">'
            . '<a class="action-btn" href="index.php?p=dosen_materi&course=' . h($course['id']) . '">✦ Edit Pertemuan 1–16 & Buat Tugas</a>'
            . '<a class="action-btn" href="index.php?p=dosen_presensi&course=' . h($course['id']) . '">✓ Isi Presensi Kelas Ini</a>'
            . '</div>'
        : '';

    $extraBlock = '';
    if ($extraTasks) {
        $erows = '';
        foreach ($extraTasks as $et) {
            $erows .= task_row_for_user($pdo, $et, $user, $studentTotal);
        }
        $extraBlock = '<section class="panel section-gap"><div class="panel-head"><h2>Tugas Tambahan</h2><span class="panel-hint">' . count($extraTasks) . ' item</span></div><div class="stack">' . $erows . '</div></section>';
    }

    $html = '<p class="code on-batik">' . h($course['code']) . '</p><h1>' . h($course['name']) . '</h1><p class="lede">' . h($course['summary']) . '</p>';
    $html .= '<p class="muted on-batik">' . h($course['lecturer']) . ' · ' . h($course['day_name']) . ' ' . h($course['time_label']) . ' · ' . h($course['room']) . '</p>';
    $html .= $dosenActions;
    $html .= '<section class="panel section-gap"><div class="panel-head"><h2>Pertemuan 1–16 & Kolom Pengumpulan Tugas</h2><span class="panel-hint">UTS P6 · UAS P16</span></div>';
    $html .= '<div class="table-wrap"><table class="data-table meeting-table"><thead><tr><th>Pertemuan</th><th>Materi & Bacaan</th><th>Kolom Pengumpulan Tugas</th><th>Status & Aksi</th></tr></thead><tbody>' . $tableRows . '</tbody></table></div>';
    $html .= '<p class="panel-foot">Setiap pertemuan memiliki kolom pengumpulan tugasnya masing-masing (termasuk UTS pada Pertemuan 6 dan UAS pada Pertemuan 16). Klik tombol <strong>Kumpulkan</strong> atau buka materi pertemuan untuk mengisi kotak pengumpulan.</p></section>';
    $html .= $extraBlock;
    layout($course['code'], $html, $user);
    exit;
}

if ($page === 'materi') {
    $id = (string) ($_GET['id'] ?? '');
    $stmt = $pdo->prepare('SELECT m.*, c.code, c.name AS course_name, c.id AS cid, c.room, c.day_name, c.time_label FROM materials m JOIN courses c ON c.id = m.course_id WHERE m.id = ?');
    $stmt->execute([$id]);
    $mat = $stmt->fetch();
    if (!$mat) {
        layout('Kosong', '<h1>Pertemuan tidak ditemukan.</h1>', $user);
        exit;
    }

    if (!$isDosen) {
        $pdo->prepare('INSERT OR IGNORE INTO reads (user_id, material_id) VALUES (?, ?)')->execute([$user['id'], $id]);
    }

    $w = (int) $mat['week'];
    $paras = '';
    foreach (preg_split("/\n\s*\n/", $mat['body']) as $para) {
        $paras .= '<p>' . nl2br(h(trim($para))) . '</p>';
    }

    $badge = $w === 6 ? ' <span class="badge-exam badge-uts">UTS</span>' : ($w === 16 ? ' <span class="badge-exam badge-uas">UAS</span>' : '');
    $html = '<p class="muted on-batik"><a class="crumb" href="index.php?p=kelas&id=' . h($mat['cid']) . '">' . h($mat['code']) . ' ' . h($mat['course_name']) . '</a> · Pertemuan ' . $w . ' dari 16' . $badge . '</p>';
    $html .= '<article class="article-panel"><h1>' . h($mat['title']) . '</h1>' . $paras . '</article>';

    if (!empty($_GET['ok'])) {
        $html .= '<p class="okline section-gap">Pengumpulan atau penilaian berhasil disimpan.</p>';
    }

    // Kolom / Kotak Pengumpulan Tugas pada pertemuan ini (berlaku di Pertemuan 1..16)
    $tStmt = $pdo->prepare('SELECT a.*, c.code, c.name AS course_name, c.id AS cid, c.room, c.day_name, c.time_label FROM assignments a JOIN courses c ON c.id = a.course_id WHERE a.course_id = ? AND a.week = ? ORDER BY a.due');
    $tStmt->execute([$mat['cid'], $w]);
    $weekTasks = $tStmt->fetchAll();

    foreach ($weekTasks as $idx => $wt) {
        $anchor = $idx === 0 ? ' id="kotak-pengumpulan"' : '';
        $html .= '<div class="section-gap"' . $anchor . '>' . render_task_submission_section($pdo, $wt, $user, $mat['id']) . '</div>';
    }

    if ($isDosen) {
        $readersStmt = $pdo->prepare("SELECT u.name, u.nim FROM reads r JOIN users u ON u.id = r.user_id WHERE r.material_id = ? AND u.role = 'mahasiswa' ORDER BY u.nim");
        $readersStmt->execute([$mat['id']]);
        $readers = $readersStmt->fetchAll();
        $rText = $readers
            ? implode(', ', array_map(fn($r) => h($r['name']), $readers))
            : 'Belum ada mahasiswa yang membuka pertemuan ini.';

        $html .= '<section class="panel section-gap"><div class="panel-head"><h2>Panel Khusus Dosen</h2><span class="panel-hint">Pertemuan ' . $w . '</span></div>'
            . '<p><strong>Sudah membaca (' . count($readers) . '):</strong> ' . $rText . '</p>'
            . '<div class="action-strip">'
            . '<a class="action-btn" href="index.php?p=dosen_materi&course=' . h($mat['cid']) . '&week=' . $w . '">✎ Edit Materi Pertemuan ' . $w . '</a>'
            . '<a class="action-btn" href="index.php?p=dosen_presensi&course=' . h($mat['cid']) . '&week=' . $w . '">✓ Isi Presensi Pertemuan ' . $w . '</a>'
            . '</div></section>';
    }

    // Navigasi Pertemuan Sebelumnya / Berikutnya (1..16)
    $prevStmt = $pdo->prepare('SELECT id, week FROM materials WHERE course_id = ? AND week = ?');
    $prevStmt->execute([$mat['cid'], $w - 1]);
    $prevMat = $prevStmt->fetch();
    $nextStmt = $pdo->prepare('SELECT id, week FROM materials WHERE course_id = ? AND week = ?');
    $nextStmt->execute([$mat['cid'], $w + 1]);
    $nextMat = $nextStmt->fetch();

    $pager = '<div class="pager">';
    $pager .= $prevMat ? '<a class="pager-btn" href="index.php?p=materi&id=' . h($prevMat['id']) . '">‹ Pertemuan ' . (int) $prevMat['week'] . '</a>' : '<span></span>';
    $pager .= '<a class="pager-btn" href="index.php?p=kelas&id=' . h($mat['cid']) . '">Daftar 1–16</a>';
    $pager .= $nextMat ? '<a class="pager-btn" href="index.php?p=materi&id=' . h($nextMat['id']) . '">Pertemuan ' . (int) $nextMat['week'] . ' ›</a>' : '<span></span>';
    $pager .= '</div>';

    layout('Pertemuan ' . $w . ' · ' . $mat['title'], $html . $pager, $user);
    exit;
}

if ($page === 'tugas') {
    $filter = (string) ($_GET['filter'] ?? 'semua');
    $courses = $pdo->query('SELECT * FROM courses')->fetchAll();
    $allTasks = $pdo->query('SELECT a.*, c.code, c.name AS course_name FROM assignments a JOIN courses c ON c.id = a.course_id ORDER BY a.week, a.due')->fetchAll();
    $studentTotal = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'mahasiswa'")->fetchColumn();

    $mySubs = [];
    $subStatsByTask = [];
    if ($isDosen) {
        foreach ($pdo->query("SELECT s.assignment_id, COUNT(*) AS tot, SUM(CASE WHEN s.score IS NULL THEN 1 ELSE 0 END) AS wait FROM submissions s JOIN users u ON u.id = s.user_id WHERE u.role = 'mahasiswa' GROUP BY s.assignment_id") as $r) {
            $subStatsByTask[$r['assignment_id']] = ['tot' => (int) $r['tot'], 'wait' => (int) $r['wait']];
        }
    } else {
        $mSubStmt = $pdo->prepare('SELECT * FROM submissions WHERE user_id = ?');
        $mSubStmt->execute([$user['id']]);
        foreach ($mSubStmt->fetchAll() as $s) {
            $mySubs[$s['assignment_id']] = $s;
        }
    }

    $counts = ['semua' => 0, 'belum' => 0, 'sudah' => 0, 'perlu_nilai' => 0, 'tugas' => 0, 'ujian' => 0];
    foreach ($allTasks as $t) {
        $isExam = ($t['kind'] === 'uts' || $t['kind'] === 'uas');
        $counts['semua']++;
        if ($isExam) {
            $counts['ujian']++;
        } else {
            $counts['tugas']++;
        }
        if ($isDosen) {
            $st = $subStatsByTask[$t['id']] ?? ['tot' => 0, 'wait' => 0];
            if ($st['tot'] === 0) {
                $counts['belum']++;
            }
            if ($st['wait'] > 0) {
                $counts['perlu_nilai']++;
            }
        } else {
            if (isset($mySubs[$t['id']])) {
                $counts['sudah']++;
            } else {
                $counts['belum']++;
            }
        }
    }

    $grouped = [];
    foreach ($allTasks as $task) {
        $isExam = ($task['kind'] === 'uts' || $task['kind'] === 'uas');
        $match = true;
        if ($filter === 'tugas') {
            $match = !$isExam;
        } elseif ($filter === 'ujian') {
            $match = $isExam;
        } elseif ($filter === 'belum') {
            $match = $isDosen
                ? ($subStatsByTask[$task['id']]['tot'] ?? 0) === 0
                : !isset($mySubs[$task['id']]);
        } elseif ($filter === 'sudah' && !$isDosen) {
            $match = isset($mySubs[$task['id']]);
        } elseif ($filter === 'perlu_nilai' && $isDosen) {
            $match = ($subStatsByTask[$task['id']]['wait'] ?? 0) > 0;
        }
        if ($match) {
            $grouped[$task['course_id']][] = $task;
        }
    }

    if ($isDosen) {
        $tabs = '<div class="pill-bar">'
            . '<a class="pill' . ($filter === 'semua' ? ' is-on' : '') . '" href="index.php?p=tugas&filter=semua">Semua (' . $counts['semua'] . ')</a>'
            . '<a class="pill' . ($filter === 'belum' ? ' is-on' : '') . '" href="index.php?p=tugas&filter=belum">Belum Ada Masuk (' . $counts['belum'] . ')</a>'
            . '<a class="pill' . ($filter === 'perlu_nilai' ? ' is-on' : '') . '" href="index.php?p=tugas&filter=perlu_nilai">Perlu Dinilai (' . $counts['perlu_nilai'] . ')</a>'
            . '<a class="pill' . ($filter === 'tugas' ? ' is-on' : '') . '" href="index.php?p=tugas&filter=tugas">Tugas Mingguan (' . $counts['tugas'] . ')</a>'
            . '<a class="pill' . ($filter === 'ujian' ? ' is-on' : '') . '" href="index.php?p=tugas&filter=ujian">UTS (P6) & UAS (P16) (' . $counts['ujian'] . ')</a>'
            . '</div>';
    } else {
        $tabs = '<div class="pill-bar">'
            . '<a class="pill' . ($filter === 'semua' ? ' is-on' : '') . '" href="index.php?p=tugas&filter=semua">Semua (' . $counts['semua'] . ')</a>'
            . '<a class="pill' . ($filter === 'belum' ? ' is-on' : '') . '" href="index.php?p=tugas&filter=belum">Belum Dikumpulkan (' . $counts['belum'] . ')</a>'
            . '<a class="pill' . ($filter === 'sudah' ? ' is-on' : '') . '" href="index.php?p=tugas&filter=sudah">Sudah Dikumpulkan (' . $counts['sudah'] . ')</a>'
            . '<a class="pill' . ($filter === 'tugas' ? ' is-on' : '') . '" href="index.php?p=tugas&filter=tugas">Tugas Mingguan (' . $counts['tugas'] . ')</a>'
            . '<a class="pill' . ($filter === 'ujian' ? ' is-on' : '') . '" href="index.php?p=tugas&filter=ujian">UTS (P6) & UAS (P16) (' . $counts['ujian'] . ')</a>'
            . '</div>';
    }

    $panels = '';
    foreach ($courses as $c) {
        $cid = $c['id'];
        if (empty($grouped[$cid])) {
            continue;
        }
        $rows = '';
        $belumDiMk = 0;
        foreach ($grouped[$cid] as $task) {
            $rows .= task_row_for_user($pdo, $task, $user, $studentTotal);
            if ($isDosen && ($subStatsByTask[$task['id']]['tot'] ?? 0) === 0) {
                $belumDiMk++;
            } elseif (!$isDosen && !isset($mySubs[$task['id']])) {
                $belumDiMk++;
            }
        }
        $hint = $isDosen
            ? ($belumDiMk > 0 ? $belumDiMk . ' belum ada pengumpulan' : count($grouped[$cid]) . ' komponen')
            : ($belumDiMk > 0 ? $belumDiMk . ' belum dikumpulkan' : count($grouped[$cid]) . ' item');

        $panels .= '<section class="panel section-gap">'
            . '<div class="panel-head">'
            . '<h2>' . h($c['code']) . ' · ' . h($c['name']) . '</h2>'
            . '<div class="row-actions"><span class="panel-hint">' . h($hint) . '</span><a class="btn-link-sm" href="index.php?p=kelas&id=' . h($cid) . '">Buka Kelas</a></div>'
            . '</div>'
            . '<div class="stack">' . $rows . '</div>'
            . '</section>';
    }

    if ($panels === '') {
        $emptyText = $filter === 'sudah'
            ? 'Belum ada tugas yang dikumpulkan. Pilih tab Belum Dikumpulkan untuk melihat daftar tugas yang perlu dikerjakan.'
            : 'Tidak ada tugas atau ujian yang cocok dengan filter ini.';
        $panels = '<section class="panel section-gap"><p class="empty-note">' . h($emptyText) . '</p></section>';
    }

    $msg = (!empty($_GET['ok']) && $_GET['ok'] === 'reset')
        ? '<p class="okline">Seluruh pengumpulan tugas pada semua mata kuliah telah direset ke status belum mengumpulkan.</p>'
        : '';

    if ($isDosen) {
        $head = '<h1>Penilaian Tugas & Ujian.</h1><p class="lede">Daftar tugas Pertemuan 1–16 dikelompokkan per mata kuliah, termasuk UTS (Pertemuan 6) dan UAS (Pertemuan 16).</p>'
            . '<div class="action-strip">'
            . '<a class="action-btn" href="index.php?p=dosen_materi#buat-tugas">+ Buat Tugas Baru</a>'
            . '<form method="post" action="index.php?p=dosen_reset_pengumpulan" class="inline-form" data-confirm="Yakin ingin mereset seluruh pengumpulan tugas & nilai semua mahasiswa ke belum mengumpulkan?">'
            . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
            . '<button type="submit" class="btn-danger-reset">↺ Reset Semua Pengumpulan</button>'
            . '</form>'
            . '</div>';
    } else {
        $head = '<h1>Tugas, UTS & UAS.</h1><p class="lede">Semua tugas Pertemuan 1–16 dikelompokkan sesuai mata kuliah. Gunakan filter untuk melihat tugas yang belum dikumpulkan atau jadwal UTS & UAS.</p>';
    }

    layout($isDosen ? 'Penilaian' : 'Tugas', $head . $msg . $tabs . $panels, $user);
    exit;
}

if ($page === 'tugas_detail') {
    $id = (string) ($_GET['id'] ?? '');
    $stmt = $pdo->prepare('SELECT a.*, c.code, c.name AS course_name, c.id AS cid, c.room, c.day_name, c.time_label FROM assignments a JOIN courses c ON c.id = a.course_id WHERE a.id = ?');
    $stmt->execute([$id]);
    $task = $stmt->fetch();
    if (!$task) {
        layout('Kosong', '<h1>Tugas tidak ditemukan.</h1>', $user);
        exit;
    }

    $kindBadge = $task['kind'] === 'uts'
        ? '<span class="badge-exam badge-uts">UTS · Pertemuan 6</span>'
        : ($task['kind'] === 'uas'
            ? '<span class="badge-exam badge-uas">UAS · Pertemuan 16</span>'
            : '<span class="badge-soft">Pertemuan ' . (int) $task['week'] . ' · Bobot ' . (int) $task['weight'] . '%</span>');

    $html = '<p class="muted on-batik"><a class="crumb" href="index.php?p=kelas&id=' . h($task['cid']) . '">' . h($task['code']) . ' ' . h($task['course_name']) . '</a> · ' . $kindBadge . '</p>';
    $html .= '<h1>' . h($task['title']) . '</h1>';
    if (!empty($_GET['ok'])) {
        $html .= '<p class="okline">Tersimpan.</p>';
    } elseif (!empty($_GET['err']) && $_GET['err'] === 'nilai') {
        $html .= '<p class="err">Nilai harus berupa angka 0 sampai ' . (int) $task['max_score'] . '.</p>';
    }
    $html .= render_task_submission_section($pdo, $task, $user);
    layout($task['title'], $html, $user);
    exit;
}

if ($page === 'nilai') {
    $students = $pdo->query("SELECT * FROM users WHERE role = 'mahasiswa' ORDER BY nim")->fetchAll();
    $selectedId = (int) $user['id'];
    $selectedStudent = $user;

    if ($isDosen) {
        $reqId = (int) ($_GET['student'] ?? ($students[0]['id'] ?? 0));
        foreach ($students as $stu) {
            if ((int) $stu['id'] === $reqId) {
                $selectedId = $reqId;
                $selectedStudent = $stu;
                break;
            }
        }
    }

    $stats = student_course_stats($pdo, $selectedId);
    $blocks = '';
    foreach ($stats['courses'] as $cEntry) {
        $course = $cEntry['course'];
        $items = '';
        foreach ($cEntry['tasks'] as $tr) {
            $task = $tr['task'];
            $got = $tr['sub'];
            $label = 'Belum';
            if ($got && $got['score'] !== null) {
                $label = (string) (int) $got['score'];
            } elseif ($got) {
                $label = 'Menunggu';
            } elseif ($task['kind'] === 'uts' || $task['kind'] === 'uas') {
                $label = 'Belum (' . strtoupper($task['kind']) . ' P' . (int) $task['week'] . ')';
            }
            $items .= '<li><a href="index.php?p=tugas_detail&id=' . h($task['id']) . '">' . h($task['title']) . '</a><span>' . h($label) . '</span></li>';
        }
        $head = $cEntry['score'] !== null && $cEntry['grade']
            ? $cEntry['score'] . ' · ' . $cEntry['grade'][0]
            : 'Belum ada nilai';
        $blocks .= '<section class="card"><div class="between"><h2>' . h($course['name']) . '</h2><span>' . h($head) . '</span></div><ul class="plain">' . $items . '</ul></section>';
    }

    if ($isDosen) {
        $summaryRows = '';
        $pills = '';
        foreach ($students as $stu) {
            $sid = (int) $stu['id'];
            $sStat = student_course_stats($pdo, $sid);
            $cls = $sid === $selectedId ? 'pill is-on' : 'pill';
            $pills .= '<a class="' . $cls . '" href="index.php?p=nilai&student=' . $sid . '">' . h($stu['name']) . ' (' . h($stu['nim']) . ')</a>';
            $summaryRows .= '<tr class="' . ($sid === $selectedId ? 'is-highlight' : '') . '">'
                . '<td data-label="NIM"><code>' . h($stu['nim']) . '</code></td>'
                . '<td data-label="Nama"><strong>' . h($stu['name']) . '</strong></td>'
                . '<td data-label="Dinilai">' . (int) $sStat['graded_count'] . ' komponen</td>'
                . '<td data-label="SKS">' . (int) $sStat['sks'] . ' SKS</td>'
                . '<td data-label="IPS"><strong>' . h($sStat['ips']) . '</strong></td>'
                . '<td data-label="Aksi"><a class="btn-link-sm" href="index.php?p=nilai&student=' . $sid . '">Rincian</a></td>'
                . '</tr>';
        }

        $html = '<p class="kicker">Khusus Dosen · Rekap Nilai</p>'
            . '<h1>Rekap Nilai Mahasiswa.</h1>'
            . '<p class="lede">Pantau IP sementara seluruh mahasiswa dan klik komponen tugas, UTS, atau UAS untuk memperbarui nilai.</p>'
            . '<section class="panel"><div class="panel-head"><h2>Ringkasan Semua Mahasiswa</h2><span class="panel-hint">' . count($students) . ' mahasiswa</span></div>'
            . '<div class="table-wrap"><table class="data-table"><thead><tr><th>NIM</th><th>Nama</th><th>Komponen Dinilai</th><th>SKS Terhitung</th><th>IPS</th><th>Aksi</th></tr></thead><tbody>' . $summaryRows . '</tbody></table></div></section>'
            . '<h2 class="section">Rincian: ' . h($selectedStudent['name']) . ' (' . h($selectedStudent['nim']) . ')</h2>'
            . '<div class="pill-bar">' . $pills . '</div>'
            . '<div class="card ips"><p class="muted">Indeks Prestasi Sementara (' . h($selectedStudent['name']) . ')</p><p class="score">' . h($stats['ips']) . '</p></div>'
            . '<div class="grid grid-2">' . $blocks . '</div>';
        layout('Rekap Nilai', $html, $user);
        exit;
    }

    layout('Nilai', '<h1>Nilai sementara.</h1><p class="lede">Mencakup tugas mingguan, UTS (Pertemuan 6), dan UAS (Pertemuan 16). Bukan transkrip resmi.</p><div class="card ips"><p class="muted">Indeks Prestasi Sementara</p><p class="score">' . h($stats['ips']) . '</p></div><div class="grid grid-2">' . $blocks . '</div>', $user);
    exit;
}

redirect('index.php?p=beranda');

function safe_return_url(PDO $pdo, string $taskId, string $returnMat, string $query): string
{
    $qs = $query !== '' ? '&' . $query : '';
    if ($returnMat !== '') {
        $chk = $pdo->prepare('SELECT id FROM materials WHERE id = ?');
        $chk->execute([$returnMat]);
        if ($chk->fetchColumn()) {
            return 'index.php?p=materi&id=' . urlencode($returnMat) . $qs . '#kotak-pengumpulan';
        }
    }
    return 'index.php?p=tugas_detail&id=' . urlencode($taskId) . $qs;
}

/**
 * Merender kolom/kotak pengumpulan tugas (untuk mahasiswa) dan panel penilaian (untuk dosen).
 * Digunakan bersama oleh halaman Pertemuan (?p=materi) dan halaman Detail Tugas (?p=tugas_detail).
 */
function render_task_submission_section(PDO $pdo, array $task, array $user, string $returnMatId = ''): string
{
    $isDosen = is_dosen($user);
    $subStmt = $pdo->prepare('SELECT s.*, u.name, u.nim FROM submissions s JOIN users u ON u.id = s.user_id WHERE s.assignment_id = ? ORDER BY u.nim');
    $subStmt->execute([$task['id']]);
    $subs = $subStmt->fetchAll();
    $subsByStudent = [];
    $mine = null;
    foreach ($subs as $s) {
        $subsByStudent[(int) $s['user_id']] = $s;
        if ((int) $s['user_id'] === (int) $user['id']) {
            $mine = $s;
        }
    }

    $rubric = '';
    foreach (explode('|', $task['rubric']) as $item) {
        $rubric .= '<li>' . h($item) . '</li>';
    }

    $isExam = ($task['kind'] === 'uts' || $task['kind'] === 'uas');
    $hint = $isExam ? strtoupper($task['kind']) . ' · Pertemuan ' . (int) $task['week'] : 'Pertemuan ' . (int) $task['week'];
    $returnHidden = $returnMatId !== '' ? '<input type="hidden" name="return_mat" value="' . h($returnMatId) . '">' : '';

    $out = '<section class="panel panel-accent submit-panel">';
    $out .= '<div class="panel-head"><h2>Kolom Pengumpulan · ' . h($task['title']) . '</h2><span class="panel-hint">' . h($hint) . '</span></div>';
    $out .= '<p>' . h($task['brief']) . '</p>';
    if ($isExam && !empty($task['room'])) {
        $out .= '<p class="muted">Ruang: ' . h($task['room']) . ' · Jadwal: ' . h($task['day_name'] ?? '') . ' ' . h($task['time_label'] ?? '') . '</p>';
    }
    $out .= '<div class="card rubric-card"><strong>Kriteria & Bobot (' . (int) $task['weight'] . '% · Maks ' . (int) $task['max_score'] . ' · Tenggat ' . h(date('j M Y H:i', strtotime($task['due']))) . ')</strong><ul class="rubric">' . $rubric . '</ul></div>';

    if (!$isDosen) {
        $locked = $mine && $mine['score'] !== null;
        if (!empty($_GET['err'])) {
            $errMsg = $_GET['err'] === 'berkas'
                ? 'Berkas tidak diterima (tipe/ukuran). Maks. 8 MB: foto atau dokumen.'
                : 'Isi tulisan minimal singkat atau unggah berkas.';
            $out .= '<p class="err">' . h($errMsg) . '</p>';
        }
        if ($mine) {
            $out .= '<div class="card saved-box"><p class="muted">Tersimpan ' . h(date('j M Y H:i', strtotime($mine['submitted_at']))) . '</p>';
            $out .= '<div class="saved-body">' . nl2br(h($mine['body'])) . '</div>';
            if (!empty($mine['attachment'])) {
                $out .= '<p class="attach-line"><a class="attach-link" href="index.php?p=unduh&id=' . (int) $mine['id'] . '">📎 ' . h($mine['attachment_name'] !== '' ? $mine['attachment_name'] : $mine['attachment']) . '</a></p>';
            }
            $out .= '</div>';
        }
        $out .= '<form method="post" action="index.php?p=kumpul" class="stack-form" enctype="multipart/form-data">';
        $out .= '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="id" value="' . h($task['id']) . '">' . $returnHidden;
        $out .= '<label>Jawaban tertulis<textarea name="body" rows="6" ' . ($locked ? 'disabled' : '') . ' placeholder="Tulis jawabanmu untuk Pertemuan ' . (int) $task['week'] . ' di sini atau lampirkan berkas di bawah.">' . h($mine['body'] ?? '') . '</textarea></label>';
        if (!$locked) {
            $out .= '<label>Sisipkan foto atau dokumen<input type="file" name="berkas" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.zip,.txt,image/*"></label>';
            $out .= '<p class="field-hint">Opsional. Maks. 8 MB. Jika tidak memilih berkas baru, lampiran lama tetap dipakai.</p>';
            $out .= '<button type="submit">' . ($mine ? 'Perbarui pengumpulan' : 'Kumpulkan Tugas') . '</button>';
        } else {
            $out .= '<div class="card"><p class="muted">Nilai Resmi dari Dosen</p><p class="score">' . (int) $mine['score'] . '</p><p>' . h($mine['feedback'] ?: 'Tidak ada catatan tambahan.') . '</p></div>';
        }
        $out .= '</form></section>';
        return $out;
    }

    $students = $pdo->query("SELECT id, name, nim FROM users WHERE role = 'mahasiswa' ORDER BY nim")->fetchAll();
    if ($isExam) {
        $out .= '<p class="muted">Masukkan nilai ujian (0–' . (int) $task['max_score'] . ') untuk setiap mahasiswa (baik yang mengunggah jawaban daring maupun mengikuti ujian di kelas).</p>';
        $out .= '<form method="post" action="index.php?p=nilai_ujian_massal">';
        $out .= '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="id" value="' . h($task['id']) . '">' . $returnHidden;
        $tRows = '';
        foreach ($students as $stu) {
            $sid = (int) $stu['id'];
            $s = $subsByStudent[$sid] ?? null;
            $sc = $s && $s['score'] !== null ? (string) (int) $s['score'] : '';
            $fb = $s['feedback'] ?? '';
            $ansInfo = '';
            if ($s && $s['body'] !== '' && strpos($s['body'], '(Nilai U') !== 0) {
                $ansInfo .= '<div class="muted">' . h(mb_strimwidth($s['body'], 0, 120, '…')) . '</div>';
            }
            if ($s && !empty($s['attachment'])) {
                $ansInfo .= '<div><a class="attach-link" href="index.php?p=unduh&id=' . (int) $s['id'] . '">📎 ' . h($s['attachment_name'] !== '' ? $s['attachment_name'] : $s['attachment']) . '</a></div>';
            }
            $tRows .= '<tr>'
                . '<td data-label="NIM"><code>' . h($stu['nim']) . '</code></td>'
                . '<td data-label="Mahasiswa & Jawaban"><strong>' . h($stu['name']) . '</strong>' . $ansInfo . '</td>'
                . '<td data-label="Nilai"><input name="score[' . $sid . ']" type="number" min="0" max="' . (int) $task['max_score'] . '" value="' . h($sc) . '" placeholder="0–' . (int) $task['max_score'] . '"></td>'
                . '<td data-label="Catatan"><input name="feedback[' . $sid . ']" value="' . h($fb) . '" placeholder="Catatan dosen (opsional)"></td>'
                . '</tr>';
        }
        $out .= '<div class="table-wrap"><table class="data-table"><thead><tr><th>NIM</th><th>Mahasiswa & Jawaban</th><th>Nilai</th><th>Catatan</th></tr></thead><tbody>' . $tRows . '</tbody></table></div>';
        $out .= '<div class="form-actions"><button type="submit">Simpan Semua Nilai ' . strtoupper(h($task['kind'])) . '</button></div>';
        $out .= '</form></section>';
        return $out;
    }

    foreach ($students as $stu) {
        $sid = (int) $stu['id'];
        $s = $subsByStudent[$sid] ?? null;
        $out .= '<form method="post" action="index.php?p=nilai_simpan" class="card stack-form">';
        $out .= '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
        $out .= '<input type="hidden" name="id" value="' . h($task['id']) . '"><input type="hidden" name="user_id" value="' . $sid . '">' . $returnHidden;
        $out .= '<div class="between"><h2>' . h($stu['name']) . ' <small class="muted">(' . h($stu['nim']) . ')</small></h2>'
            . ($s ? ($s['score'] !== null ? '<em class="ok">Dinilai ' . (int) $s['score'] . '</em>' : '<em class="wait">Menunggu nilai</em>') : '<em class="late">Belum mengumpulkan</em>')
            . '</div>';
        if ($s) {
            $out .= '<p class="muted">Dikirim ' . h(date('j M Y H:i', strtotime($s['submitted_at']))) . '</p>';
            $out .= '<p>' . nl2br(h($s['body'])) . '</p>';
            if (!empty($s['attachment'])) {
                $out .= '<p class="attach-line"><a class="attach-link" href="index.php?p=unduh&id=' . (int) $s['id'] . '">📎 ' . h($s['attachment_name'] !== '' ? $s['attachment_name'] : $s['attachment']) . '</a></p>';
            }
        } else {
            $out .= '<p class="muted">Mahasiswa belum mengirim berkas/teks. Anda tetap dapat memberi nilai manual bila diperlukan.</p>';
        }
        $out .= '<div class="form-row-2">';
        $out .= '<label>Nilai (0–' . (int) $task['max_score'] . ')<input name="score" type="number" min="0" max="' . (int) $task['max_score'] . '" value="' . h($s && $s['score'] !== null ? (string) (int) $s['score'] : '') . '" required></label>';
        $out .= '<label>Catatan Umpan Balik<input name="feedback" value="' . h($s['feedback'] ?? '') . '" placeholder="Tulis masukan untuk mahasiswa"></label>';
        $out .= '</div>';
        $out .= '<button type="submit">Simpan Nilai ' . h(explode(' ', $stu['name'])[0]) . '</button></form>';
    }
    $out .= '</section>';
    return $out;
}

function task_row_for_user(PDO $pdo, array $task, array $user, int $studentTotal): string
{
    $code = isset($task['code']) ? h($task['code']) . ' · ' : '';
    $weekLabel = !empty($task['week']) ? 'Pertemuan ' . (int) $task['week'] . ' · ' : '';
    $isExam = ($task['kind'] === 'uts' || $task['kind'] === 'uas');

    if (is_dosen($user)) {
        $sStmt = $pdo->prepare('SELECT COUNT(*) AS tot, SUM(CASE WHEN s.score IS NULL THEN 1 ELSE 0 END) AS wait, SUM(CASE WHEN s.score IS NOT NULL THEN 1 ELSE 0 END) AS graded FROM submissions s JOIN users u ON u.id = s.user_id WHERE s.assignment_id = ? AND u.role = "mahasiswa"');
        $sStmt->execute([$task['id']]);
        $cnt = $sStmt->fetch();
        $tot = (int) ($cnt['tot'] ?? 0);
        $wait = (int) ($cnt['wait'] ?? 0);
        $graded = (int) ($cnt['graded'] ?? 0);

        if ($wait > 0) {
            $tone = 'wait';
            $label = $tot . '/' . $studentTotal . ' masuk · ' . $wait . ' perlu nilai';
        } elseif ($graded > 0) {
            $tone = 'ok';
            $label = ($isExam ? strtoupper($task['kind']) . ' · ' : '') . $graded . '/' . $studentTotal . ' dinilai';
        } else {
            $tone = $isExam ? ($task['kind'] === 'uts' ? 'soon' : 'calm') : 'calm';
            $label = ($isExam ? strtoupper($task['kind']) . ' · ' : '') . '0/' . $studentTotal . ' masuk';
        }
        return '<a class="row" href="index.php?p=tugas_detail&id=' . h($task['id']) . '"><span><strong>' . h($task['title']) . '</strong><small>' . $code . $weekLabel . 'bobot ' . (int) $task['weight'] . '%</small></span><em class="' . h($tone) . '">' . h($label) . '</em></a>';
    }

    $subStmt = $pdo->prepare('SELECT * FROM submissions WHERE assignment_id = ? AND user_id = ?');
    $subStmt->execute([$task['id'], $user['id']]);
    $sub = $subStmt->fetch() ?: null;

    if ($sub && $sub['score'] !== null) {
        $tone = 'ok';
        $label = ($isExam ? strtoupper($task['kind']) . ' · ' : '') . 'Dinilai ' . (int) $sub['score'];
    } elseif ($sub) {
        $tone = 'wait';
        $label = 'Menunggu nilai';
    } else {
        [$tone, $dueTxt] = due_label($task['due']);
        if ($isExam) {
            $label = 'Belum mengumpulkan · ' . strtoupper($task['kind']) . ' P' . (int) $task['week'];
            $tone = $task['kind'] === 'uts' ? 'soon' : 'calm';
        } else {
            $label = $tone === 'late' ? 'Belum mengumpulkan · terlambat' : 'Belum mengumpulkan · ' . $dueTxt;
        }
    }

    return '<a class="row" href="index.php?p=tugas_detail&id=' . h($task['id']) . '"><span><strong>' . h($task['title']) . '</strong><small>' . $code . $weekLabel . 'bobot ' . (int) $task['weight'] . '%</small></span><em class="' . h($tone) . '">' . h($label) . '</em></a>';
}
