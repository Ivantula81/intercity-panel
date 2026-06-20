<?php

// REST-клиент Evolution API (WhatsApp). Паттерн повторяет max-bot/MaxBotClient.php.

class EvolutionApiClient
{
    private $baseUrl;
    private $instance;
    private $apiKey;

    public function __construct($baseUrl, $instance, $apiKey)
    {
        $this->baseUrl = rtrim((string) $baseUrl, '/');
        $this->instance = trim((string) $instance);
        $this->apiKey = trim((string) $apiKey);
    }

    public function isConfigured()
    {
        return $this->baseUrl !== '' && $this->instance !== '' && $this->apiKey !== '';
    }

    // Состояние подключения номера: array('ok'=>bool, 'state'=>'open|connecting|close', ...)
    public function connectionState()
    {
        $response = $this->request('GET', '/instance/connectionState/' . rawurlencode($this->instance));
        if (!$response['ok']) {
            return $response;
        }

        $state = isset($response['data']['instance']['state']) ? $response['data']['instance']['state'] : '';
        return array('ok' => true, 'state' => $state);
    }

    // Данные подключённого номера: array('ok'=>true, 'state'=>, 'number'=>, 'name'=>, 'avatar'=>, 'messages'=>, 'contacts'=>)
    public function instanceInfo()
    {
        $response = $this->request('GET', '/instance/fetchInstances?instanceName=' . rawurlencode($this->instance));
        if (!$response['ok']) {
            return $response;
        }

        $list = $response['data'];
        $inst = null;
        if (is_array($list)) {
            // ответ может быть массивом инстансов или объектом — ищем нужный по name
            foreach ($list as $item) {
                if (is_array($item) && isset($item['name']) && $item['name'] === $this->instance) {
                    $inst = $item;
                    break;
                }
            }
            if ($inst === null && isset($list['name'])) {
                $inst = $list;
            }
        }
        if ($inst === null) {
            return array('ok' => true, 'state' => 'close', 'number' => '', 'name' => '', 'avatar' => '');
        }

        $jid = isset($inst['ownerJid']) ? (string) $inst['ownerJid'] : '';
        $number = $jid !== '' ? '+' . preg_replace('/\D+/', '', explode('@', $jid)[0]) : '';
        $count = isset($inst['_count']) && is_array($inst['_count']) ? $inst['_count'] : array();

        return array(
            'ok' => true,
            'state' => isset($inst['connectionStatus']) ? $inst['connectionStatus'] : '',
            'number' => $number,
            'name' => isset($inst['profileName']) ? (string) $inst['profileName'] : '',
            'avatar' => isset($inst['profilePicUrl']) ? (string) $inst['profilePicUrl'] : '',
            'messages' => isset($count['Message']) ? (int) $count['Message'] : 0,
            'contacts' => isset($count['Contact']) ? (int) $count['Contact'] : 0,
        );
    }

    // QR-код для привязки номера: array('ok'=>true, 'data'=>['base64'=>'data:image/png...', ...])
    public function connectQr()
    {
        return $this->request('GET', '/instance/connect/' . rawurlencode($this->instance));
    }

    // Отключить текущий номер (logout устройства WhatsApp). После этого можно привязать новый по QR.
    public function logout()
    {
        return $this->request('DELETE', '/instance/logout/' . rawurlencode($this->instance));
    }

    // Создать новый инстанс (аккаунт WhatsApp) с заданным именем.
    public function createInstance($name)
    {
        return $this->request('POST', '/instance/create', array(
            'instanceName' => (string) $name,
            'integration' => 'WHATSAPP-BAILEYS',
            'qrcode' => true,
        ));
    }

    // Полностью удалить инстанс с сервера.
    public function deleteInstance()
    {
        return $this->request('DELETE', '/instance/delete/' . rawurlencode($this->instance));
    }

    // Список всех инстансов (для управления аккаунтами).
    public function listInstances()
    {
        return $this->request('GET', '/instance/fetchInstances');
    }

    // Проверка наличия номеров в WhatsApp. Возвращает ['ok'=>bool, 'exists'=>[number=>bool]].
    public function checkNumbers(array $phones)
    {
        $nums = array();
        foreach ($phones as $p) {
            $n = preg_replace('/\D+/', '', (string) $p);
            if ($n !== '') $nums[] = $n;
        }
        if (!$nums) return array('ok' => true, 'exists' => array());
        $resp = $this->request('POST', '/chat/whatsappNumbers/' . rawurlencode($this->instance), array('numbers' => array_values(array_unique($nums))));
        if (!$resp['ok']) return array('ok' => false, 'error' => isset($resp['error']) ? $resp['error'] : 'ошибка проверки номеров');
        $out = array();
        foreach ((array) $resp['data'] as $row) {
            $num = preg_replace('/\D+/', '', (string) (isset($row['number']) ? $row['number'] : ''));
            if ($num !== '') $out[$num] = !empty($row['exists']);
        }
        return array('ok' => true, 'exists' => $out);
    }

    // Настроить webhook инстанса на наш приёмник.
    public function setWebhook($url, $events)
    {
        return $this->request('POST', '/webhook/set/' . rawurlencode($this->instance), array(
            'webhook' => array(
                'enabled' => true,
                'url' => (string) $url,
                'byEvents' => false,
                'base64' => false,
                'events' => $events,
            ),
        ));
    }

    // Отправка текста: телефон в формате +7XXXXXXXXXX или 7XXXXXXXXXX
    public function sendText($phone, $text)
    {
        $number = preg_replace('/\D+/', '', (string) $phone);
        if ($number === '') {
            return array('ok' => false, 'error' => 'Пустой номер телефона.');
        }

        return $this->request('POST', '/message/sendText/' . rawurlencode($this->instance), array(
            'number' => $number,
            'text' => (string) $text,
            'linkPreview' => false, // не разворачивать превью ссылок (карты и т.п.)
        ));
    }

    // Отправка изображения с подписью. $filePath — локальный путь к картинке.
    public function sendImage($phone, $filePath, $caption = '')
    {
        $number = preg_replace('/\D+/', '', (string) $phone);
        if ($number === '') {
            return array('ok' => false, 'error' => 'Пустой номер телефона.');
        }
        if (!is_file($filePath)) {
            return array('ok' => false, 'error' => 'Файл вложения не найден.');
        }

        $mime = function_exists('mime_content_type') ? mime_content_type($filePath) : 'image/jpeg';

        return $this->request('POST', '/message/sendMedia/' . rawurlencode($this->instance), array(
            'number' => $number,
            'mediatype' => 'image',
            'mimetype' => $mime,
            'fileName' => basename($filePath),
            'media' => base64_encode(file_get_contents($filePath)),
            'caption' => (string) $caption,
        ));
    }

    private function request($method, $path, $body = null)
    {
        if (!$this->isConfigured()) {
            return array('ok' => false, 'error' => 'Evolution API не настроен (config.local.php).');
        }

        $ch = curl_init($this->baseUrl . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'apikey: ' . $this->apiKey,
        ));

        if ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        } elseif ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            return array('ok' => false, 'error' => 'cURL: ' . $error);
        }

        $data = json_decode((string) $raw, true);

        if ($code < 200 || $code >= 300) {
            $message = '';
            if (is_array($data)) {
                if (isset($data['response']['message'])) {
                    $message = is_array($data['response']['message'])
                        ? json_encode($data['response']['message'], JSON_UNESCAPED_UNICODE)
                        : (string) $data['response']['message'];
                } elseif (isset($data['message'])) {
                    $message = is_array($data['message'])
                        ? json_encode($data['message'], JSON_UNESCAPED_UNICODE)
                        : (string) $data['message'];
                }
            }
            return array('ok' => false, 'error' => 'HTTP ' . $code . ($message !== '' ? ': ' . $message : ''));
        }

        return array('ok' => true, 'data' => is_array($data) ? $data : array());
    }
}
