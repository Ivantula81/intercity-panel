<?php

// Наладка МАТЧИНГА агентов отчётности: алиасы и «где искать».
//
// Зачем: на проде 100% пассажиров без агента — алиасы в справочнике не совпадают с тем,
// что реально приходит в ведомостях («GouBas» вместо «Гоубас», нет Толкачева/Мачульской,
// нет Артмарка как контрагента). Из-за этого продажи Терры = 0 и наши 15% не начисляются.
//
// ⚠️ СТАВКИ (проценты) НЕ МЕНЯЕТ — это деньги и решение владельца. Правит только
// алиасы, источник поиска (match_src) и заводит отсутствующие сущности с нулевой
// ставкой, чтобы их было видно и можно было проставить процент руками.
//
// Запуск:  php reporting_agents_fix.php        — показать, что изменится (dry-run)
//          php reporting_agents_fix.php apply  — применить

require dirname(__DIR__) . '/app/bootstrap.php';

$apply = in_array('apply', $argv, true);
$db = db();

// Что должно быть, чтобы матчинг заработал. Источник — прототип владельца + фактические
// значения из ведомостей на проде. side/rate у существующих НЕ трогаем.
$want = [
    // наши (ищутся в поле «Агент/кассир» — там автозаполнение системы)
    ['name' => 'ИП Толкачёв',        'alias' => 'толкачев|толкачёв',      'side' => 'ours',    'src' => 'raw'],
    ['name' => 'Мачульская Н.А.',    'alias' => 'мачульская',             'side' => 'ours',    'src' => 'raw'],
    ['name' => 'Бондаренко',         'alias' => 'бондаренко',             'side' => 'ours',    'src' => 'raw'],
    ['name' => 'Артмарк GDS',        'alias' => 'артмарк|e-traffic|tutu|туту|unitiki|юнитик|busfor|басфор|автовокзал|новые тур|рус-билет|rus-bilet',
                                                                          'side' => 'ours',    'src' => 'raw'],
    // агенты перевозчика (ищутся в РУЧНОМ комментарии кассира — приоритет над полем)
    ['name' => 'Гоубас ВАнюк',       'alias' => 'гоубас|гоу бас|gobus|goubas', 'side' => 'carrier', 'src' => 'comment'],
    ['name' => 'Рус-Билет Ванюк',    'alias' => 'рб ванюк',               'side' => 'carrier', 'src' => 'comment'],
];

$existing = $db->query("SELECT c.id cid, c.agent_id, c.settlement_side, c.agent_commission_rate, c.match_src,
    a.id aid, a.name, a.aliases FROM report_agent_contracts c
    JOIN report_agents a ON a.id = c.agent_id WHERE c.active = 1")->fetchAll();

$byName = [];
foreach ($existing as $row) $byName[mb_strtolower(trim($row['name']))] = $row;

echo ($apply ? "ПРИМЕНЯЮ" : "[dry-run] БУДЕТ СДЕЛАНО") . ":\n\n";
$upAlias = $db->prepare('UPDATE report_agents SET aliases = ? WHERE id = ?');
$upSrc = $db->prepare('UPDATE report_agent_contracts SET match_src = ? WHERE id = ?');
$changed = 0; $created = 0;

foreach ($want as $w) {
    $key = mb_strtolower($w['name']);
    // ищем по имени или по пересечению алиасов (в проде имена чуть другие)
    $found = $byName[$key] ?? null;
    if (!$found) {
        foreach ($existing as $row) {
            foreach (explode('|', $w['alias']) as $al) {
                $al = trim(mb_strtolower($al));
                if ($al !== '' && mb_strlen($al) >= 4 && str_contains(mb_strtolower($row['name'] . ' ' . $row['aliases']), $al)) {
                    $found = $row; break 2;
                }
            }
        }
    }

    if ($found) {
        $needAlias = mb_strtolower(trim((string) $found['aliases'])) !== mb_strtolower($w['alias']);
        $needSrc = (string) $found['match_src'] !== $w['src'];
        if (!$needAlias && !$needSrc) { printf("  ok   %-24s уже настроен\n", mb_substr($found['name'], 0, 22)); continue; }
        printf("  ПРАВКА %-22s side=%-7s %s%%  (ставку не трогаю)\n", mb_substr($found['name'], 0, 22), $found['settlement_side'], rtrim(rtrim($found['agent_commission_rate'], '0'), '.'));
        if ($needAlias) printf("         алиасы: [%s] → [%s]\n", mb_substr((string) $found['aliases'], 0, 30), mb_substr($w['alias'], 0, 60));
        if ($needSrc)   printf("         где искать: %s → %s\n", $found['match_src'] ?: 'авто', $w['src']);
        if ($apply) {
            if ($needAlias) $upAlias->execute([$w['alias'], (int) $found['aid']]);
            if ($needSrc) $upSrc->execute([$w['src'], (int) $found['cid']]);
        }
        $changed++;
    } else {
        printf("  СОЗДАТЬ %-21s side=%-7s ставка 0%% (проставить вручную!)\n", mb_substr($w['name'], 0, 21), $w['side']);
        printf("         алиасы: %s\n", mb_substr($w['alias'], 0, 60));
        if ($apply) {
            $db->prepare('INSERT INTO report_agents (name, aliases) VALUES (?,?)')->execute([$w['name'], $w['alias']]);
            $aid = (int) $db->lastInsertId();
            $db->prepare('INSERT INTO report_agent_contracts (agent_id,title,settlement_side,agent_commission_rate,commercial_rate,dispatch_rate,match_src)
                VALUES (?,?,?,0,15,7,?)')->execute([$aid, 'Основной договор', $w['side'], $w['src']]);
        }
        $created++;
    }
}

echo "\n---\n";
printf("правок: %d · создать: %d\n", $changed, $created);
if ($apply) {
    echo "Готово. ⚠️ У созданных агентов ставка 0% — проставьте проценты в «Отчётность → Справочник агентов».\n";
    echo "Матчинг применится при следующем расчёте рейса (агент подбирается на лету, если не назначен вручную).\n";
} else {
    echo "Это DRY-RUN. Применить:  php " . basename(__FILE__) . " apply\n";
    echo "СТАВКИ не меняются ни в каком режиме — только алиасы и источник поиска.\n";
}
