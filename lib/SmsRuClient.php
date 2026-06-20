<?php

// Клиент SMS.RU. Интерфейс совместим с другими каналами: isConfigured / sendText / status.
// Ключ api_id берётся из /etc/panel.env (SMSRU_API_ID). Буквенный отправитель (from) — опционально,
// должен быть согласован у операторов; без него уходит от общего отправителя.

class SmsRuClient
{
    private $apiId;
    private $from;

    public function __construct($apiId, $from = '')
    {
        $this->apiId = trim((string) $apiId);
        $this->from = trim((string) $from);
    }

    public function isConfigured()
    {
        return $this->apiId !== '';
    }

    // Отправка SMS. Возвращает ['ok'=>bool, 'data'=>['key'=>['id'=>sms_id]], 'error'=>...] — единый вид со всеми каналами.
    public function sendText($phone, $text)
    {
        if (!$this->isConfigured()) return ['ok' => false, 'error' => 'SMS.RU не настроен.'];
        $number = preg_replace('/\D+/', '', (string) $phone);
        if ($number === '') return ['ok' => false, 'error' => 'Пустой номер.'];

        $params = ['api_id' => $this->apiId, 'to' => $number, 'msg' => (string) $text, 'json' => 1];
        if ($this->from !== '') $params['from'] = $this->from;

        $r = $this->get('sms/send', $params);
        if (!$r['ok']) return ['ok' => false, 'error' => $r['error']];
        $d = $r['data'];
        if (($d['status'] ?? '') !== 'OK') {
            return ['ok' => false, 'error' => 'SMS.RU: ' . self::codeText((int) ($d['status_code'] ?? 0))];
        }
        $info = $d['sms'][$number] ?? (is_array($d['sms'] ?? null) ? reset($d['sms']) : []);
        if (($info['status'] ?? '') !== 'OK') {
            return ['ok' => false, 'error' => 'SMS.RU: ' . self::codeText((int) ($info['status_code'] ?? 0))];
        }
        return ['ok' => true, 'data' => ['key' => ['id' => (string) ($info['sms_id'] ?? '')]], 'balance' => $d['balance'] ?? null];
    }

    // Статус по sms_id: ['ok'=>bool, 'code'=>int, 'delivered'=>bool, 'failed'=>bool]
    public function status($smsId)
    {
        if (!$this->isConfigured()) return ['ok' => false, 'error' => 'SMS.RU не настроен.'];
        $r = $this->get('sms/status', ['api_id' => $this->apiId, 'sms_id' => (string) $smsId, 'json' => 1]);
        if (!$r['ok']) return ['ok' => false, 'error' => $r['error']];
        $info = $r['data']['sms'][(string) $smsId] ?? null;
        if (!$info) return ['ok' => false, 'error' => 'нет данных по sms_id'];
        $code = (int) ($info['status_code'] ?? 0);
        return [
            'ok' => true,
            'code' => $code,
            'delivered' => $code === 103,
            'failed' => in_array($code, [104, 105, 106, 107, 108, 150], true),
        ];
    }

    // Баланс счёта SMS.RU
    public function balance()
    {
        if (!$this->isConfigured()) return ['ok' => false, 'error' => 'SMS.RU не настроен.'];
        $r = $this->get('my/balance', ['api_id' => $this->apiId, 'json' => 1]);
        if (!$r['ok']) return $r;
        return ['ok' => true, 'balance' => $r['data']['balance'] ?? null];
    }

    private static function codeText($code)
    {
        $m = [
            200 => 'неверный api_id', 201 => 'недостаточно средств', 202 => 'неверный номер',
            203 => 'нет текста сообщения', 204 => 'отправитель не согласован', 205 => 'слишком длинное',
            206 => 'превышен дневной лимит', 207 => 'нельзя слать на этот номер', 209 => 'номер в стоп-листе',
            210 => 'нужен POST', 211 => 'метод не найден', 220 => 'сервис недоступен, попробуйте позже',
            230 => 'превышен лимит на номер', 300 => 'неверный токен', 301 => 'неверный пароль',
        ];
        return $m[$code] ?? ('код ' . $code);
    }

    private function get($method, $params)
    {
        $ch = curl_init('https://sms.ru/' . $method . '?' . http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) return ['ok' => false, 'error' => 'cURL: ' . $err];
        if ($code < 200 || $code >= 300) return ['ok' => false, 'error' => 'HTTP ' . $code];
        $data = json_decode($raw, true);
        if (!is_array($data)) return ['ok' => false, 'error' => 'плохой ответ SMS.RU'];
        return ['ok' => true, 'data' => $data];
    }
}
