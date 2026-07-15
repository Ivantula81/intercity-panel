<?php

// Реестр каналов отправки/проверки наличия.
//   whatsapp → Evolution        max → Green API (MAX-инстанс)
//   telegram → Green API (TG)   sms → SMS.RU
// Единый интерфейс: client / configured / presence / sendText. Канал без ключа неактивен.
// Использует фабрики из app/api.php (wa_client_for, green_api, green_api_tg, sms_ru).

class Channels
{
    const LABELS = [
        'whatsapp' => 'WhatsApp',
        'max'      => 'MAX',
        'telegram' => 'Telegram',
        'sms'      => 'SMS',
    ];

    // Мессенджеры, у которых можно проверять наличие у номера (SMS — по номеру всегда).
    const MESSENGERS = ['max', 'telegram', 'whatsapp'];

    // Канал по умолчанию, если настройка не задана. В России основной мессенджер — MAX.
    const PRIMARY_DEFAULT = 'max';

    public static function label(string $ch): string
    {
        return self::LABELS[$ch] ?? $ch;
    }

    // Основной канал рассылки — задаётся в «Настройках». От него считаются:
    // галочка по умолчанию, порядок каналов и то, какие каналы считаются запасными.
    public static function primary(): string
    {
        $ch = function_exists('opt') ? (string) opt('primary_channel', self::PRIMARY_DEFAULT) : self::PRIMARY_DEFAULT;
        return in_array($ch, self::MESSENGERS, true) ? $ch : self::PRIMARY_DEFAULT;
    }

    // Мессенджеры в порядке приоритета: основной первым, остальные — запасные.
    public static function byPriority(): array
    {
        $p = self::primary();
        return array_merge([$p], array_values(array_diff(self::MESSENGERS, [$p])));
    }

    // Клиент канала или null.
    public static function client(string $ch)
    {
        switch ($ch) {
            case 'whatsapp': return wa_client_for('whatsapp'); // Evolution на реальный инстанс
            case 'max':      return green_api();
            case 'telegram': return green_api_tg();
            case 'sms':      return sms_ru();
        }
        return null;
    }

    // Настроен ли канал (есть ключ/инстанс).
    public static function configured(string $ch): bool
    {
        $c = self::client($ch);
        return $c !== null && $c->isConfigured();
    }

    // Список настроенных каналов — основной первым (фронт берёт [0] как канал по умолчанию).
    public static function active(): array
    {
        $out = [];
        foreach (array_merge(self::byPriority(), ['sms']) as $ch) {
            if (self::configured($ch)) $out[] = $ch;
        }
        return $out;
    }

    // РЕАЛЬНОЕ состояние канала: 'open' | 'connecting' | 'close' | 'unconfigured' | 'ready' | 'error'.
    // Кэш в рамках запроса, чтобы не дёргать API повторно.
    public static function state(string $ch): string
    {
        static $cache = [];
        if (isset($cache[$ch])) return $cache[$ch];
        $c = self::client($ch);
        if ($c === null || !$c->isConfigured()) return $cache[$ch] = 'unconfigured';
        if ($ch === 'whatsapp' || $ch === 'max' || $ch === 'telegram') {
            $s = $c->connectionState();
            $st = !empty($s['ok']) ? (string) ($s['state'] ?? '') : '';
            // Живой опрос удался — доверяем ему. Если сбоит (таймаут/лимит провайдера) — НЕ врём
            // «не авторизован», а берём последнее состояние, которое сам провайдер прислал в webhook.
            if ($st !== '' && $st !== 'error') return $cache[$ch] = $st;
            return $cache[$ch] = (self::lastPushedState($ch) ?: 'error');
        }
        return $cache[$ch] = 'ready'; // sms/email — stateless HTTP, готовы если настроены
    }

    // Последнее состояние канала от провайдера через webhook (stateInstanceChanged / connection.update).
    // Единый источник правды, когда живой опрос временно недоступен. MAX/Telegram — фиксированные ключи.
    private static function lastPushedState(string $ch): string
    {
        static $keys = ['max' => 'wa_conn_greenapi', 'telegram' => 'wa_conn_greenapi_tg', 'whatsapp' => 'wa_conn_greenapi_wa'];
        if (!isset($keys[$ch]) || !function_exists('opt')) return '';
        $raw = json_decode((string) opt($keys[$ch]), true);
        $st = is_array($raw) ? (string) ($raw['state'] ?? '') : '';
        $map = ['authorized' => 'open', 'notAuthorized' => 'close', 'starting' => 'connecting', 'yellowCard' => 'connecting', 'blocked' => 'close'];
        return $st !== '' ? ($map[$st] ?? $st) : '';
    }

    // Готов ли канал реально отправлять (авторизован/подключён), а не просто «ключи заданы».
    public static function ready(string $ch): bool
    {
        $st = self::state($ch);
        return $st === 'open' || $st === 'ready';
    }

    // Наличие канала у номера + его chatId.
    //   known=false → проверить НЕ удалось. Это не «канала нет»: ответ неизвестен, врать нельзя.
    //   limited=true → упёрлись в лимит мессенджера на просмотр контактов (HTTP 469), надо ждать.
    //   chat_id      → сохраняем в contacts, чтобы при отправке не дёргать проверку заново.
    public static function presenceInfo(string $ch, string $phone): array
    {
        $out = ['known' => false, 'exists' => null, 'chat_id' => '', 'limited' => false, 'error' => ''];
        $c = self::client($ch);
        if ($c === null || !$c->isConfigured()) {
            $out['error'] = self::label($ch) . ': канал не настроен';
            return $out;
        }
        if ($ch === 'sms') return ['known' => true, 'exists' => true, 'chat_id' => '', 'limited' => false, 'error' => ''];

        // Green API (max/telegram/whatsapp) отдаёт chatId; Evolution умеет только батч-проверку номеров.
        if (method_exists($c, 'checkAccount')) {
            $r = $c->checkAccount($phone);
            if (empty($r['ok'])) {
                $out['limited'] = !empty($r['limited']);
                $out['error'] = (string) ($r['error'] ?? 'проверка не удалась');
                return $out;
            }
            return ['known' => true, 'exists' => (bool) $r['exists'], 'chat_id' => (string) ($r['chatId'] ?? ''), 'limited' => false, 'error' => ''];
        }
        $r = $c->checkNumbers([$phone]);
        if (empty($r['ok'])) {
            $out['error'] = (string) ($r['error'] ?? 'проверка не удалась');
            return $out;
        }
        $num = preg_replace('/\D+/', '', $phone);
        if (!array_key_exists($num, (array) ($r['exists'] ?? []))) {
            $out['error'] = 'номер не вернулся в ответе провайдера'; // неизвестно, а не «нет канала»
            return $out;
        }
        return ['known' => true, 'exists' => (bool) $r['exists'][$num], 'chat_id' => '', 'limited' => false, 'error' => ''];
    }

    // Наличие канала у номера: true / false / null (не настроен либо проверка не удалась).
    public static function presence(string $ch, string $phone): ?bool
    {
        $i = self::presenceInfo($ch, $phone);
        return $i['known'] ? (bool) $i['exists'] : null;
    }

    // Проверка всех мессенджеров у номера → ['max'=>?bool, 'telegram'=>?bool, 'whatsapp'=>?bool].
    // null означает «канал не настроен» или «проверить не удалось».
    public static function presenceAll(string $phone): array
    {
        $out = [];
        foreach (self::byPriority() as $ch) {
            $out[$ch] = self::configured($ch) ? self::presence($ch, $phone) : null;
        }
        return $out;
    }

    // Отправка в канал. target = номер (WhatsApp/SMS) либо chatId (MAX/Telegram, если есть).
    public static function sendText(string $ch, string $target, string $text): array
    {
        $c = self::client($ch);
        if ($c === null || !$c->isConfigured()) {
            return ['ok' => false, 'error' => self::label($ch) . ': канал не настроен'];
        }
        return $c->sendText($target, $text);
    }
}
