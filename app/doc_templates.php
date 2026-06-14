<?php

// HTML-шаблоны ведомостей для водителя и дорожной (по образцам ООО «ТерраТрансКрым»).

function doc_e($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function doc_seat_num($seat)
{
    return preg_match('/(\d+)/', (string) $seat, $m) ? (int) $m[1] : PHP_INT_MAX;
}

// Общая шапка/стили + подвал с печатью и подписью
function doc_render(string $title, array $manifest, array $carrier, array $passengers, string $bodyHtml, array $req): string
{
    $departure = $manifest['departure_at'] ? date('d.m.Y H:i', strtotime($manifest['departure_at'])) : '—';
    $stampImg = !empty($req['stamp']) ? '<img class="stamp" src="' . doc_e($req['stamp']) . '">' : '';
    $signImg = !empty($req['sign']) ? '<img class="sign" src="' . doc_e($req['sign']) . '">' : '';

    return '<!doctype html><html lang="ru"><head><meta charset="utf-8"><style>
    @page { margin: 10mm; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: "DejaVu Sans", Arial, sans-serif; font-size: 11px; color: #000; }
    .corner { text-align: right; font-size: 10px; line-height: 1.4; margin-bottom: 10px; color: #222; }
    h1 { text-align: center; font-size: 14px; margin: 6px 0 12px; }
    .head td { padding: 2px 6px; vertical-align: top; }
    .head .lbl { color: #333; width: 170px; }
    table.list { width: 100%; border-collapse: collapse; margin-top: 10px; }
    .list th, .list td { border: 1px solid #555; padding: 4px 6px; font-size: 10.5px; vertical-align: top; }
    .list th { background: #eee; text-align: center; }
    .num { text-align: center; white-space: nowrap; }
    .money { text-align: right; white-space: nowrap; }
    .paynote { color: #a00; font-weight: bold; }
    .comment { color: #333; }
    .foot { margin-top: 24px; position: relative; }
    .foot-row { display: flex; justify-content: space-between; align-items: flex-end; }
    .sign-box { position: relative; width: 320px; text-align: center; }
    .sign-line { border-top: 1px solid #000; margin-top: 34px; padding-top: 3px; }
    .stamp { position: absolute; right: 40px; bottom: -10px; width: 120px; opacity: .85; }
    .sign { position: absolute; left: 90px; bottom: 6px; width: 130px; }
    </style></head><body>
    <div class="corner">Приложение №2<br>к договору № ' . doc_e($carrier['contract_no']) . ' от ' . doc_e($carrier['contract_date']) . '<br>фрахтования транспортного средства<br>для перевозки пассажиров по заказу</div>
    <h1>' . doc_e($title) . '</h1>
    <table class="head">
        <tr><td class="lbl">Дата/время отправления:</td><td>' . doc_e($departure) . '</td></tr>
        <tr><td class="lbl">Перевозчик:</td><td>' . doc_e($carrier['atp'] ?: $manifest['carrier']) . '</td></tr>
        <tr><td class="lbl">Водитель(и):</td><td>' . doc_e($manifest['drivers']) . '</td></tr>
        <tr><td class="lbl">Транспорт:</td><td>' . doc_e($manifest['bus']) . '</td></tr>
        <tr><td class="lbl">Маршрут:</td><td>' . doc_e($manifest['route']) . '</td></tr>
    </table>
    ' . $bodyHtml . '
    <div class="foot"><div class="foot-row">
        <div></div>
        <div class="sign-box">
            ' . $signImg . $stampImg . '
            <div>Фрахтователь&nbsp;&nbsp;&nbsp;&nbsp;' . doc_e($req['frahtovatel'] ?: 'ООО «ТерраТрансКрым»') . '</div>
            <div class="sign-line">' . doc_e($req['signer']) . '</div>
        </div>
    </div></div>
    </body></html>';
}

// Ведомость ДЛЯ ВОДИТЕЛЯ — с маршрутами, ценами, пометками оплаты
function doc_driver(array $manifest, array $carrier, array $passengers, array $req): string
{
    usort($passengers, fn($a, $b) => doc_seat_num($a['seat']) - doc_seat_num($b['seat']));
    $rows = '';
    foreach ($passengers as $p) {
        $price = $p['price'] !== null ? number_format((float) $p['price'], 2, '.', ' ') : '';
        // платёжные комментарии выделяем красным, прочие — обычным
        $isPay = preg_match('/(посадк|оплат|деньг|водител|депозит|доплат|долж|остат|наличн|предоплат|\d{3,})/ui', (string) $p['pay_note']);
        $noteCls = $isPay ? 'paynote' : 'comment';
        $rows .= '<tr>
            <td class="num">' . doc_e($p['seat']) . '</td>
            <td>' . doc_e($p['name']) . ' <span style="color:#555">' . doc_e($p['doc']) . ' ' . doc_e($p['birthdate']) . '</span></td>
            <td class="num">' . doc_e($p['phone']) . '</td>
            <td class="num">' . doc_e($p['citizenship']) . '</td>
            <td>' . doc_e($p['from_stop']) . '</td>
            <td>' . doc_e($p['to_stop']) . '</td>
            <td class="money">' . $price . '</td>
            <td class="' . $noteCls . '">' . doc_e($p['pay_note']) . '</td>
        </tr>';
    }
    $body = '<table class="list"><thead><tr>
        <th>Место</th><th>Пассажир (документ, ДР)</th><th>Телефон</th><th>Гражд.</th>
        <th>Откуда</th><th>Куда</th><th>Стоимость</th><th>Комментарий</th>
    </tr></thead><tbody>' . $rows . '</tbody></table>';

    return doc_render('Список пассажиров определённый круг лиц', $manifest, $carrier, $passengers, $body, $req);
}

// ДОРОЖНАЯ ведомость — для инспекции, без денег и маршрутов
function doc_road(array $manifest, array $carrier, array $passengers, array $req): string
{
    usort($passengers, fn($a, $b) => doc_seat_num($a['seat']) - doc_seat_num($b['seat']));
    $rows = '';
    foreach ($passengers as $p) {
        $rows .= '<tr>
            <td class="num">' . doc_e($p['seat']) . '</td>
            <td>' . doc_e($p['name']) . '</td>
            <td>' . doc_e($p['doc']) . '</td>
            <td class="num">' . doc_e($p['birthdate']) . '</td>
            <td class="num">' . doc_e($p['phone']) . '</td>
        </tr>';
    }
    $body = '<table class="list"><thead><tr>
        <th>Место</th><th>ФИО</th><th>Документ</th><th>Дата рожд.</th><th>Телефон</th>
    </tr></thead><tbody>' . $rows . '</tbody></table>';

    return doc_render('Список пассажиров определённый круг лиц', $manifest, $carrier, $passengers, $body, $req);
}
