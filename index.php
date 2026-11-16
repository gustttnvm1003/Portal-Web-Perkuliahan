<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/dosen_pages.php';

start_session();
$page = $_GET['p'] ?? 'beranda';

if ($page === 'masuk' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $stmt = db()->prepare("SELECT * FROM users WHERE username = ? OR nim = ? OR (email != '' AND LOWER(email) = LOWER(?)) LIMIT 1");
    $stmt->execute([$username, $username, $username]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $user['id'];
        redirect('index.php?p=beranda');
    }
    $_SESSION['flash'] = 'NIM, NIDN, email, atau kata sandi tidak cocok.';
    redirect('index.php?p=masuk&tab=login');
}

function auth_otp_error_text(array $res): string
{
    $err = (string) ($res['err'] ?? '');
    if ($err === 'cooldown') {
        $wait = max(1, (int) ($res['wait'] ?? 45));
        return 'Tunggu ' . $wait . ' detik sebelum mengirim ulang kode OTP.';
    }
    if ($err === 'mail_unconfigured') {
        return 'Layanan kirim email OTP belum dikonfigurasi oleh admin (atur GAS_WEBHOOK_URL, BREVO_API_KEY, RESEND_API_KEY, atau SMTP_* pada server).';
    }
    if ($err === 'mail_failed') {
        return 'Gagal mengirim email OTP ke alamat tersebut. Periksa konfigurasi SMTP / API Email server.';
    }
    if ($err === 'expired') {
        return 'Kode OTP sudah kedaluwarsa (lebih dari 10 menit). Silakan kirim ulang kode OTP.';
    }
    if ($err === 'too_many') {
        return 'Terlalu banyak percobaan OTP yang salah. Silakan minta kode OTP baru.';
    }
    if ($err === 'not_found') {
        return 'Belum ada kode OTP aktif untuk email tersebut. Silakan kirim OTP terlebih dahulu.';
    }
    return 'Kode OTP 6 digit tidak valid.';
}

if ($page === 'daftar_kirim_otp' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $pdo = db();
    $isResend = !empty($_POST['resend']) && !empty($_SESSION['reg_pending']['password_hash']);
    if ($isResend) {
        $pending = $_SESSION['reg_pending'];
        $name = (string) $pending['name'];
        $nim = (string) $pending['nim'];
        $email = (string) $pending['email'];
        $passHash = (string) $pending['password_hash'];
    } else {
        $name = trim((string) ($_POST['name'] ?? ''));
        $nim = trim((string) ($_POST['nim'] ?? ''));
        $email = normalize_email((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $_SESSION['reg_draft'] = ['name' => $name, 'nim' => $nim, 'email' => $email];

        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            $_SESSION['flash'] = 'Nama lengkap harus diisi (2–100 karakter).';
            redirect('index.php?p=masuk&tab=daftar');
        }
        if (!preg_match('/^[A-Za-z0-9._-]{4,40}$/', $nim)) {
            $_SESSION['flash'] = 'NIM mahasiswa minimal 4 karakter (huruf, angka, titik, atau tanda hubung tanpa spasi).';
            redirect('index.php?p=masuk&tab=daftar');
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash'] = 'Masukkan alamat email pribadi / Gmail yang valid.';
            redirect('index.php?p=masuk&tab=daftar');
        }
        if (strlen($password) < 8) {
            $_SESSION['flash'] = 'Kata sandi pendaftaran minimal 8 karakter.';
            redirect('index.php?p=masuk&tab=daftar');
        }
        $passHash = password_hash($password, PASSWORD_DEFAULT);
    }

    $chkNim = $pdo->prepare('SELECT id FROM users WHERE nim = ? OR username = ? LIMIT 1');
    $chkNim->execute([$nim, $nim]);
    if ($chkNim->fetch()) {
        $_SESSION['flash'] = 'NIM tersebut sudah terdaftar. Silakan masuk atau gunakan NIM lain.';
        redirect('index.php?p=masuk&tab=daftar');
    }
    $chkEmail = $pdo->prepare("SELECT id FROM users WHERE email != '' AND LOWER(email) = LOWER(?) LIMIT 1");
    $chkEmail->execute([$email]);
    if ($chkEmail->fetch()) {
        $_SESSION['flash'] = 'Email tersebut sudah terdaftar pada akun lain. Silakan masuk menggunakan OTP Email atau kata sandi.';
        redirect('index.php?p=masuk&tab=daftar');
    }

    $payload = [
        'name' => $name,
        'nim' => $nim,
        'password_hash' => $passHash,
    ];
    $otpRes = issue_email_otp($pdo, $email, 'register', $name, $payload);
    if (!$otpRes['ok']) {
        $_SESSION['flash'] = auth_otp_error_text($otpRes);
        redirect('index.php?p=masuk&tab=daftar' . ($isResend ? '&step=otp' : ''));
    }

    $_SESSION['reg_pending'] = [
        'name' => $name,
        'nim' => $nim,
        'email' => $email,
        'password_hash' => $passHash,
        'sent_at' => time(),
        'preview_code' => $otpRes['preview_code'] ?? null,
    ];
    $_SESSION['flash_ok'] = 'Kode OTP 6 digit telah dikirim ke ' . $email . '. Berlaku selama 10 menit.';
    redirect('index.php?p=masuk&tab=daftar&step=otp');
}

if ($page === 'daftar_verifikasi' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $pdo = db();
    $pending = $_SESSION['reg_pending'] ?? null;
    $email = normalize_email((string) ($pending['email'] ?? ($_POST['email'] ?? '')));
    $otp = (string) ($_POST['otp'] ?? '');

    if ($email === '') {
        $_SESSION['flash'] = 'Sesi pendaftaran tidak ditemukan. Silakan isi data pendaftaran lagi.';
        redirect('index.php?p=masuk&tab=daftar');
    }

    $ver = verify_email_otp($pdo, $email, 'register', $otp);
    if (!$ver['ok']) {
        $_SESSION['flash'] = auth_otp_error_text($ver);
        redirect('index.php?p=masuk&tab=daftar&step=otp');
    }

    $payload = $ver['payload'] ?? [];
    $name = trim((string) ($payload['name'] ?? ($pending['name'] ?? '')));
    $nim = trim((string) ($payload['nim'] ?? ($pending['nim'] ?? '')));
    $passHash = (string) ($payload['password_hash'] ?? '');
    if ($name === '' || $nim === '' || $passHash === '') {
        $_SESSION['flash'] = 'Data pendaftaran tidak lengkap. Silakan ulangi pendaftaran.';
        redirect('index.php?p=masuk&tab=daftar');
    }

    $pdo->beginTransaction();
    try {
        $chkDup = $pdo->prepare("SELECT id FROM users WHERE nim = ? OR username = ? OR (email != '' AND LOWER(email) = LOWER(?)) LIMIT 1");
        $chkDup->execute([$nim, $nim, $email]);
        if ($chkDup->fetch()) {
            $pdo->rollBack();
            $_SESSION['flash'] = 'NIM atau email sudah digunakan oleh akun lain.';
            redirect('index.php?p=masuk&tab=daftar');
        }
        $ins = $pdo->prepare("INSERT INTO users (name, username, password_hash, role, nim, email, prodi) VALUES (?, ?, ?, 'mahasiswa', ?, ?, 'Teknik Informatika')");
        $ins->execute([$name, $nim, $passHash, $nim, $email]);
        $newId = (int) $pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash'] = 'Pendaftaran gagal disimpan. Silakan coba lagi.';
        redirect('index.php?p=masuk&tab=daftar');
    }

    unset($_SESSION['reg_pending'], $_SESSION['reg_draft']);
    session_regenerate_id(true);
    $_SESSION['uid'] = $newId;
    redirect('index.php?p=beranda');
}

if ($page === 'otp_kirim' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $pdo = db();
    $tab = 'lupa';
    $email = normalize_email((string) ($_POST['email'] ?? ''));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['flash'] = 'Masukkan alamat email yang valid.';
        redirect('index.php?p=masuk&tab=' . $tab);
    }

    $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE email != '' AND LOWER(email) = LOWER(?) LIMIT 1");
    $stmt->execute([$email]);
    $targetUser = $stmt->fetch();
    if (!$targetUser) {
        $_SESSION['flash'] = 'Email tersebut belum terdaftar pada akun mana pun. Silakan daftar atau lengkapi email di menu Profil Saya.';
        redirect('index.php?p=masuk&tab=' . $tab);
    }

    $otpRes = issue_email_otp($pdo, (string) $targetUser['email'], 'reset', (string) $targetUser['name'], ['user_id' => (int) $targetUser['id']]);
    if (!$otpRes['ok']) {
        $_SESSION['flash'] = auth_otp_error_text($otpRes);
        redirect('index.php?p=masuk&tab=' . $tab);
    }

    $_SESSION['otp_pending_reset'] = [
        'email' => normalize_email((string) $targetUser['email']),
        'sent_at' => time(),
        'preview_code' => $otpRes['preview_code'] ?? null,
    ];
    $_SESSION['flash_ok'] = 'Kode OTP 6 digit telah dikirim ke ' . $targetUser['email'] . '. Berlaku 10 menit.';
    redirect('index.php?p=masuk&tab=' . $tab . '&step=verif');
}

if ($page === 'lupa_verifikasi' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $pdo = db();
    $pending = $_SESSION['otp_pending_reset'] ?? null;
    $email = normalize_email((string) ($pending['email'] ?? ($_POST['email'] ?? '')));
    $otp = (string) ($_POST['otp'] ?? '');
    $newPass = (string) ($_POST['new_password'] ?? '');
    $confirmPass = (string) ($_POST['confirm_password'] ?? '');

    if ($email === '') {
        $_SESSION['flash'] = 'Masukkan email terdaftar dan minta kode OTP reset terlebih dahulu.';
        redirect('index.php?p=masuk&tab=lupa');
    }
    if (strlen($newPass) < 8) {
        $_SESSION['flash'] = 'Kata sandi baru minimal 8 karakter.';
        redirect('index.php?p=masuk&tab=lupa&step=verif');
    }
    if ($newPass !== $confirmPass) {
        $_SESSION['flash'] = 'Konfirmasi kata sandi baru tidak sama.';
        redirect('index.php?p=masuk&tab=lupa&step=verif');
    }

    $ver = verify_email_otp($pdo, $email, 'reset', $otp);
    if (!$ver['ok']) {
        $_SESSION['flash'] = auth_otp_error_text($ver);
        redirect('index.php?p=masuk&tab=lupa&step=verif');
    }

    $uid = (int) ($ver['payload']['user_id'] ?? 0);
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($newPass, PASSWORD_DEFAULT), $uid]);
    unset($_SESSION['otp_pending_reset']);
    $_SESSION['flash_ok'] = 'Kata sandi berhasil diperbarui. Silakan masuk dengan kata sandi baru Anda.';
    redirect('index.php?p=masuk&tab=login');
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

if ($page === 'foto') {
    $viewer = require_user();
    $uid = (int) ($_GET['id'] ?? $viewer['id']);
    $stmt = db()->prepare('SELECT id, avatar FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    $row = $stmt->fetch();
    $file = $row ? basename((string) ($row['avatar'] ?? '')) : '';
    $path = $file !== '' ? avatars_dir() . DIRECTORY_SEPARATOR . $file : '';
    if ($file === '' || !is_file($path)) {
        http_response_code(404);
        exit('Foto profil tidak ditemukan.');
    }
    $info = @getimagesize($path);
    $allowedMime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $mime = ($info && in_array($info['mime'] ?? '', $allowedMime, true)) ? $info['mime'] : 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
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

if ($page === 'profil_simpan' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = require_user();
    check_csrf();
    $pdo = db();

    $name = trim((string) ($_POST['name'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    $nim = trim((string) ($_POST['nim'] ?? ''));
    $prodi = trim((string) ($_POST['prodi'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $bio = trim((string) ($_POST['bio'] ?? ''));

    if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
        redirect('index.php?p=profil&err=nama');
    }
    if (mb_strlen($username) < 3 || mb_strlen($username) > 60) {
        redirect('index.php?p=profil&err=username');
    }
    $chkUser = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
    $chkUser->execute([$username, (int) $user['id']]);
    if ($chkUser->fetch()) {
        redirect('index.php?p=profil&err=username_dipakai');
    }
    if ($nim === '' || mb_strlen($nim) > 40) {
        redirect('index.php?p=profil&err=nim');
    }
    $chkNim = $pdo->prepare('SELECT id FROM users WHERE nim = ? AND id != ?');
    $chkNim->execute([$nim, (int) $user['id']]);
    if ($chkNim->fetch()) {
        redirect('index.php?p=profil&err=nim_dipakai');
    }
    if ($email !== '') {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            redirect('index.php?p=profil&err=email');
        }
        $email = normalize_email($email);
        $chkEmail = $pdo->prepare("SELECT id FROM users WHERE email != '' AND LOWER(email) = LOWER(?) AND id != ?");
        $chkEmail->execute([$email, (int) $user['id']]);
        if ($chkEmail->fetch()) {
            redirect('index.php?p=profil&err=email_dipakai');
        }
    }

    $prodi = mb_substr($prodi, 0, 80);
    $phone = mb_substr($phone, 0, 30);
    $bio = mb_substr($bio, 0, 400);

    $avatar = basename((string) ($user['avatar'] ?? ''));
    $hasUploadAttempt = !empty($_FILES['avatar']['name'])
        || (int) ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($hasUploadAttempt) {
        $file = $_FILES['avatar'];
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int) ($file['size'] ?? 0) > 4 * 1024 * 1024) {
            redirect('index.php?p=profil&err=foto');
        }
        $imgInfo = @getimagesize((string) $file['tmp_name']);
        $imgMap = [
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
            IMAGETYPE_GIF => 'gif',
        ];
        $imgType = $imgInfo[2] ?? 0;
        if (!$imgInfo || !isset($imgMap[$imgType])) {
            redirect('index.php?p=profil&err=foto');
        }
        $dir = avatars_dir();
        $stored = 'u' . (int) $user['id'] . '_' . bin2hex(random_bytes(6)) . '.' . $imgMap[$imgType];
        $dest = $dir . DIRECTORY_SEPARATOR . $stored;
        if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
            redirect('index.php?p=profil&err=foto');
        }
        if ($avatar !== '' && is_file($dir . DIRECTORY_SEPARATOR . $avatar)) {
            @unlink($dir . DIRECTORY_SEPARATOR . $avatar);
        }
        $avatar = $stored;
    }

    $upd = $pdo->prepare('UPDATE users SET name = ?, username = ?, nim = ?, prodi = ?, email = ?, phone = ?, bio = ?, avatar = ? WHERE id = ?');
    $upd->execute([$name, $username, $nim, $prodi, $email, $phone, $bio, $avatar, (int) $user['id']]);
    redirect('index.php?p=profil&ok=1');
}

if ($page === 'profil_hapus_foto' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = require_user();
    check_csrf();
    $old = basename((string) ($user['avatar'] ?? ''));
    if ($old !== '') {
        $oldPath = avatars_dir() . DIRECTORY_SEPARATOR . $old;
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }
    db()->prepare("UPDATE users SET avatar = '' WHERE id = ?")->execute([(int) $user['id']]);
    redirect('index.php?p=profil&ok=foto_dihapus');
}

if ($page === 'profil_sandi' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = require_user();
    check_csrf();
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if (!password_verify($current, (string) $user['password_hash'])) {
        redirect('index.php?p=profil&err=sandi_lama');
    }
    if (strlen($new) < 6) {
        redirect('index.php?p=profil&err=sandi_pendek');
    }
    if ($new !== $confirm) {
        redirect('index.php?p=profil&err=sandi_beda');
    }
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), (int) $user['id']]);
    redirect('index.php?p=profil&ok=sandi');
}

function auth_svg_icon(string $name): string
{
    $icons = [
        'shield' => '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg>',
        'key' => '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="7.5" cy="15.5" r="4.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/></svg>',
        'mail-check' => '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 13V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v12c0 1.1.9 2 2 2h8"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/><path d="m16 19 2 2 4-4"/></svg>',
        'user-plus' => '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/></svg>',
        'user' => '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        'badge' => '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="6" x2="10" y1="10" y2="10"/><line x1="6" x2="14" y1="14" y2="14"/></svg>',
        'mail' => '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>',
        'lock' => '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
        'eye' => '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>',
    ];
    return $icons[$name] ?? '';
}

if ($page === 'masuk') {
    if (current_user()) {
        redirect('index.php?p=beranda');
    }

    $tab = (string) ($_GET['tab'] ?? 'login');
    if (!in_array($tab, ['login', 'daftar', 'lupa'], true)) {
        $tab = 'login';
    }
    $step = (string) ($_GET['step'] ?? '');
    if (!empty($_GET['ubah'])) {
        if ($tab === 'daftar') {
            unset($_SESSION['reg_pending']);
        } elseif ($tab === 'lupa') {
            unset($_SESSION['otp_pending_reset']);
        }
    }

    $flash = (string) ($_SESSION['flash'] ?? '');
    $flashOk = (string) ($_SESSION['flash_ok'] ?? '');
    unset($_SESSION['flash'], $_SESSION['flash_ok']);
    $err = $flash !== '' ? '<p class="err">' . h($flash) . '</p>' : '';
    $okAlert = $flashOk !== '' ? '<p class="okline">' . h($flashOk) . '</p>' : '';

    $csrf = h(csrf_token());
    $demoHtml = getenv('SHOW_DEMO') === '1' ? '
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
        </div>' : '';

    $kickerMap = [
        'login' => [auth_svg_icon('shield') . '<span>AKUN TERDAFTAR</span>', 'Selamat datang', 'Masuk dengan NIM, NIDN, nama pengguna, atau email terdaftar.'],
        'daftar' => [auth_svg_icon('user-plus') . '<span>PENDAFTARAN BARU</span>', 'Daftar Akun Mahasiswa', 'Daftarkan akun mahasiswa baru menggunakan Email, NIM, dan verifikasi OTP email.'],
        'lupa' => [auth_svg_icon('lock') . '<span>PULIHKAN AKSES</span>', 'Reset Kata Sandi', 'Masukkan email terdaftar untuk menerima kode OTP pemulihan akun.'],
    ];
    [$kickerHtml, $headingText, $subText] = $kickerMap[$tab];

    $tabsHtml = '<nav class="auth-tabs" aria-label="Metode autentikasi">'
        . '<a class="auth-tab' . ($tab === 'login' ? ' is-on' : '') . '" href="index.php?p=masuk&tab=login">' . auth_svg_icon('key') . '<span>Masuk</span></a>'
        . '<a class="auth-tab' . ($tab === 'daftar' ? ' is-on' : '') . '" href="index.php?p=masuk&tab=daftar">' . auth_svg_icon('user-plus') . '<span>Daftar</span></a>'
        . '</nav>';

    $formBody = '';
    if ($tab === 'login') {
        $formBody = '<form method="post" action="index.php?p=masuk" class="auth-form">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '">'
            . '<div class="auth-field"><label for="login-user">NIM, NIDN, Email, atau Nama Pengguna</label>'
            . '<div class="input-wrap"><span class="input-ico">' . auth_svg_icon('badge') . '</span>'
            . '<input id="login-user" name="username" autocomplete="username" placeholder="240511001 / NIDN / nama@gmail.com" required></div></div>'
            . '<div class="auth-field"><div class="auth-label-row"><label for="login-pass">Kata sandi</label><a class="auth-forgot" href="index.php?p=masuk&tab=lupa">Lupa kata sandi?</a></div>'
            . '<div class="input-wrap"><span class="input-ico">' . auth_svg_icon('lock') . '</span>'
            . '<input id="login-pass" name="password" type="password" autocomplete="current-password" placeholder="Masukkan kata sandi" required>'
            . '<button type="button" class="pass-toggle" data-toggle-pass="login-pass" aria-label="Tampilkan kata sandi" aria-pressed="false">' . auth_svg_icon('eye') . '</button></div></div>'
            . '<button type="submit" class="auth-submit">Masuk &rarr;</button>'
            . '</form>' . $demoHtml;
    } elseif ($tab === 'daftar') {
        $pendingReg = $_SESSION['reg_pending'] ?? null;
        if ($step === 'otp' && is_array($pendingReg) && !empty($pendingReg['email'])) {
            $previewHint = !empty($pendingReg['preview_code'])
                ? '<p class="auth-preview-otp">Mode uji lokal · Kode OTP: <strong>' . h((string) $pendingReg['preview_code']) . '</strong></p>'
                : '';
            $formBody = '<div class="auth-pending-box">'
                . '<div><strong>' . h((string) $pendingReg['name']) . '</strong> · NIM <code>' . h((string) $pendingReg['nim']) . '</code><br>'
                . '<small>OTP dikirim ke <strong>' . h((string) $pendingReg['email']) . '</strong></small></div>'
                . '<a href="index.php?p=masuk&tab=daftar&ubah=1">Ubah data</a></div>'
                . $previewHint
                . '<form method="post" action="index.php?p=daftar_verifikasi" class="auth-form">'
                . '<input type="hidden" name="csrf" value="' . $csrf . '">'
                . '<div class="auth-field"><label for="reg-otp-code">Masukkan Kode OTP 6 Digit</label>'
                . '<input id="reg-otp-code" class="otp-input" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="000000" autocomplete="one-time-code" data-otp-input required autofocus></div>'
                . '<button type="submit" class="auth-submit">Verifikasi OTP &amp; Buat Akun &rarr;</button></form>'
                . '<form method="post" action="index.php?p=daftar_kirim_otp" class="auth-resend-form">'
                . '<input type="hidden" name="csrf" value="' . $csrf . '">'
                . '<input type="hidden" name="resend" value="1">'
                . '<button type="submit" class="btn-ghost-sm auth-resend-btn">Kirim Ulang OTP ke Email</button></form>';
        } else {
            $draft = $_SESSION['reg_draft'] ?? ['name' => '', 'nim' => '', 'email' => ''];
            $formBody = '<form method="post" action="index.php?p=daftar_kirim_otp" class="auth-form">'
                . '<input type="hidden" name="csrf" value="' . $csrf . '">'
                . '<div class="auth-field"><label for="reg-name">Nama lengkap</label>'
                . '<div class="input-wrap"><span class="input-ico">' . auth_svg_icon('user') . '</span>'
                . '<input id="reg-name" name="name" value="' . h((string) ($draft['name'] ?? '')) . '" maxlength="100" autocomplete="name" placeholder="Nama lengkap sesuai data kampus" required></div></div>'
                . '<div class="auth-field"><label for="reg-nim">NIM Mahasiswa</label>'
                . '<div class="input-wrap"><span class="input-ico">' . auth_svg_icon('badge') . '</span>'
                . '<input id="reg-nim" name="nim" value="' . h((string) ($draft['nim'] ?? '')) . '" maxlength="40" placeholder="Contoh: 240511088" required></div></div>'
                . '<div class="auth-field"><label for="reg-email">Email pribadi / Gmail</label>'
                . '<div class="input-wrap"><span class="input-ico">' . auth_svg_icon('mail') . '</span>'
                . '<input id="reg-email" name="email" type="email" value="' . h((string) ($draft['email'] ?? '')) . '" maxlength="120" autocomplete="email" placeholder="nama@gmail.com" required></div></div>'
                . '<div class="auth-field"><label for="reg-pass">Buat kata sandi (minimal 8 karakter)</label>'
                . '<div class="input-wrap"><span class="input-ico">' . auth_svg_icon('lock') . '</span>'
                . '<input id="reg-pass" name="password" type="password" minlength="8" autocomplete="new-password" placeholder="Minimal 8 karakter" data-pass-input required>'
                . '<button type="button" class="pass-toggle" data-toggle-pass="reg-pass" aria-label="Tampilkan kata sandi" aria-pressed="false">' . auth_svg_icon('eye') . '</button></div>'
                . '<div class="pass-meter" data-pass-meter>'
                . '<div class="pass-bars"><span></span><span></span><span></span><span></span></div>'
                . '<div class="pass-meta"><span data-pass-label>Minimal 8 karakter</span><span data-pass-count>0/8 karakter</span></div>'
                . '</div></div>'
                . '<button type="submit" class="auth-submit">Kirim OTP Pendaftaran ke Email ' . auth_svg_icon('mail-check') . '</button>'
                . '</form>';
        }
    } else {
        $pendingReset = $_SESSION['otp_pending_reset'] ?? null;
        if ($step === 'verif' && is_array($pendingReset) && !empty($pendingReset['email'])) {
            $previewHint = !empty($pendingReset['preview_code'])
                ? '<p class="auth-preview-otp">Mode uji lokal · Kode OTP: <strong>' . h((string) $pendingReset['preview_code']) . '</strong></p>'
                : '';
            $formBody = '<div class="auth-pending-box"><span>Tujuan OTP: <strong>' . h((string) $pendingReset['email']) . '</strong></span>'
                . '<a href="index.php?p=masuk&tab=lupa&ubah=1">Ganti email</a></div>'
                . $previewHint
                . '<form method="post" action="index.php?p=lupa_verifikasi" class="auth-form">'
                . '<input type="hidden" name="csrf" value="' . $csrf . '">'
                . '<div class="auth-field"><label for="reset-otp-code">Kode OTP 6 Digit</label>'
                . '<input id="reset-otp-code" class="otp-input" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="000000" autocomplete="one-time-code" data-otp-input required autofocus></div>'
                . '<div class="auth-field"><label for="reset-pass">Kata sandi baru (minimal 8 karakter)</label>'
                . '<div class="input-wrap"><span class="input-ico">' . auth_svg_icon('lock') . '</span>'
                . '<input id="reset-pass" name="new_password" type="password" minlength="8" autocomplete="new-password" placeholder="Minimal 8 karakter" data-pass-input required>'
                . '<button type="button" class="pass-toggle" data-toggle-pass="reset-pass" aria-label="Tampilkan kata sandi" aria-pressed="false">' . auth_svg_icon('eye') . '</button></div>'
                . '<div class="pass-meter" data-pass-meter><div class="pass-bars"><span></span><span></span><span></span><span></span></div>'
                . '<div class="pass-meta"><span data-pass-label>Minimal 8 karakter</span><span data-pass-count>0/8 karakter</span></div></div></div>'
                . '<div class="auth-field"><label for="reset-pass-2">Konfirmasi kata sandi baru</label>'
                . '<div class="input-wrap"><span class="input-ico">' . auth_svg_icon('lock') . '</span>'
                . '<input id="reset-pass-2" name="confirm_password" type="password" minlength="8" autocomplete="new-password" placeholder="Ulangi kata sandi baru" required>'
                . '<button type="button" class="pass-toggle" data-toggle-pass="reset-pass-2" aria-label="Tampilkan kata sandi" aria-pressed="false">' . auth_svg_icon('eye') . '</button></div></div>'
                . '<button type="submit" class="auth-submit">Simpan Kata Sandi Baru &rarr;</button></form>'
                . '<form method="post" action="index.php?p=otp_kirim" class="auth-resend-form">'
                . '<input type="hidden" name="csrf" value="' . $csrf . '">'
                . '<input type="hidden" name="purpose" value="reset">'
                . '<input type="hidden" name="email" value="' . h((string) $pendingReset['email']) . '">'
                . '<button type="submit" class="btn-ghost-sm auth-resend-btn">Kirim Ulang OTP Reset</button></form>';
        } else {
            $formBody = '<form method="post" action="index.php?p=otp_kirim" class="auth-form">'
                . '<input type="hidden" name="csrf" value="' . $csrf . '">'
                . '<input type="hidden" name="purpose" value="reset">'
                . '<div class="auth-field"><label for="reset-email">Email terdaftar</label>'
                . '<div class="input-wrap"><span class="input-ico">' . auth_svg_icon('mail') . '</span>'
                . '<input id="reset-email" name="email" type="email" autocomplete="email" placeholder="nama@gmail.com" required></div></div>'
                . '<button type="submit" class="auth-submit">Kirim OTP Reset ke Email ' . auth_svg_icon('mail-check') . '</button>'
                . '</form>'
                . '<p class="auth-back-link"><a href="index.php?p=masuk&tab=login">&larr; Kembali ke halaman Masuk</a></p>';
        }
    }

    $html = '<div class="auth-split-wrap">'
        . '<section class="auth-hero" aria-label="Informasi Portal">'
        . '<div class="auth-hero-top">'
        . '<div class="auth-hero-brand">'
        . '<span class="auth-hero-logo"><img src="logo.svg" width="28" height="28" alt=""></span>'
        . '<span class="auth-hero-brand-txt"><strong>UNIVERSITAS INDRAMAYU</strong><small>PORTAL AKADEMIK</small></span>'
        . '</div>'
        . render_live_clock('live-clock-hero')
        . '</div>'
        . '<div class="auth-hero-copy">'
        . '<span class="auth-hero-pill">PORTAL PERKULIAHAN UNIRA</span>'
        . '<h2>Ruang terpadu untuk kelancaran studi dan pengajaran.</h2>'
        . '<p>Kelola jadwal kuliah, materi pertemuan, pengumpulan tugas, kehadiran kelas, hingga rekap nilai dalam satu sistem kampus.</p>'
        . '</div>'
        . '<p class="auth-hero-foot">&copy; 2026 Universitas Indramayu · Akses akun kampus</p>'
        . '</section>'
        . '<main class="auth-pane">'
        . '<div class="card auth-card auth-card-split">'
        . render_live_clock('live-clock-auth')
        . '<p class="auth-kicker">' . $kickerHtml . '</p>'
        . '<h1>' . h($headingText) . '</h1>'
        . '<p class="auth-sub">' . h($subText) . '</p>'
        . $tabsHtml
        . $err . $okAlert
        . $formBody
        . '</div>'
        . '</main>'
        . '</div>';

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
                . '<td data-label="Nama"><div class="user-inline">' . user_avatar_html($stu, 'avatar-xs') . '<strong>' . h($stu['name']) . '</strong></div></td>'
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

if ($page === 'profil') {
    $idLabel = $isDosen ? 'NIDN' : 'NIM';
    $roleBadge = $isDosen
        ? '<span class="role-badge role-badge-dosen">Dosen Pengampu</span>'
        : '<span class="role-badge role-badge-mhs">Mahasiswa</span>';
    $prodiLabel = $isDosen ? 'Program Studi / Bidang Keahlian' : 'Program Studi';

    $okCode = (string) ($_GET['ok'] ?? '');
    $errCode = (string) ($_GET['err'] ?? '');
    $okMap = [
        '1' => 'Perubahan profil dan foto profil berhasil disimpan.',
        'foto_dihapus' => 'Foto profil berhasil dihapus dan dikembalikan ke inisial.',
        'sandi' => 'Sandi akun berhasil diperbarui.',
    ];
    $errMap = [
        'nama' => 'Nama lengkap harus diisi (2–100 karakter).',
        'username' => 'Nama pengguna login minimal 3 karakter (maks. 60 karakter).',
        'username_dipakai' => 'Nama pengguna tersebut sudah dipakai akun lain.',
        'nim' => $idLabel . ' tidak boleh kosong (maks. 40 karakter).',
        'nim_dipakai' => $idLabel . ' tersebut sudah dipakai akun lain.',
        'email' => 'Format alamat email tidak valid.',
        'email_dipakai' => 'Alamat email tersebut sudah dipakai akun lain.',
        'foto' => 'Foto profil harus berupa gambar JPG, PNG, WebP, atau GIF (maks. 4 MB).',
        'sandi_lama' => 'Sandi saat ini tidak cocok.',
        'sandi_pendek' => 'Sandi baru minimal 6 karakter.',
        'sandi_beda' => 'Konfirmasi sandi baru tidak sama.',
    ];
    $alert = '';
    if (isset($okMap[$okCode])) {
        $alert = '<p class="okline">' . h($okMap[$okCode]) . '</p>';
    } elseif (isset($errMap[$errCode])) {
        $alert = '<p class="err">' . h($errMap[$errCode]) . '</p>';
    }

    $hasAvatar = user_avatar_url($user) !== null;
    $removePhotoForm = $hasAvatar
        ? '<form method="post" action="index.php?p=profil_hapus_foto" class="inline-form" data-confirm="Hapus foto profil saat ini?"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><button type="submit" class="btn-danger-sm">Hapus Foto</button></form>'
        : '';

    $prodiVal = trim((string) ($user['prodi'] ?? '')) !== '' ? (string) $user['prodi'] : 'Teknik Informatika';
    $bioText = trim((string) ($user['bio'] ?? ''));

    if ($isDosen) {
        $mhsCnt = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'mahasiswa'")->fetchColumn();
        $annCnt = (int) $pdo->query('SELECT COUNT(*) FROM announcements')->fetchColumn();
        $statSummary = '<div class="profile-stats-mini">'
            . '<div><span>Peran Akun</span><strong>Dosen Pengampu</strong></div>'
            . '<div><span>Mahasiswa Aktif</span><strong>' . $mhsCnt . '</strong></div>'
            . '<div><span>Pengumuman Terbit</span><strong>' . $annCnt . '</strong></div>'
            . '</div>';
    } else {
        $myStats = student_course_stats($pdo, (int) $user['id']);
        $subCntStmt = $pdo->prepare('SELECT COUNT(*) FROM submissions WHERE user_id = ?');
        $subCntStmt->execute([(int) $user['id']]);
        $subCnt = (int) $subCntStmt->fetchColumn();
        $statSummary = '<div class="profile-stats-mini">'
            . '<div><span>Peran Akun</span><strong>Mahasiswa</strong></div>'
            . '<div><span>Tugas Terkirim</span><strong>' . $subCnt . '</strong></div>'
            . '<div><span>IPS Sementara</span><strong>' . h($myStats['ips']) . '</strong></div>'
            . '</div>';
    }

    $html = '<p class="kicker">Akun · ' . h($isDosen ? 'Dosen Pengampu' : 'Mahasiswa') . '</p>';
    $html .= '<h1>Profil & Foto Akun.</h1>';
    $html .= '<p class="lede">Perbarui foto profil, identitas akademik, kontak, serta keamanan sandi akun Anda.</p>';
    $html .= $alert;

    $html .= '<section class="card profile-hero">'
        . '<div class="profile-hero-main">'
        . '<div class="profile-avatar-col">' . user_avatar_html($user, 'avatar-xl', true) . $removePhotoForm . '</div>'
        . '<div class="profile-hero-info">'
        . '<div class="profile-badges">' . $roleBadge . '<span class="badge-soft">' . h($prodiVal) . '</span></div>'
        . '<h2>' . h($user['name']) . '</h2>'
        . '<p class="muted">@' . h($user['username']) . ' · ' . $idLabel . ': <code>' . h($user['nim']) . '</code></p>'
        . ($bioText !== '' ? '<p class="profile-bio-preview">' . nl2br(h($bioText)) . '</p>' : '<p class="muted">Belum ada bio singkat. Tambahkan catatan profil Anda di formulir di bawah.</p>')
        . '</div>'
        . '</div>'
        . '</section>';

    $html .= '<div class="split section-gap">';

    $html .= '<section class="panel panel-accent">'
        . '<div class="panel-head"><h2>Edit Profil & Foto</h2><span class="panel-hint">' . h($isDosen ? 'Data Dosen' : 'Data Mahasiswa') . '</span></div>'
        . '<form method="post" action="index.php?p=profil_simpan" enctype="multipart/form-data" class="stack-form">'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
        . '<label>Foto Profil Baru (JPG, PNG, WebP, GIF · maks. 4 MB)<input type="file" name="avatar" accept="image/jpeg,image/png,image/webp,image/gif" data-avatar-input></label>'
        . '<p class="muted avatar-hint" data-avatar-note>' . ($hasAvatar ? 'Pilih file gambar baru jika ingin mengganti foto profil saat ini.' : 'Belum ada foto terpasang. Unggah foto untuk mengganti avatar inisial.') . '</p>'
        . '<label>Nama Lengkap<input name="name" value="' . h($user['name']) . '" maxlength="100" required></label>'
        . '<div class="form-row-2">'
        . '<label>Nama Pengguna (Login)<input name="username" value="' . h($user['username']) . '" minlength="3" maxlength="60" required></label>'
        . '<label>' . $idLabel . '<input name="nim" value="' . h($user['nim']) . '" maxlength="40" required></label>'
        . '</div>'
        . '<small class="muted field-note">Nama pengguna dipakai saat masuk ke portal akademik.</small>'
        . '<div class="form-row-2">'
        . '<label>' . $prodiLabel . '<input name="prodi" value="' . h($prodiVal) . '" maxlength="80" placeholder="Teknik Informatika"></label>'
        . '<label>No. HP / WhatsApp<input name="phone" value="' . h((string) ($user['phone'] ?? '')) . '" maxlength="30" placeholder="0812xxxxxxxx"></label>'
        . '</div>'
        . '<label>Alamat Email<input name="email" type="email" value="' . h((string) ($user['email'] ?? '')) . '" maxlength="120" placeholder="nama@unira.ac.id"></label>'
        . '<label>Bio / Deskripsi Singkat<textarea name="bio" rows="3" maxlength="400" placeholder="Ceritakan minat riset, kelas, atau catatan singkat Anda...">' . h((string) ($user['bio'] ?? '')) . '</textarea></label>'
        . '<button type="submit">Simpan Perubahan Profil</button>'
        . '</form></section>';

    $html .= '<section class="panel">'
        . '<div class="panel-head"><h2>Keamanan & Sandi</h2><span class="panel-hint">Akun Aktif</span></div>'
        . $statSummary
        . '<form method="post" action="index.php?p=profil_sandi" class="stack-form">'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
        . '<label>Sandi Saat Ini<input name="current_password" type="password" autocomplete="current-password" required></label>'
        . '<label>Sandi Baru (min. 6 karakter)<input name="new_password" type="password" minlength="6" autocomplete="new-password" required></label>'
        . '<label>Konfirmasi Sandi Baru<input name="confirm_password" type="password" minlength="6" autocomplete="new-password" required></label>'
        . '<button type="submit">Perbarui Sandi</button>'
        . '</form>'
        . '</section>';

    $html .= '</div>';

    layout('Profil Saya', $html, $user);
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
