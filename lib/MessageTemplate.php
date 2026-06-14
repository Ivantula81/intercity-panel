<?php

class MessageTemplate
{
    public static function defaultWhatsApp()
    {
        return "Здравствуйте, {имя}!\n\n"
            . "Напоминаем о вашей поездке {маршрут}.\n"
            . "Отправление: {дата} в {время}.\n"
            . "Место: {место}. Посадка: {откуда}.\n\n"
            . "Пожалуйста, приходите на посадку за 20 минут.\n"
            . "Интерсити Тур";
    }

    public static function defaultEmailSubject()
    {
        return 'Напоминание о поездке {маршрут} {дата}';
    }

    public static function placeholders()
    {
        return array('{имя}', '{маршрут}', '{дата}', '{время}', '{место}', '{откуда}', '{куда}', '{автобус}', '{перевозчик}');
    }

    public static function varsForPassenger($trip, $passenger)
    {
        $date = '';
        $time = '';
        if (preg_match('/(\d{2}\.\d{2}\.\d{4})(?:\s+(\d{1,2}:\d{2}))?/', (string) $trip['departure_at'], $m)) {
            $date = $m[1];
            $time = isset($m[2]) ? $m[2] : '';
        }

        $firstName = self::firstName($passenger['name']);

        return array(
            '{имя}' => $firstName !== '' ? $firstName : 'уважаемый пассажир',
            '{маршрут}' => trim((string) $trip['route']),
            '{дата}' => $date,
            '{время}' => $time,
            '{место}' => trim(preg_replace('/^место\s*/ui', '', (string) $passenger['seat'])),
            '{откуда}' => trim((string) $passenger['from']),
            '{куда}' => trim((string) $passenger['to']),
            '{автобус}' => trim((string) $trip['bus']),
            '{перевозчик}' => trim((string) $trip['carrier']),
        );
    }

    // Нестрогая подстановка: {Имя}, { имя }, {ИМЯ} — всё распознаётся.
    // Неизвестные переменные остаются в тексте как есть (видно, что не подставилось).
    public static function render($template, $vars)
    {
        $map = self::normalizeVars($vars);
        $text = preg_replace_callback('/\{\s*([^{}\s][^{}]*?)\s*\}/u', function ($m) use ($map) {
            $key = mb_strtolower($m[1], 'UTF-8');
            return array_key_exists($key, $map) ? $map[$key] : $m[0];
        }, (string) $template);

        // убираем строки, ставшие пустыми из-за незаполненных плейсхолдеров (напр. {доп})
        $text = preg_replace('/^[ \t]*\R/m', "\n", $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    // Какие {переменные} в тексте не известны — для предупреждения оператору
    public static function unknownVars($template, $vars)
    {
        $map = self::normalizeVars($vars);
        $unknown = array();
        if (preg_match_all('/\{\s*([^{}\s][^{}]*?)\s*\}/u', (string) $template, $mm)) {
            foreach ($mm[1] as $name) {
                if (!array_key_exists(mb_strtolower($name, 'UTF-8'), $map)) {
                    $unknown['{' . $name . '}'] = true;
                }
            }
        }
        return array_keys($unknown);
    }

    private static function normalizeVars($vars)
    {
        $map = array();
        foreach ($vars as $key => $value) {
            $key = mb_strtolower(trim((string) $key, "{} \t"), 'UTF-8');
            if ($key !== '') {
                $map[$key] = (string) $value;
            }
        }
        return $map;
    }

    // «Фамилия Имя Отчество» → «Имя Отчество». Заглушки вроде «нет» отбрасываем.
    private static function firstName($fullName)
    {
        $parts = array();
        foreach (preg_split('/\s+/u', trim((string) $fullName)) as $part) {
            if ($part !== '' && mb_strtolower($part, 'UTF-8') !== 'нет') {
                $parts[] = $part;
            }
        }

        if (count($parts) >= 3) {
            return $parts[1] . ' ' . $parts[2];
        }
        if (count($parts) === 2) {
            return $parts[1];
        }
        return implode(' ', $parts);
    }
}
