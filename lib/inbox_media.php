<?php

// Скачивание и кэширование входящих вложений вне docroot.
// Возвращаем локальный URL — чтобы лента чата не зависела от живущих недолго внешних ссылок.

function inbox_media_label(string $mime, string $fileName = ''): string
{
    if (str_starts_with($mime, 'image/')) return '📷 Фото';
    if (str_starts_with($mime, 'video/')) return '🎬 Видео';
    if (str_starts_with($mime, 'audio/')) return '🎤 Голосовое';
    return '📎 ' . ($fileName !== '' ? $fileName : 'Файл');
}

function inbox_media_ext(string $mime, string $fileName): string
{
    if ($fileName !== '' && preg_match('/\.([a-z0-9]{1,5})$/i', $fileName, $m)) {
        return strtolower($m[1]);
    }
    $map = [
        'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'image/gif' => 'gif', 'image/heic' => 'heic', 'application/pdf' => 'pdf',
        'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a',
        'video/mp4' => 'mp4', 'video/quicktime' => 'mov',
    ];
    return $map[$mime] ?? 'bin';
}

function inbox_media_storage_dir(): string
{
    $configured = getenv('INBOX_MEDIA_DIR') ?: '';
    if ($configured === '' && is_readable('/etc/panel.env')) {
        foreach (file('/etc/panel.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with($line, 'INBOX_MEDIA_DIR=')) {
                $configured = substr($line, strlen('INBOX_MEDIA_DIR='));
                break;
            }
        }
    }
    return rtrim($configured !== '' ? $configured : PANEL_ROOT . '/storage/inbox', '/');
}

// Защита от SSRF: только https и публичный (не внутренний) адрес назначения.
function inbox_media_url_safe(string $url): bool
{
    $p = parse_url($url);
    if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])) return false;
    $host = $p['host'];
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (@gethostbynamel($host) ?: []);
    if (!$ips) return false;
    foreach ($ips as $ip) {
        // отсекаем приватные/зарезервированные диапазоны (127.*, 10.*, 169.254.*, ::1 и т.п.)
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    }
    return true;
}

function inbox_media_fetch(string $url): string
{
    if (!inbox_media_url_safe($url)) return '';
    $max = 20 * 1024 * 1024; // ≤20 МБ
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,        // только https
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,  // и редиректы только на https
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,              // проверяем TLS-сертификат
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_MAXFILESIZE => $max,
        ]);
        $d = curl_exec($ch);
        curl_close($ch);
        return is_string($d) ? $d : '';
    }
    $ctx = stream_context_create(['http' => ['timeout' => 25, 'follow_location' => 1, 'max_redirects' => 3]]);
    $d = @file_get_contents($url, false, $ctx, 0, $max);
    return is_string($d) ? $d : '';
}

// [localUrl, mime] или ['',''] при неудаче.
function inbox_save_media(string $url, string $mime = '', string $fileName = ''): array
{
    if ($url === '') return ['', ''];
    $dir = inbox_media_storage_dir();
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!is_dir($dir) || !is_writable($dir)) return ['', ''];

    $data = inbox_media_fetch($url);
    if ($data === '' || strlen($data) > 20 * 1024 * 1024) return ['', ''];

    if ($mime === '' && function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) { $mime = (string) finfo_buffer($fi, $data); finfo_close($fi); }
    }
    $ext = inbox_media_ext($mime, $fileName);
    try { $name = bin2hex(random_bytes(8)) . '.' . $ext; }
    catch (Exception $e) { $name = 'm' . bin2hex((string) microtime(true)) . '.' . $ext; }

    if (@file_put_contents($dir . '/' . $name, $data) === false) return ['', ''];
    @chmod($dir . '/' . $name, 0644);
    // Выдача только через авторизованный контроллер, без прямого URL из docroot.
    return ['/?p=media&f=' . rawurlencode($name), $mime];
}
