<?php

// Скачивание и кэширование входящих вложений (напр. Green API downloadUrl) в /public/uploads/inbox.
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

function inbox_media_fetch(string $url): string
{
    $max = 20 * 1024 * 1024; // ≤20 МБ
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_MAXFILESIZE => $max,
        ]);
        $d = curl_exec($ch);
        curl_close($ch);
        return is_string($d) ? $d : '';
    }
    $ctx = stream_context_create(['http' => ['timeout' => 25], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $d = @file_get_contents($url, false, $ctx, 0, $max);
    return is_string($d) ? $d : '';
}

// [localUrl, mime] или ['',''] при неудаче.
function inbox_save_media(string $url, string $mime = '', string $fileName = ''): array
{
    if ($url === '') return ['', ''];
    $dir = PANEL_ROOT . '/public/uploads/inbox';
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
    catch (Exception $e) { $name = uniqid('m', true) . '.' . $ext; }

    if (@file_put_contents($dir . '/' . $name, $data) === false) return ['', ''];
    @chmod($dir . '/' . $name, 0644);
    return ['/uploads/inbox/' . $name, $mime];
}
