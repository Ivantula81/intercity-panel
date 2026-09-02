<?php

// Разбор письма канала продаж в структурную запись для таблицы sales.
// Точно определяются канал и тип события (продажа/возврат/аннуляция/оплата/ведомость);
// сумма и дата рейса извлекаются best-effort (в осн. свой сайт + GoBus).
class SalesParser
{
    public const VERSION = 2;

    public static function channel(string $sender): string
    {
        $s = mb_strtolower($sender, 'UTF-8');
        if (str_contains($s, 'gobus.online')) return 'gobus';
        if (str_contains($s, 'ros-bilet.ru')) return 'rosbilet';
        if (str_contains($s, 'unitiki.com')) return 'unitiki';
        if (str_contains($s, 'e-traffic.ru')) return 'artmark';
        if (str_contains($s, 'avtovokzaly.ru')) return 'avtovokzaly';
        if (str_contains($s, 'blablacar')) return 'blablacar';
        if (str_contains($s, 'интерсититур') || str_contains($s, 'xn--')) return 'site';
        return 'other';
    }

    public static function kind(string $subject, string $body): string
    {
        $t = mb_strtolower($subject . ' ' . $body, 'UTF-8');
        if (str_contains($t, 'аннулирован') || str_contains($t, 'вычеркните пассажира')) return 'cancel';
        if (str_contains($t, 'возврат') || str_contains($t, 'возвращен') || str_contains($t, 'отмена отправления')) return 'refund';
        if (str_contains($t, 'оплата заявки') && str_contains($t, 'успешно')) return 'payment';
        if (str_contains($t, 'список билетов') && str_contains($t, 'сформирован')) return 'manifest';
        if (str_contains($t, 'продан билет') || str_contains($t, 'покупка') || str_contains($t, 'электронные билеты')) return 'sale';
        // Артмарк/e-traffic: «Агент …, Билет N» + «Пассажир» — это продажа через GDS
        if (str_contains($t, 'агент') && str_contains($t, 'билет') && str_contains($t, 'пассажир')) return 'sale';
        return 'other';
    }

    public static function ticket(string $subject, string $body): string
    {
        if (preg_match('/\[#\s*([0-9]+)/u', $subject, $m)) return $m[1];
        if (preg_match('/Билет\s*№\s*([0-9]+)/u', $body, $m)) return $m[1];
        if (preg_match('/\bБилет\s+([0-9]{4,})\b/u', $subject . ' ' . $body, $m)) return $m[1];
        if (preg_match('/заказ\s*#\s*([0-9]+)/u', $subject . ' ' . $body, $m)) return $m[1];
        if (preg_match('/#\s*([0-9]{5,})/u', $subject, $m)) return $m[1];
        return '';
    }

    public static function order(string $subject, string $body): string
    {
        $text = $subject . ' ' . $body;
        if (preg_match('/\b(SO-\d{8}-\d{6}-[A-Z0-9]+)\b/ui', $text, $m)) return strtoupper($m[1]);
        if (preg_match('/заказ(?:а)?\s*#\s*([0-9]+)/ui', $text, $m)) return $m[1];
        if (preg_match('/покупка через сайт\s*#\s*([0-9]+)/ui', $text, $m)) return $m[1];
        return '';
    }

    public static function quantity(string $subject, string $body): int
    {
        $text = $subject . ' ' . $body;
        if (preg_match('/Билетов:\s*(\d{1,3})/ui', $text, $m)) return max(1, min(999, (int) $m[1]));
        if (preg_match('/\b(\d{1,3})\s+билет(?:а|ов)?\b/ui', $text, $m)) return max(1, min(999, (int) $m[1]));
        return 1;
    }

    public static function route(string $channel, string $subject, string $body): string
    {
        // GoBus: "... / Курск - Евпатория / 29.06.2026 11:00"
        if ($channel === 'gobus' && preg_match('#/\s*([^/]+?\s-\s[^/]+?)\s*/\s*\d{2}\.\d{2}\.\d{4}#u', $body, $m)) {
            return trim($m[1]);
        }
        // RosBilet: тема "на автобус X - Y."
        if (preg_match('/на автобус\s+(.+?)\.?\s*$/u', $subject, $m)) return trim($m[1]);
        // "Рейс: X - Y 13:00" / "Маршрут X - Y"
        if (preg_match('/(?:Рейс|Маршрут):?\s+(.+?)\s+\d{1,2}:\d{2}/u', $body, $m)) return trim($m[1]);
        if (preg_match('/Маршрут\s+(.+?)\s+Посадка/u', $body, $m)) return trim($m[1]);
        // свой сайт: "A — B" (длинное тире) рядом с "Отправление"
        if (preg_match('/([^\n]+?\s[—-]\s[^\n]+?)\s*Отправление/u', $body, $m)) return trim($m[1]);
        return '';
    }

    public static function segment(string $body): string
    {
        if (preg_match('/Проданный отрезок:\s*(.+?)\s*(?:Место|$)/u', $body, $m)) return trim($m[1]);
        return '';
    }

    // Дата/время рейса (Y-m-d H:i:s) или null
    public static function departAt(string $body, string $occurredAt = ''): ?string
    {
        if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})\s+(\d{2}):(\d{2})/u', $body, $m)) {
            return "$m[3]-$m[2]-$m[1] $m[4]:$m[5]:00";
        }
        // свой сайт: "Отправление 15 июня, в 11:50"
        $months = ['января'=>'01','февраля'=>'02','марта'=>'03','апреля'=>'04','мая'=>'05','июня'=>'06',
                   'июля'=>'07','августа'=>'08','сентября'=>'09','октября'=>'10','ноября'=>'11','декабря'=>'12'];
        if (preg_match('/Отправление\s+(\d{1,2})\s+([а-яё]+),?\s+в\s+(\d{1,2}):(\d{2})/u', $body, $m)) {
            $mon = $months[mb_strtolower($m[2], 'UTF-8')] ?? null;
            if ($mon) {
                $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
                $reference = strtotime($occurredAt) ?: time();
                $year = (int) date('Y', $reference);
                // Год без года определяем относительно даты самого письма, а не
                // даты запуска повторного импорта исторического ящика.
                if ((int) $mon < (int) date('m', $reference)) $year++;
                return sprintf('%d-%s-%s %02d:%s:00', $year, $mon, $day, (int) $m[3], $m[4]);
            }
        }
        return null;
    }

    // Сумма (₽) best-effort
    public static function amount(string $kind, string $body): ?float
    {
        if (preg_match('/Оплаченная сумма:\s*([\d\s]+)/u', $body, $m)) {
            return (float) preg_replace('/\s+/', '', $m[1]);
        }
        if (preg_match('/Сумма к возврату\s*([\d\s]+)/u', $body, $m)) {
            return (float) preg_replace('/\s+/', '', $m[1]);
        }
        // свой сайт, эл. билеты: суммируем все "NNNN р."
        if (preg_match_all('/(\d{3,6})\s*р(?:уб|\.|\b)/u', $body, $mm) && $mm[1]) {
            $sum = 0; foreach ($mm[1] as $v) $sum += (int) $v;
            return (float) $sum;
        }
        return null;
    }

    public static function passenger(string $body): string
    {
        // свой сайт/e-traffic: ФИО рядом с документом/телефоном — берём осторожно, иначе пусто
        if (preg_match('/Пассажир\s+([А-ЯЁ][а-яё]+\s[А-ЯЁ][а-яё]+(?:\s[А-ЯЁ][а-яё]+)?)/u', $body, $m)) return $m[1];
        return '';
    }

    public static function eventKey(string $channel, string $kind, string $ticket, string $order): ?string
    {
        $businessId = $ticket !== '' ? 'ticket:' . $ticket : ($order !== '' ? 'order:' . $order : '');
        if ($businessId === '') return null;
        return hash('sha256', mb_strtolower($channel . '|' . $kind . '|' . $businessId, 'UTF-8'));
    }

    public static function relevant(array $row): bool
    {
        return ($row['channel'] ?? 'other') !== 'other'
            && in_array($row['kind'] ?? 'other', ['sale', 'payment', 'refund', 'cancel', 'manifest'], true);
    }

    /** @return array<string,mixed> */
    public static function parse(string $sender, string $subject, string $body, string $occurredAt, string $emailId): array
    {
        $channel = self::channel($sender);
        $kind = self::kind($subject, $body);
        $ticket = self::ticket($subject, $body);
        $order = self::order($subject, $body);
        return [
            'email_id'    => $emailId,
            'source'      => 'email',
            'source_event_id' => $emailId,
            'channel'     => $channel,
            'kind'        => $kind,
            'ticket_no'   => $ticket,
            'order_no'    => $order,
            'quantity'    => self::quantity($subject, $body),
            'event_key'   => self::eventKey($channel, $kind, $ticket, $order),
            'parse_version' => self::VERSION,
            'route'       => mb_substr(self::route($channel, $subject, $body), 0, 255),
            'segment'     => mb_substr(self::segment($body), 0, 255),
            'depart_at'   => self::departAt($body, $occurredAt),
            'amount'      => self::amount($kind, $body),
            'passenger'   => mb_substr(self::passenger($body), 0, 255),
            'occurred_at' => $occurredAt,
            'subject'     => mb_substr($subject, 0, 255),
            'snippet'     => mb_substr($body, 0, 512),
        ];
    }
}
