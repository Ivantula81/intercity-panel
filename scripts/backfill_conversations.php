<?php

require dirname(__DIR__) . '/app/bootstrap.php';
require_once PANEL_ROOT . '/app/conversations.php';

$source = $argv[1] ?? 'all';
$count = 0;
foreach (['inbox','messages'] as $table) {
    if ($source !== 'all' && $source !== $table) continue;
    $last = 0;
    do {
        $st = db()->prepare("SELECT id FROM `$table` WHERE id>? ORDER BY id LIMIT 500");
        $st->execute([$last]);
        $ids = array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN));
        foreach ($ids as $id) {
            conversation_append_legacy($table,$id);
            $last = $id;
            $count++;
        }
        if ($ids) echo "$table: $last\n";
    } while (count($ids) === 500);
}
echo "Backfill complete: $count rows\n";
