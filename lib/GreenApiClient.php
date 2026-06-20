<?php

// Клиент Green API (WhatsApp/MAX). Интерфейс совместим с EvolutionApiClient:
// isConfigured / connectionState / instanceInfo / sendText / sendImage.

class GreenApiClient
{
    private $base;
    private $id;
    private $token;

    public function __construct($base, $id, $token)
    {
        $this->base = rtrim((string) $base, '/');
        $this->id = trim((string) $id);
        $this->token = trim((string) $token);
    }

    public function isConfigured()
    {
        return $this->base !== '' && $this->id !== '' && $this->token !== '';
    }

    private function url($method)
    {
        return $this->base . '/waInstance' . $this->id . '/' . $method . '/' . $this->token;
    }

    // Принимает телефон (→ WhatsApp-формат phone@c.us) или готовый chatId.
    // Короткий числовой id (MAX user_id) и любое значение с '@' используются как есть.
    private function chatId($v)
    {
        $v = (string) $v;
        if (str_contains($v, '@')) return $v;                 // уже chatId (c.us / g.us / max)
        $digits = preg_replace('/\D+/', '', $v);
        if (str_starts_with($v, '+') || strlen($digits) >= 10) return $digits . '@c.us'; // телефон → WhatsApp
        return $v;                                            // короткий id (напр. MAX) — как есть
    }

    // Состояние: authorized → open, прочее → close/connecting
    public function connectionState()
    {
        $r = $this->get('getStateInstance');
        if (!$r['ok']) return $r;
        $state = $r['data']['stateInstance'] ?? '';
        $map = ['authorized' => 'open', 'notAuthorized' => 'close', 'starting' => 'connecting', 'yellowCard' => 'connecting', 'blocked' => 'close'];
        return ['ok' => true, 'state' => $map[$state] ?? $state];
    }

    public function instanceInfo()
    {
        $st = $this->connectionState();
        $settings = $this->get('getSettings');
        $wid = $settings['ok'] ? ($settings['data']['wid'] ?? '') : '';
        $number = $wid !== '' ? '+' . preg_replace('/\D+/', '', explode('@', $wid)[0]) : '';
        return [
            'ok' => true,
            'state' => $st['ok'] ? $st['state'] : 'error',
            'number' => $number,
            'name' => '',
            'avatar' => '',
            'messages' => 0,
            'contacts' => 0,
        ];
    }

    public function sendText($phone, $text)
    {
        $r = $this->post('sendMessage', ['chatId' => $this->chatId($phone), 'message' => (string) $text]);
        return $this->mapSend($r);
    }

    public function sendImage($phone, $filePath, $caption = '')
    {
        if (!is_file($filePath)) return ['ok' => false, 'error' => 'Файл вложения не найден.'];
        $ch = curl_init($this->url('sendFileByUpload'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 40);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'chatId' => $this->chatId($phone),
            'caption' => (string) $caption,
            'file' => new CURLFile($filePath, mime_content_type($filePath) ?: 'application/octet-stream', basename($filePath)),
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) return ['ok' => false, 'error' => 'cURL: ' . $err];
        return $this->mapSend(['ok' => $code >= 200 && $code < 300, 'data' => json_decode($raw, true), 'code' => $code, 'raw' => $raw]);
    }

    // Документ (PDF) — для отправки ведомостей водителю
    public function sendDocument($phone, $filePath, $caption = '')
    {
        return $this->sendImage($phone, $filePath, $caption); // sendFileByUpload одинаков для любых файлов
    }

    // Настроить webhook на нашу панель
    public function configureWebhook($webhookUrl, $webhookToken)
    {
        return $this->post('setSettings', [
            'webhookUrl' => (string) $webhookUrl,
            'webhookUrlToken' => (string) $webhookToken,
            'incomingWebhook' => 'yes',
            'outgoingMessageWebhook' => 'yes',
            'outgoingAPIMessageWebhook' => 'yes',
            'stateWebhook' => 'yes',
            'delaySendMessagesMilliseconds' => 2000,
        ]);
    }

    // Проверка наличия аккаунта в мессенджере (MAX/Telegram). ['ok'=>bool, 'exists'=>bool, 'chatId'=>string]
    public function checkAccount($phone)
    {
        $number = preg_replace('/\D+/', '', (string) $phone);
        if ($number === '') return ['ok' => false, 'error' => 'Пустой номер.'];
        $r = $this->post('checkAccount', ['phoneNumber' => (int) $number]);
        if (!$r['ok']) return ['ok' => false, 'error' => 'Green API: HTTP ' . ($r['code'] ?? '?')];
        $d = is_array($r['data'] ?? null) ? $r['data'] : [];
        $exists = $d['exist'] ?? ($d['existsWhatsapp'] ?? null);
        return ['ok' => true, 'exists' => (bool) $exists, 'chatId' => (string) ($d['chatId'] ?? '')];
    }

    private function mapSend($r)
    {
        if (!$r['ok']) {
            $msg = is_array($r['data'] ?? null) ? json_encode($r['data'], JSON_UNESCAPED_UNICODE) : (string) ($r['raw'] ?? $r['error'] ?? 'ошибка');
            return ['ok' => false, 'error' => 'Green API: ' . substr($msg, 0, 200)];
        }
        $idMessage = $r['data']['idMessage'] ?? '';
        return ['ok' => true, 'data' => ['key' => ['id' => $idMessage]]];
    }

    private function get($method)
    {
        if (!$this->isConfigured()) return ['ok' => false, 'error' => 'Green API не настроен.'];
        $ch = curl_init($this->url($method));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $code < 200 || $code >= 300) return ['ok' => false, 'error' => 'HTTP ' . $code];
        return ['ok' => true, 'data' => json_decode($raw, true)];
    }

    private function post($method, $body)
    {
        if (!$this->isConfigured()) return ['ok' => false, 'error' => 'Green API не настроен.'];
        $ch = curl_init($this->url($method));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) return ['ok' => false, 'error' => 'cURL: ' . $err];
        return ['ok' => $code >= 200 && $code < 300, 'data' => json_decode($raw, true), 'code' => $code, 'raw' => $raw];
    }
}
