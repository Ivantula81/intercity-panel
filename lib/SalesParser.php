<?php

// Разбор письма канала продаж в структурную запись для таблицы sales.
// Точно определяются канал и тип события (продажа/возврат/аннуляция/оплата/ведомость);
// сумма и дата рейса извлекаются best-effort (в осн. свой сайт + GoBus).
class SalesParser
{
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
        if (preg_match('/заказ\s*#\s*([0-9]+)/u', $subject . ' ' . $body, $m)) return $m[1];
        if (preg_match('/#\s*([0-9]{5,})/u', $subject, $m)) return $m[1];
        return '';
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
    public static function departAt(string $body): ?string
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
                $year = (int) date('Y');
                // если месяц уже прошёл в этом году — считаем следующий год
                if ((int) $mon < (int) date('m')) $year++;
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

    /** @return array<string,mixed> */
    public static function parse(string $sender, string $subject, string $body, string $occurredAt, string $emailId): array
    {
        $channel = self::channel($sender);
        $kind = self::kind($subject, $body);
        return [
            'email_id'    => $emailId,
            'channel'     => $channel,
            'kind'        => $kind,
            'ticket_no'   => self::ticket($subject, $body),
            'route'       => mb_substr(self::route($channel, $subject, $body), 0, 255),
            'segment'     => mb_substr(self::segment($body), 0, 255),
            'depart_at'   => self::departAt($body),
            'amount'      => self::amount($kind, $body),
            'passenger'   => mb_substr(self::passenger($body), 0, 255),
            'occurred_at' => $occurredAt,
            'subject'     => mb_substr($subject, 0, 255),
            'snippet'     => mb_substr($body, 0, 512),
        ];
    }
}
