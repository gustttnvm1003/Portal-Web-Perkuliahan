<?php
declare(strict_types=1);

$uriPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$cleanPath = '/' . ltrim(str_replace('\\', '/', $uriPath), '/');

if ($cleanPath === '/health') {
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ok';
    return true;
}

// Blokir akses langsung ke folder data, dotfile, dan berkas internal/konfigurasi.
if (
    preg_match('#(^|/)\.#', $cleanPath)
    || preg_match('#^/data(/|$)#i', $cleanPath)
    || preg_match('#\.(sqlite|sqlite3|db|log|md|json|lock)$#i', $cleanPath)
    || in_array(strtolower($cleanPath), ['/dockerfile', '/router.php', '/lib.php', '/seed_data.php', '/dosen_pages.php', '/mailer.php'], true)
) {
    if ($cleanPath !== '/manifest.webmanifest') {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Akses ditolak.';
        return true;
    }
}

$file = __DIR__ . $cleanPath;
if ($cleanPath !== '/' && is_file($file)) {
    if (str_ends_with(strtolower($cleanPath), '.php') && strtolower($cleanPath) !== '/index.php') {
        http_response_code(403);
        echo 'Akses ditolak.';
        return true;
    }
    return false;
}

require __DIR__ . '/index.php';
return true;

