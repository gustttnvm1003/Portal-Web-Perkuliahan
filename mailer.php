<?php
declare(strict_types=1);

if (!defined('SERAMBI_APP')) {
    http_response_code(403);
    exit;
}

/**
 * Mengecek apakah layanan kirim email nyata (Resend API atau SMTP) sudah dikonfigurasi.
 */
function mail_transport_configured(): bool
{
    $resendKey = trim((string) getenv('RESEND_API_KEY'));
    if ($resendKey !== '') {
        return true;
    }
    $host = trim((string) getenv('SMTP_HOST'));
    $user = trim((string) getenv('SMTP_USER'));
    $pass = (string) getenv('SMTP_PASS');
    return $host !== '' && $user !== '' && $pass !== '';
}

/**
 * Mengirim kode OTP ke email tujuan menggunakan Resend API atau SMTP.
 *
 * @return array{ok:bool,provider:string,error:string}
 */
function send_otp_email(string $toEmail, string $toName, string $code, string $purposeLabel): array
{
    $subject = 'Kode OTP ' . $purposeLabel . ' · Portal Akademik UNIRA';
    $safeName = htmlspecialchars($toName !== '' ? $toName : 'Pengguna', ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $safePurpose = htmlspecialchars($purposeLabel, ENT_QUOTES, 'UTF-8');

    $textBody = "Halo {$toName},\n\n"
        . "Berikut kode OTP untuk {$purposeLabel} di Portal Akademik Universitas Indramayu:\n\n"
        . "Kode OTP: {$code}\n\n"
        . "Kode ini berlaku selama 10 menit dan bersifat rahasia. Jangan bagikan kode ini kepada siapa pun.\n\n"
        . "Jika Anda tidak merasa meminta kode ini, abaikan email ini.\n";

    $htmlBody = '<!doctype html><html><body style="margin:0;padding:24px;background:#f4f0fb;font-family:Segoe UI,Roboto,Arial,sans-serif;color:#1a1228;">'
        . '<div style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:18px;padding:28px;border:1px solid #e4dcf3;">'
        . '<p style="margin:0 0 6px;font-size:12px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#6d4aff;">Universitas Indramayu</p>'
        . '<h2 style="margin:0 0 12px;font-size:20px;color:#1a1228;">Kode Verifikasi OTP</h2>'
        . '<p style="margin:0 0 16px;font-size:14px;line-height:1.55;color:#5c5470;">Halo <strong>' . $safeName . '</strong>, gunakan kode OTP berikut untuk <strong>' . $safePurpose . '</strong>:</p>'
        . '<div style="margin:18px 0;padding:16px;border-radius:14px;background:#f3edff;border:1px dashed #6d4aff;text-align:center;">'
        . '<span style="font-size:32px;font-weight:800;letter-spacing:8px;color:#4c1d95;font-family:Consolas,monospace;">' . $safeCode . '</span>'
        . '</div>'
        . '<p style="margin:0;font-size:13px;line-height:1.5;color:#5c5470;">Kode berlaku selama <strong>10 menit</strong>. Demi keamanan akun, jangan bagikan kode ini kepada siapa pun.</p>'
        . '</div></body></html>';

    $resendKey = trim((string) getenv('RESEND_API_KEY'));
    if ($resendKey !== '') {
        $res = resend_send_mail($resendKey, $toEmail, $subject, $htmlBody, $textBody);
    } elseif (mail_transport_configured()) {
        $res = smtp_send_mail($toEmail, $toName, $subject, $htmlBody, $textBody);
    } else {
        $res = ['ok' => false, 'provider' => 'none', 'error' => 'Transport email belum dikonfigurasi.'];
    }

    if (!$res['ok']) {
        @error_log('[serambi-mail] ' . $res['provider'] . ': ' . $res['error']);
    }
    return $res;
}

function resend_send_mail(string $apiKey, string $toEmail, string $subject, string $htmlBody, string $textBody): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'provider' => 'resend', 'error' => 'Ekstensi cURL PHP tidak tersedia.'];
    }
    $from = trim((string) getenv('RESEND_FROM')) ?: 'Portal Akademik UNIRA <onboarding@resend.dev>';
    $payload = json_encode([
        'from' => $from,
        'to' => [$toEmail],
        'subject' => $subject,
        'html' => $htmlBody,
        'text' => $textBody,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $code < 200 || $code >= 300) {
        return ['ok' => false, 'provider' => 'resend', 'error' => $err ?: ('Resend HTTP ' . $code . ': ' . (string) $resp)];
    }
    return ['ok' => true, 'provider' => 'resend', 'error' => ''];
}

function smtp_read_response($fp): array
{
    $lines = '';
    $code = 0;
    while (!feof($fp)) {
        $line = fgets($fp, 1024);
        if ($line === false) {
            break;
        }
        $lines .= $line;
        if (preg_match('/^(\d{3})([\s-])/', $line, $m)) {
            $code = (int) $m[1];
            if ($m[2] === ' ') {
                break;
            }
        } else {
            break;
        }
    }
    return [$code, trim($lines)];
}

function smtp_command($fp, string $cmd, array $expectCodes): array
{
    fwrite($fp, $cmd . "\r\n");
    [$code, $msg] = smtp_read_response($fp);
    if (!in_array($code, $expectCodes, true)) {
        return [false, "SMTP {$code}: {$msg}"];
    }
    return [true, $msg];
}

function smtp_send_mail(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody): array
{
    $host = trim((string) getenv('SMTP_HOST'));
    $port = (int) (getenv('SMTP_PORT') ?: 465);
    $user = trim((string) getenv('SMTP_USER'));
    $pass = (string) getenv('SMTP_PASS');
    $fromEmail = trim((string) getenv('SMTP_FROM')) ?: $user;
    $fromName = trim((string) getenv('SMTP_FROM_NAME')) ?: 'Portal Akademik UNIRA';
    $secure = strtolower(trim((string) getenv('SMTP_SECURE')));
    if ($secure === '') {
        $secure = $port === 465 ? 'ssl' : 'tls';
    }

    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($remote, $errno, $errstr, 12, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        return ['ok' => false, 'provider' => 'smtp', 'error' => "Gagal koneksi SMTP ({$errno}): {$errstr}"];
    }
    stream_set_timeout($fp, 12);

    try {
        [$greetCode, $greetMsg] = smtp_read_response($fp);
        if ($greetCode !== 220) {
            return ['ok' => false, 'provider' => 'smtp', 'error' => "SMTP greeting {$greetCode}: {$greetMsg}"];
        }

        $ehloHost = preg_replace('/[^a-zA-Z0-9.-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
        [$ok, $msg] = smtp_command($fp, "EHLO {$ehloHost}", [250]);
        if (!$ok) {
            return ['ok' => false, 'provider' => 'smtp', 'error' => $msg];
        }

        if ($secure === 'tls') {
            [$ok, $msg] = smtp_command($fp, 'STARTTLS', [220]);
            if (!$ok) {
                return ['ok' => false, 'provider' => 'smtp', 'error' => $msg];
            }
            $crypto = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
            if ($crypto !== true) {
                return ['ok' => false, 'provider' => 'smtp', 'error' => 'Gagal memulai enkripsi STARTTLS.'];
            }
            [$ok, $msg] = smtp_command($fp, "EHLO {$ehloHost}", [250]);
            if (!$ok) {
                return ['ok' => false, 'provider' => 'smtp', 'error' => $msg];
            }
        }

        [$ok, $msg] = smtp_command($fp, 'AUTH LOGIN', [334]);
        if (!$ok) {
            return ['ok' => false, 'provider' => 'smtp', 'error' => $msg];
        }
        [$ok, $msg] = smtp_command($fp, base64_encode($user), [334]);
        if (!$ok) {
            return ['ok' => false, 'provider' => 'smtp', 'error' => $msg];
        }
        [$ok, $msg] = smtp_command($fp, base64_encode($pass), [235]);
        if (!$ok) {
            return ['ok' => false, 'provider' => 'smtp', 'error' => $msg];
        }

        [$ok, $msg] = smtp_command($fp, "MAIL FROM:<{$fromEmail}>", [250]);
        if (!$ok) {
            return ['ok' => false, 'provider' => 'smtp', 'error' => $msg];
        }
        [$ok, $msg] = smtp_command($fp, "RCPT TO:<{$toEmail}>", [250, 251]);
        if (!$ok) {
            return ['ok' => false, 'provider' => 'smtp', 'error' => $msg];
        }
        [$ok, $msg] = smtp_command($fp, 'DATA', [354]);
        if (!$ok) {
            return ['ok' => false, 'provider' => 'smtp', 'error' => $msg];
        }

        $boundary = 'serambi_' . bin2hex(random_bytes(8));
        $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $safeToName = str_replace(["\r", "\n"], '', $toName);
        $toHeader = $safeToName !== '' ? ('=?UTF-8?B?' . base64_encode($safeToName) . '?= <' . $toEmail . '>') : $toEmail;

        $mime = "From: {$encFromName} <{$fromEmail}>\r\n"
            . "To: {$toHeader}\r\n"
            . "Subject: {$encSubject}\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Date: " . date(DATE_RFC2822) . "\r\n"
            . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($textBody), 76, "\r\n") . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($htmlBody), 76, "\r\n") . "\r\n"
            . "--{$boundary}--\r\n";

        $stuffed = preg_replace('/^\./m', '..', $mime);
        fwrite($fp, $stuffed . "\r\n.\r\n");
        [$dataCode, $dataMsg] = smtp_read_response($fp);
        if ($dataCode !== 250) {
            return ['ok' => false, 'provider' => 'smtp', 'error' => "SMTP DATA {$dataCode}: {$dataMsg}"];
        }
        @fwrite($fp, "QUIT\r\n");
        return ['ok' => true, 'provider' => 'smtp', 'error' => ''];
    } finally {
        @fclose($fp);
    }
}
