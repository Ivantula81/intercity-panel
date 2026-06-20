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
    const MESSENGERS = ['whatsapp', 'max', 'telegram'];

    public static function label(string $ch): string
    {
        return self::LABELS[$ch] ?? $ch;
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

    // Список настроенных каналов.
    public static function active(): array
    {
        $out = [];
        foreach (array_keys(self::LABELS) as $ch) {
            if (self::configured($ch)) $out[] = $ch;
        }
        return $out;
    }

    // Наличие канала у номера: true / false / null (канал не настроен или проверка не удалась).
    public static function presence(string $ch, string $phone): ?bool
    {
        $c = self::client($ch);
        if ($c === null || !$c->isConfigured()) return null;

        if ($ch === 'whatsapp') {
            $r = $c->checkNumbers([$phone]);
            if (empty($r['ok'])) return null;
            $num = preg_replace('/\D+/', '', $phone);
            return isset($r['exists'][$num]) ? (bool) $r['exists'][$num] : false;
        }
        if ($ch === 'max' || $ch === 'telegram') {
            $r = $c->checkAccount($phone);
            return empty($r['ok']) ? null : (bool) $r['exists'];
        }
        if ($ch === 'sms') return true; // по номеру SMS возможна всегда
        return null;
    }

    // Проверка всех мессенджеров у номера → ['whatsapp'=>?bool, 'max'=>?bool, 'telegram'=>?bool].
    // null означает «канал не настроен» — такой не проверяем.
    public static function presenceAll(string $phone): array
    {
        $out = [];
        foreach (self::MESSENGERS as $ch) {
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
