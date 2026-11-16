<?php
declare(strict_types=1);

if (!defined('SERAMBI_APP')) {
    http_response_code(403);
    exit;
}

/**
 * Menangani aksi POST khusus dosen. Mengembalikan true jika ditangani.
 */
function handle_dosen_post(string $page, PDO $pdo): bool
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return false;
    }

    if ($page === 'dosen_materi_simpan') {
        $user = require_dosen();
        check_csrf();
        $courseId = (string) ($_POST['course_id'] ?? '');
        $week = (int) ($_POST['week'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $minutes = max(1, min(300, (int) ($_POST['minutes'] ?? 15)));
        $body = trim((string) ($_POST['body'] ?? ''));

        if ($courseId === '' || $week < 1 || $week > 16 || $title === '' || $body === '') {
            redirect('index.php?p=dosen_materi&course=' . urlencode($courseId) . '&week=' . $week . '&err=kosong');
        }

        $kind = $week === 6 ? 'uts' : ($week === 16 ? 'uas' : 'materi');
        $chk = $pdo->prepare('SELECT id FROM materials WHERE course_id = ? AND week = ?');
        $chk->execute([$courseId, $week]);
        $existingId = $chk->fetchColumn();

        if ($existingId) {
            $upd = $pdo->prepare('UPDATE materials SET title = ?, minutes = ?, body = ?, kind = ? WHERE id = ?');
            $upd->execute([$title, $minutes, $body, $kind, $existingId]);
        } else {
            $ins = $pdo->prepare('INSERT INTO materials (id, course_id, week, kind, title, minutes, body) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $ins->execute([$courseId . '-m' . $week, $courseId, $week, $kind, $title, $minutes, $body]);
        }
        redirect('index.php?p=dosen_materi&course=' . urlencode($courseId) . '&week=' . $week . '&ok=1');
    }

    if ($page === 'dosen_tugas_simpan') {
        $user = require_dosen();
        check_csrf();
        $courseId = (string) ($_POST['course_id'] ?? '');
        $week = max(1, min(16, (int) ($_POST['week'] ?? 8)));
        $title = trim((string) ($_POST['title'] ?? ''));
        $weight = max(1, min(100, (int) ($_POST['weight'] ?? 20)));
        $maxScore = max(1, min(1000, (int) ($_POST['max_score'] ?? 100)));
        $dueRaw = trim((string) ($_POST['due'] ?? ''));
        $brief = trim((string) ($_POST['brief'] ?? ''));
        $rubric = trim((string) ($_POST['rubric'] ?? ''));

        $chkCourse = $pdo->prepare('SELECT 1 FROM courses WHERE id = ?');
        $chkCourse->execute([$courseId]);
        $dueTs = strtotime($dueRaw);

        if (!$chkCourse->fetch() || $title === '' || $brief === '' || $dueTs === false) {
            redirect('index.php?p=dosen_materi&course=' . urlencode($courseId) . '&err=tugas#buat-tugas');
        }

        if ($rubric === '') {
            $rubric = 'Kelengkapan jawaban|Ketepatan analisis|Kerapian penyajian';
        }
        $id = preg_replace('/[^a-z0-9]/', '', $courseId) . '-t-' . bin2hex(random_bytes(3));
        $ins = $pdo->prepare("INSERT INTO assignments (id, course_id, kind, week, title, weight, max_score, due, brief, rubric, in_class) VALUES (?, ?, 'tugas', ?, ?, ?, ?, ?, ?, ?, 0)");
        $ins->execute([$id, $courseId, $week, $title, $weight, $maxScore, date('c', $dueTs), $brief, $rubric]);
        redirect('index.php?p=dosen_materi&course=' . urlencode($courseId) . '&ok=1#daftar-tugas');
    }

    if ($page === 'dosen_tugas_hapus') {
        $user = require_dosen();
        check_csrf();
        $id = (string) ($_POST['id'] ?? '');
        $courseId = (string) ($_POST['course_id'] ?? '');
        $stmt = $pdo->prepare("SELECT id, kind FROM assignments WHERE id = ?");
        $stmt->execute([$id]);
        $task = $stmt->fetch();
        if ($task && $task['kind'] === 'tugas') {
            $pdo->prepare('DELETE FROM submissions WHERE assignment_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM assignments WHERE id = ?')->execute([$id]);
        }
        redirect('index.php?p=dosen_materi&course=' . urlencode($courseId) . '&ok=1#daftar-tugas');
    }

    if ($page === 'dosen_presensi_simpan') {
        $user = require_dosen();
        check_csrf();
        $courseId = (string) ($_POST['course_id'] ?? '');
        $week = max(1, min(16, (int) ($_POST['week'] ?? 1)));
        $statuses = $_POST['status'] ?? [];
        $notes = $_POST['note'] ?? [];
        $allowed = array_keys(attendance_labels());

        $chkCourse = $pdo->prepare('SELECT 1 FROM courses WHERE id = ?');
        $chkCourse->execute([$courseId]);
        if (!$chkCourse->fetch()) {
            redirect('index.php?p=dosen_presensi');
        }

        $students = $pdo->query("SELECT id FROM users WHERE role = 'mahasiswa'")->fetchAll();
        $upsert = $pdo->prepare('INSERT INTO attendance (course_id, week, user_id, status, note, updated_at)
            VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT(course_id, week, user_id) DO UPDATE SET
                status = excluded.status,
                note = excluded.note,
                updated_at = excluded.updated_at');
        $now = date('c');

        foreach ($students as $stu) {
            $sid = (int) $stu['id'];
            $st = (string) ($statuses[$sid] ?? 'hadir');
            if (!in_array($st, $allowed, true)) {
                $st = 'hadir';
            }
            $note = trim((string) ($notes[$sid] ?? ''));
            $upsert->execute([$courseId, $week, $sid, $st, $note, $now]);
        }
        redirect('index.php?p=dosen_presensi&course=' . urlencode($courseId) . '&week=' . $week . '&ok=1');
    }

    if ($page === 'dosen_pengumuman_simpan') {
        $user = require_dosen();
        check_csrf();
        $courseId = trim((string) ($_POST['course_id'] ?? ''));
        $title = trim((string) ($_POST['title'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));

        if ($title === '' || $body === '') {
            redirect('index.php?p=dosen_pengumuman&err=kosong');
        }
        if ($courseId !== '') {
            $chk = $pdo->prepare('SELECT 1 FROM courses WHERE id = ?');
            $chk->execute([$courseId]);
            if (!$chk->fetch()) {
                $courseId = '';
            }
        }
        $ins = $pdo->prepare('INSERT INTO announcements (course_id, author_id, title, body, created_at) VALUES (?, ?, ?, ?, ?)');
        $ins->execute([$courseId, $user['id'], $title, $body, date('c')]);
        redirect('index.php?p=dosen_pengumuman&ok=1');
    }

    if ($page === 'dosen_pengumuman_hapus') {
        $user = require_dosen();
        check_csrf();
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM announcements WHERE id = ?')->execute([$id]);
        }
        redirect('index.php?p=dosen_pengumuman&ok=1');
    }

    if ($page === 'dosen_reset_pengumpulan') {
        $user = require_dosen();
        check_csrf();
        reset_all_submissions($pdo);
        redirect('index.php?p=tugas&ok=reset');
    }

    return false;
}

/**
 * Merender halaman khusus dosen. Mengembalikan true jika rute ditemukan.
 */
function render_dosen_page(string $page, PDO $pdo): bool
{
    if ($page === 'dosen_materi') {
        $user = require_dosen();
        $courses = $pdo->query('SELECT * FROM courses')->fetchAll();
        $courseMap = [];
        foreach ($courses as $c) {
            $courseMap[$c['id']] = $c;
        }
        $courseId = (string) ($_GET['course'] ?? ($courses[0]['id'] ?? 'pbp'));
        if (!isset($courseMap[$courseId])) {
            $courseId = $courses[0]['id'];
        }
        $week = max(1, min(16, (int) ($_GET['week'] ?? 1)));

        $coursePills = '';
        foreach ($courses as $c) {
            $cls = $c['id'] === $courseId ? 'pill is-on' : 'pill';
            $coursePills .= '<a class="' . $cls . '" href="index.php?p=dosen_materi&course=' . h($c['id']) . '&week=' . $week . '">' . h($c['code']) . ' · ' . h($c['name']) . '</a>';
        }

        $weekPills = '';
        for ($w = 1; $w <= 16; $w++) {
            $cls = 'pill' . ($w === $week ? ' is-on' : '') . ($w === 6 ? ' pill-uts' : ($w === 16 ? ' pill-uas' : ''));
            $lbl = 'P' . $w . ($w === 6 ? ' · UTS' : ($w === 16 ? ' · UAS' : ''));
            $weekPills .= '<a class="' . $cls . '" href="index.php?p=dosen_materi&course=' . h($courseId) . '&week=' . $w . '">' . $lbl . '</a>';
        }

        $mStmt = $pdo->prepare('SELECT * FROM materials WHERE course_id = ? AND week = ?');
        $mStmt->execute([$courseId, $week]);
        $mat = $mStmt->fetch() ?: [
            'title' => 'Pertemuan ' . $week,
            'minutes' => 15,
            'body' => '',
            'kind' => $week === 6 ? 'uts' : ($week === 16 ? 'uas' : 'materi'),
        ];

        $examBadge = '';
        if ($week === 6) {
            $examBadge = '<span class="badge-exam badge-uts">UTS · Pertemuan 6</span>';
        } elseif ($week === 16) {
            $examBadge = '<span class="badge-exam badge-uas">UAS · Pertemuan 16</span>';
        }

        $tasksStmt = $pdo->prepare('SELECT * FROM assignments WHERE course_id = ? ORDER BY week, due');
        $tasksStmt->execute([$courseId]);
        $tasks = $tasksStmt->fetchAll();

        $taskList = '';
        foreach ($tasks as $t) {
            $kindTag = $t['kind'] === 'uts' ? '<span class="badge-exam badge-uts">UTS</span>' : ($t['kind'] === 'uas' ? '<span class="badge-exam badge-uas">UAS</span>' : '<span class="badge-soft">Tugas P' . (int) $t['week'] . '</span>');
            $delBtn = '';
            if ($t['kind'] === 'tugas') {
                $delBtn = '<form method="post" action="index.php?p=dosen_tugas_hapus" class="inline-form">'
                    . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
                    . '<input type="hidden" name="id" value="' . h($t['id']) . '">'
                    . '<input type="hidden" name="course_id" value="' . h($courseId) . '">'
                    . '<button type="submit" class="btn-danger-sm">Hapus</button></form>';
            }
            $taskList .= '<div class="row row-static"><span><strong>' . $kindTag . ' ' . h($t['title']) . '</strong><small>Bobot ' . (int) $t['weight'] . '% · Maks ' . (int) $t['max_score'] . '</small></span><span class="row-actions"><a class="btn-link-sm" href="index.php?p=tugas_detail&id=' . h($t['id']) . '">Nilai</a>' . $delBtn . '</span></div>';
        }

        $msg = '';
        if (!empty($_GET['ok'])) {
            $msg = '<p class="okline">Perubahan materi atau tugas berhasil disimpan.</p>';
        } elseif (!empty($_GET['err'])) {
            $msg = '<p class="err">Lengkapi judul, deskripsi, dan tenggat waktu yang valid.</p>';
        }

        $defaultDue = date('Y-m-d\TH:i', strtotime('+7 days 23:59'));
        $courseTitle = $courseMap[$courseId]['code'] . ' — ' . $courseMap[$courseId]['name'];

        $html = '<p class="kicker">Khusus Dosen · Manajemen Kelas</p>';
        $html .= '<h1>Kelola Materi & Tugas.</h1>';
        $html .= '<p class="lede">Perbarui konten Pertemuan 1–16 (termasuk catatan UTS di Pertemuan 6 dan UAS di Pertemuan 16) serta buat tugas baru untuk mahasiswa.</p>';
        $html .= $msg;
        $html .= '<div class="pill-bar">' . $coursePills . '</div>';
        $html .= '<div class="pill-bar pill-bar-weeks">' . $weekPills . '</div>';

        $html .= '<div class="split">';
        $html .= '<section class="panel panel-accent">';
        $html .= '<div class="panel-head"><h2>Edit Pertemuan ' . $week . ' ' . $examBadge . '</h2><span class="panel-hint">' . h($courseMap[$courseId]['code']) . '</span></div>';
        if ($week === 6 || $week === 16) {
            $examId = $courseId . '-' . ($week === 6 ? 'uts' : 'uas');
            $html .= '<p class="info-banner">Pertemuan ' . $week . ' adalah jadwal <strong>' . ($week === 6 ? 'UTS' : 'UAS') . '</strong>. <a href="index.php?p=tugas_detail&id=' . h($examId) . '">Buka halaman input nilai ' . ($week === 6 ? 'UTS' : 'UAS') . ' ›</a></p>';
        }
        $html .= '<form method="post" action="index.php?p=dosen_materi_simpan" class="stack-form">';
        $html .= '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
        $html .= '<input type="hidden" name="course_id" value="' . h($courseId) . '">';
        $html .= '<input type="hidden" name="week" value="' . $week . '">';
        $html .= '<label>Judul Pertemuan<input name="title" value="' . h($mat['title']) . '" required></label>';
        $html .= '<label>Estimasi Durasi Baca / Ujian (menit)<input name="minutes" type="number" min="1" max="300" value="' . (int) $mat['minutes'] . '" required></label>';
        $html .= '<label>Isi Materi / Kisi-kisi Pertemuan<textarea name="body" rows="8" required>' . h($mat['body']) . '</textarea></label>';
        $html .= '<button type="submit">Simpan Pertemuan ' . $week . '</button>';
        $html .= '</form></section>';

        $html .= '<section class="panel" id="buat-tugas">';
        $html .= '<div class="panel-head"><h2>Buat Tugas Baru</h2><span class="panel-hint">' . h($courseTitle) . '</span></div>';
        $html .= '<form method="post" action="index.php?p=dosen_tugas_simpan" class="stack-form">';
        $html .= '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
        $html .= '<input type="hidden" name="course_id" value="' . h($courseId) . '">';
        $html .= '<label>Judul Tugas<input name="title" placeholder="Contoh: Studi Kasus Pertemuan 9" required></label>';
        $html .= '<div class="form-row-2">';
        $html .= '<label>Pertemuan Terkait<select name="week">';
        for ($w = 1; $w <= 16; $w++) {
            $sel = $w === $week ? ' selected' : '';
            $suffix = $w === 6 ? ' (UTS)' : ($w === 16 ? ' (UAS)' : '');
            $html .= '<option value="' . $w . '"' . $sel . '>Pertemuan ' . $w . $suffix . '</option>';
        }
        $html .= '</select></label>';
        $html .= '<label>Bobot (%)<input name="weight" type="number" min="1" max="100" value="15" required></label>';
        $html .= '</div>';
        $html .= '<div class="form-row-2">';
        $html .= '<label>Nilai Maksimum<input name="max_score" type="number" min="1" max="1000" value="100" required></label>';
        $html .= '<label>Tenggat Pengumpulan<input name="due" type="datetime-local" value="' . h($defaultDue) . '" required></label>';
        $html .= '</div>';
        $html .= '<label>Instruksi Tugas<textarea name="brief" rows="4" placeholder="Jelaskan instruksi pengerjaan dan format file yang dikumpulkan." required></textarea></label>';
        $html .= '<label>Kriteria Penilaian (pisahkan dengan |)<input name="rubric" placeholder="Ketepatan analisis|Struktur rapi|Referensi jelas"></label>';
        $html .= '<button type="submit">Terbitkan Tugas</button>';
        $html .= '</form></section>';
        $html .= '</div>';

        $html .= '<section class="panel section-gap" id="daftar-tugas"><div class="panel-head"><h2>Daftar Tugas, UTS & UAS (' . h($courseMap[$courseId]['code']) . ')</h2><span class="panel-hint">' . count($tasks) . ' komponen</span></div><div class="stack">' . ($taskList ?: '<p class="empty-note">Belum ada komponen penilaian.</p>') . '</div></section>';

        layout('Kelola Materi & Tugas', $html, $user);
        return true;
    }

    if ($page === 'dosen_presensi') {
        $user = require_dosen();
        $courses = $pdo->query('SELECT * FROM courses')->fetchAll();
        $courseMap = [];
        foreach ($courses as $c) {
            $courseMap[$c['id']] = $c;
        }
        $courseId = (string) ($_GET['course'] ?? ($courses[0]['id'] ?? 'pbp'));
        if (!isset($courseMap[$courseId])) {
            $courseId = $courses[0]['id'];
        }
        $week = max(1, min(16, (int) ($_GET['week'] ?? 6)));

        $coursePills = '';
        foreach ($courses as $c) {
            $cls = $c['id'] === $courseId ? 'pill is-on' : 'pill';
            $coursePills .= '<a class="' . $cls . '" href="index.php?p=dosen_presensi&course=' . h($c['id']) . '&week=' . $week . '">' . h($c['code']) . ' · ' . h($c['name']) . '</a>';
        }

        $weekPills = '';
        for ($w = 1; $w <= 16; $w++) {
            $cls = 'pill' . ($w === $week ? ' is-on' : '') . ($w === 6 ? ' pill-uts' : ($w === 16 ? ' pill-uas' : ''));
            $lbl = 'P' . $w . ($w === 6 ? ' · UTS' : ($w === 16 ? ' · UAS' : ''));
            $weekPills .= '<a class="' . $cls . '" href="index.php?p=dosen_presensi&course=' . h($courseId) . '&week=' . $w . '">' . $lbl . '</a>';
        }

        $students = $pdo->query("SELECT id, name, nim FROM users WHERE role = 'mahasiswa' ORDER BY nim")->fetchAll();
        $attStmt = $pdo->prepare('SELECT user_id, status, note FROM attendance WHERE course_id = ? AND week = ?');
        $attStmt->execute([$courseId, $week]);
        $attMap = [];
        foreach ($attStmt->fetchAll() as $row) {
            $attMap[(int) $row['user_id']] = $row;
        }

        $matStmt = $pdo->prepare('SELECT title FROM materials WHERE course_id = ? AND week = ?');
        $matStmt->execute([$courseId, $week]);
        $topic = (string) ($matStmt->fetchColumn() ?: ('Pertemuan ' . $week));

        $labels = attendance_labels();
        $formRows = '';
        foreach ($students as $stu) {
            $sid = (int) $stu['id'];
            $curStatus = $attMap[$sid]['status'] ?? 'hadir';
            $curNote = $attMap[$sid]['note'] ?? '';
            $opts = '';
            foreach ($labels as $val => $lbl) {
                $sel = $curStatus === $val ? ' selected' : '';
                $opts .= '<option value="' . h($val) . '"' . $sel . '>' . h($lbl) . '</option>';
            }
            $formRows .= '<tr>'
                . '<td data-label="NIM"><code>' . h($stu['nim']) . '</code></td>'
                . '<td data-label="Nama Mahasiswa"><strong>' . h($stu['name']) . '</strong></td>'
                . '<td data-label="Status"><select name="status[' . $sid . ']">' . $opts . '</select></td>'
                . '<td data-label="Catatan"><input name="note[' . $sid . ']" value="' . h($curNote) . '" placeholder="Catatan (opsional)"></td>'
                . '</tr>';
        }

        // Rekap kehadiran semester per mahasiswa untuk mata kuliah aktif
        $recapStmt = $pdo->prepare('SELECT user_id, status, COUNT(*) AS cnt FROM attendance WHERE course_id = ? GROUP BY user_id, status');
        $recapStmt->execute([$courseId]);
        $recapCounts = [];
        foreach ($recapStmt->fetchAll() as $r) {
            $recapCounts[(int) $r['user_id']][$r['status']] = (int) $r['cnt'];
        }
        $recapRows = '';
        foreach ($students as $stu) {
            $sid = (int) $stu['id'];
            $hCount = $recapCounts[$sid]['hadir'] ?? 0;
            $iCount = $recapCounts[$sid]['izin'] ?? 0;
            $sCount = $recapCounts[$sid]['sakit'] ?? 0;
            $aCount = $recapCounts[$sid]['alpa'] ?? 0;
            $totalMarked = $hCount + $iCount + $sCount + $aCount;
            $pct = $totalMarked > 0 ? round(($hCount / $totalMarked) * 100) . '%' : '—';
            $recapRows .= '<tr><td data-label="NIM"><code>' . h($stu['nim']) . '</code></td><td data-label="Nama">' . h($stu['name']) . '</td><td data-label="Hadir">' . $hCount . '</td><td data-label="Izin">' . $iCount . '</td><td data-label="Sakit">' . $sCount . '</td><td data-label="Alpa">' . $aCount . '</td><td data-label="Persentase"><strong>' . $pct . '</strong> (' . $totalMarked . '/16)</td></tr>';
        }

        $msg = !empty($_GET['ok']) ? '<p class="okline">Presensi Pertemuan ' . $week . ' tersimpan.</p>' : '';
        $badge = $week === 6 ? ' <span class="badge-exam badge-uts">UTS</span>' : ($week === 16 ? ' <span class="badge-exam badge-uas">UAS</span>' : '');

        $html = '<p class="kicker">Khusus Dosen · Presensi Kelas</p>';
        $html .= '<h1>Presensi Pertemuan 1–16.</h1>';
        $html .= '<p class="lede">Catat kehadiran mahasiswa untuk setiap pertemuan termasuk UTS (Pertemuan 6) dan UAS (Pertemuan 16).</p>';
        $html .= $msg;
        $html .= '<div class="pill-bar">' . $coursePills . '</div>';
        $html .= '<div class="pill-bar pill-bar-weeks">' . $weekPills . '</div>';

        $html .= '<section class="panel panel-accent">';
        $html .= '<div class="panel-head"><h2>Pertemuan ' . $week . $badge . ' · ' . h($courseMap[$courseId]['code']) . '</h2><span class="panel-hint">' . count($students) . ' mahasiswa</span></div>';
        $html .= '<p class="muted">' . h($topic) . '</p>';
        $html .= '<form method="post" action="index.php?p=dosen_presensi_simpan">';
        $html .= '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
        $html .= '<input type="hidden" name="course_id" value="' . h($courseId) . '">';
        $html .= '<input type="hidden" name="week" value="' . $week . '">';
        $html .= '<div class="table-wrap"><table class="data-table"><thead><tr><th>NIM</th><th>Nama Mahasiswa</th><th>Status</th><th>Catatan</th></tr></thead><tbody>' . $formRows . '</tbody></table></div>';
        $html .= '<div class="form-actions"><button type="submit">Simpan Presensi Pertemuan ' . $week . '</button></div>';
        $html .= '</form></section>';

        $html .= '<section class="panel section-gap"><div class="panel-head"><h2>Rekap Kehadiran ' . h($courseMap[$courseId]['name']) . '</h2><span class="panel-hint">Pertemuan tercatat</span></div>';
        $html .= '<div class="table-wrap"><table class="data-table"><thead><tr><th>NIM</th><th>Nama</th><th>Hadir</th><th>Izin</th><th>Sakit</th><th>Alpa</th><th>Persentase</th></tr></thead><tbody>' . $recapRows . '</tbody></table></div></section>';

        layout('Presensi Pertemuan', $html, $user);
        return true;
    }

    if ($page === 'dosen_pengumuman') {
        $user = require_dosen();
        $courses = $pdo->query('SELECT * FROM courses')->fetchAll();
        $courseOpts = '<option value="">Semua Mata Kuliah</option>';
        $courseNames = ['' => 'Semua Mata Kuliah'];
        foreach ($courses as $c) {
            $courseNames[$c['id']] = $c['code'] . ' · ' . $c['name'];
            $courseOpts .= '<option value="' . h($c['id']) . '">' . h($c['code'] . ' · ' . $c['name']) . '</option>';
        }

        $anns = $pdo->query('SELECT a.*, u.name AS author_name FROM announcements a JOIN users u ON u.id = a.author_id ORDER BY a.created_at DESC, a.id DESC')->fetchAll();
        $annList = '';
        foreach ($anns as $ann) {
            $target = $courseNames[$ann['course_id']] ?? 'Semua Mata Kuliah';
            $annList .= '<div class="card ann-card"><div class="between"><span class="badge-soft">' . h($target) . '</span><form method="post" action="index.php?p=dosen_pengumuman_hapus" class="inline-form"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="id" value="' . (int) $ann['id'] . '"><button type="submit" class="btn-danger-sm">Hapus</button></form></div>';
            $annList .= '<h2>' . h($ann['title']) . '</h2><p>' . nl2br(h($ann['body'])) . '</p>';
            $annList .= '<p class="muted">' . h($ann['author_name']) . ' · ' . h(date('j M Y H:i', strtotime($ann['created_at']))) . '</p></div>';
        }

        $students = $pdo->query("SELECT * FROM users WHERE role = 'mahasiswa' ORDER BY nim")->fetchAll();
        $totalMaterials = (int) $pdo->query('SELECT COUNT(*) FROM materials')->fetchColumn();
        $readStmt = $pdo->prepare('SELECT COUNT(*) FROM reads WHERE user_id = ?');
        $subStmt = $pdo->prepare('SELECT COUNT(*) FROM submissions WHERE user_id = ?');
        $attStmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE user_id = ? AND status = 'hadir'");
        $attTotStmt = $pdo->prepare('SELECT COUNT(*) FROM attendance WHERE user_id = ?');

        $stuRows = '';
        foreach ($students as $stu) {
            $sid = (int) $stu['id'];
            $readStmt->execute([$sid]);
            $rCnt = (int) $readStmt->fetchColumn();
            $subStmt->execute([$sid]);
            $sCnt = (int) $subStmt->fetchColumn();
            $attStmt->execute([$sid]);
            $hCnt = (int) $attStmt->fetchColumn();
            $attTotStmt->execute([$sid]);
            $tCnt = (int) $attTotStmt->fetchColumn();
            $attText = $tCnt > 0 ? round(($hCnt / $tCnt) * 100) . '%' : '—';
            $stats = student_course_stats($pdo, $sid);

            $stuRows .= '<tr>'
                . '<td data-label="NIM"><code>' . h($stu['nim']) . '</code></td>'
                . '<td data-label="Mahasiswa"><div class="user-inline">' . user_avatar_html($stu, 'avatar-xs') . '<div><strong>' . h($stu['name']) . '</strong><br><small class="muted">@' . h($stu['username']) . '</small></div></div></td>'
                . '<td data-label="Bacaan">' . $rCnt . '/' . $totalMaterials . '</td>'
                . '<td data-label="Tugas">' . $sCnt . ' tugas</td>'
                . '<td data-label="Hadir">' . $attText . '</td>'
                . '<td data-label="IPS"><strong>' . h($stats['ips']) . '</strong></td>'
                . '<td data-label="Aksi"><a class="btn-link-sm" href="index.php?p=nilai&student=' . $sid . '">Rekap Nilai</a></td>'
                . '</tr>';
        }

        $msg = !empty($_GET['ok']) ? '<p class="okline">Pengumuman berhasil diperbarui.</p>' : (!empty($_GET['err']) ? '<p class="err">Judul dan isi pengumuman wajib diisi.</p>' : '');

        $html = '<p class="kicker">Khusus Dosen · Pengumuman & Mahasiswa</p>';
        $html .= '<h1>Pengumuman & Direktori Mahasiswa.</h1>';
        $html .= '<p class="lede">Sampaikan informasi penting ke halaman Meja mahasiswa dan pantau ringkasan keaktifan seluruh mahasiswa.</p>';
        $html .= $msg;
        $html .= '<div class="split">';
        $html .= '<section class="panel panel-accent"><div class="panel-head"><h2>Terbitkan Pengumuman</h2><span class="panel-hint">Tampil di Meja</span></div>';
        $html .= '<form method="post" action="index.php?p=dosen_pengumuman_simpan" class="stack-form">';
        $html .= '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
        $html .= '<label>Target Kelas<select name="course_id">' . $courseOpts . '</select></label>';
        $html .= '<label>Judul Pengumuman<input name="title" placeholder="Contoh: Jadwal Praktikum Pengganti" required></label>';
        $html .= '<label>Isi Pengumuman<textarea name="body" rows="5" placeholder="Tulis informasi untuk mahasiswa..." required></textarea></label>';
        $html .= '<button type="submit">Terbitkan Pengumuman</button>';
        $html .= '</form></section>';

        $html .= '<section class="panel"><div class="panel-head"><h2>Pengumuman Aktif</h2><span class="panel-hint">' . count($anns) . ' terbit</span></div>';
        $html .= '<div class="stack">' . ($annList ?: '<p class="empty-note">Belum ada pengumuman.</p>') . '</div></section>';
        $html .= '</div>';

        $html .= '<section class="panel section-gap"><div class="panel-head"><h2>Daftar Mahasiswa Terdaftar</h2><span class="panel-hint">' . count($students) . ' mahasiswa</span></div>';
        $html .= '<div class="table-wrap"><table class="data-table"><thead><tr><th>NIM</th><th>Mahasiswa</th><th>Bacaan</th><th>Tugas</th><th>Hadir</th><th>IPS</th><th>Aksi</th></tr></thead><tbody>' . $stuRows . '</tbody></table></div></section>';

        layout('Pengumuman & Mahasiswa', $html, $user);
        return true;
    }

    return false;
}

