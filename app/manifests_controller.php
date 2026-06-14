<?php

// Список ведомостей + загрузка CSV

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['manifest'])) {
    csrf_check();
    require_once PANEL_ROOT . '/app/manifest_import.php';
    try {
        $manifestId = import_manifest_csv($_FILES['manifest']);
        $cnt = db()->query('SELECT COUNT(*) FROM passengers WHERE manifest_id = ' . $manifestId)->fetchColumn();
        flash('Ведомость загружена: ' . $cnt . ' пассажиров.');
        $dest = ($_POST['dest'] ?? '') === 'notifications'
            ? '/?p=notifications&manifest_id=' . $manifestId
            : '/?p=manifest&id=' . $manifestId;
        header('Location: ' . $dest);
        exit;
    } catch (Exception $e) {
        $uploadError = $e->getMessage();
    }
}

$manifests = db()->query(
    'SELECT m.*, (SELECT COUNT(*) FROM passengers p WHERE p.manifest_id = m.id) cnt
     FROM manifests m ORDER BY m.id DESC LIMIT 50'
)->fetchAll();

view('layout', ['title' => 'Ведомости', 'page' => 'manifests',
    'content' => fn() => view('manifests', ['manifests' => $manifests, 'uploadError' => $uploadError ?? ''])]);
