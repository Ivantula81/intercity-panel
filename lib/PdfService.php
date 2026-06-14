<?php

// Генерация PDF из HTML через Gotenberg (Chromium) — пиксель-в-пиксель как печать из браузера.

class PdfService
{
    public static function htmlToPdf(string $html, string $orientation = 'portrait'): ?string
    {
        $url = (getenv('GOTENBERG_URL') ?: 'http://127.0.0.1:3001') . '/forms/chromium/convert/html';
        $landscape = $orientation === 'landscape' ? 'true' : 'false';

        $tmp = tempnam(sys_get_temp_dir(), 'doc') . '.html';
        file_put_contents($tmp, $html);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'files' => new CURLFile($tmp, 'text/html', 'index.html'),
            'landscape' => $landscape,
            'marginTop' => '0.4', 'marginBottom' => '0.4',
            'marginLeft' => '0.4', 'marginRight' => '0.4',
            'printBackground' => 'true',
        ]);
        $pdf = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        @unlink($tmp);

        return ($code === 200 && $pdf !== false && $pdf !== '') ? $pdf : null;
    }
}
