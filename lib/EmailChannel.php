<?php

// Email-канал через smtp.bz (тот же API, что в tmp_email_sender/email_sender.php).

class EmailChannel
{
    private $config;

    public function __construct($config)
    {
        $this->config = array_merge(array(
            'api_key' => '',
            'from' => '',
            'name' => 'Интерсити Тур',
            'reply' => '',
        ), (array) $config);
    }

    public function key() { return 'email'; }
    public function label() { return 'Email (smtp.bz)'; }

    public function isConfigured()
    {
        return $this->config['api_key'] !== ''
            && filter_var($this->config['from'], FILTER_VALIDATE_EMAIL) !== false;
    }

    public function statusText()
    {
        return $this->isConfigured()
            ? 'Настроен, отправитель: ' . $this->config['from']
            : 'Не настроен: заполните smtp_bz в config.local.php';
    }

    public function validRecipient($passenger)
    {
        return isset($passenger['email']) && filter_var($passenger['email'], FILTER_VALIDATE_EMAIL)
            ? $passenger['email']
            : '';
    }

    // $meta: array('subject' => ..., 'to_name' => ...)
    public function send($recipient, $body, $meta = array())
    {
        if (!$this->isConfigured()) {
            return array('ok' => false, 'error' => 'Email-канал не настроен (config.local.php).');
        }
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return array('ok' => false, 'error' => 'Некорректный email: ' . $recipient);
        }

        $html = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
        $payload = array(
            'subject' => isset($meta['subject']) && $meta['subject'] !== '' ? $meta['subject'] : 'Уведомление о рейсе',
            'name' => $this->config['name'],
            'from' => $this->config['from'],
            'to' => $recipient,
            'html' => $html,
            'text' => $body,
        );
        if (!empty($meta['to_name'])) {
            $payload['to_name'] = $meta['to_name'];
        }
        if ($this->config['reply'] !== '') {
            $payload['reply'] = $this->config['reply'];
        }

        $ch = curl_init('https://api.smtp.bz/v1/smtp/send');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: ' . $this->config['api_key']));

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            return array('ok' => false, 'error' => 'cURL: ' . $error);
        }

        if ($code < 200 || $code >= 300) {
            return array('ok' => false, 'error' => 'HTTP ' . $code . ': ' . substr((string) $raw, 0, 300));
        }

        return array('ok' => true, 'data' => json_decode((string) $raw, true));
    }
}
