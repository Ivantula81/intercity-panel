<?php
/** @var string $title @var string $page @var callable $content */

function icon(string $name): string
{
    $paths = [
        'home' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/>',
        'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
        'file' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
        'send' => '<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3h0a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5h0a1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9v0a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'trash' => '<path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>',
        'print' => '<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
        'briefcase' => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/>',
        'chart' => '<path d="M3 3v18h18"/><path d="M7 14l4-4 3 3 5-6"/>',
        'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
        'whatsapp' => '<path d="M21 11.5a8.4 8.4 0 0 1-12.3 7.4L3 21l2.2-5.5A8.5 8.5 0 1 1 21 11.5z"/>',
        'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'edit' => '<path d="M17 3a2.8 2.8 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
        'doc' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 13h6M9 17h6"/>',
        'menu' => '<path d="M4 12h16M4 6h16M4 18h16"/>',
        'chat' => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8z"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . ($paths[$name] ?? $paths['link']) . '</svg>';
}

// порядок: для нижней мобильной панели берутся первые 4 + «Ещё»
$nav = [
    'dashboard'     => ['/', 'Дашборд', 'home'],
    'sales'         => ['/?p=sales', 'Продажи', 'chart'],
    'reporting'     => ['/?p=reporting', 'Отчётность', 'chart'],
    'notifications' => ['/?p=notifications', 'Уведомления', 'bell'],
    'chats'         => ['/?p=chats', 'Чаты', 'chat'],
    'manifests'     => ['/?p=manifests', 'Ведомости', 'file'],
    'contacts'      => ['/?p=contacts', 'Контакты', 'user'],
    'broadcast'     => ['/?p=broadcast', 'Рассылка', 'send'],
    'catalogs'      => ['/?p=catalogs', 'Справочники', 'briefcase'],
    'logs'          => ['/?p=logs', 'Логи', 'doc'],
    'audit'         => ['/?p=audit', 'Журнал действий', 'doc'],
    'settings'      => ['/?p=settings', 'Настройки', 'settings'],
];
$bottom = ['dashboard', 'notifications', 'chats', 'manifests'];
$sheetKeys = ['sales', 'reporting', 'contacts', 'broadcast', 'catalogs', 'logs', 'settings'];
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#5b50e0">
<meta name="application-name" content="Интерсити Тур">
<meta name="panel-build" content="<?= (int) max(@filemtime(PANEL_ROOT . '/public/assets/panel.css') ?: 0, @filemtime(PANEL_ROOT . '/public/assets/panel.js') ?: 0) ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Интерсити Тур">
<title><?= e($title) ?> — Интерсити Тур</title>
<link rel="manifest" href="/manifest.webmanifest">
<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
<link rel="icon" type="image/svg+xml" href="/assets/icons/app-icon.svg">
<link rel="stylesheet" href="/assets/panel.css?v=<?= @filemtime(PANEL_ROOT . '/public/assets/panel.css') ?>">
<script>window.CSRF = <?= json_encode(csrf_token()) ?>;</script>
</head>
<body data-page="<?= e($page) ?>">

<aside class="sidebar">
    <a href="/" class="brand"><span class="logo">ИТ</span><span class="brand-name">Интерсити&nbsp;Тур</span></a>
    <nav class="nav">
        <?php foreach ($nav as $key => [$url, $label, $ic]): ?>
            <a href="<?= $url ?>" class="nav-item <?= $key === $page ? 'active' : '' ?>"><span class="nav-ic"><?= icon($ic) ?></span><span class="nav-label"><?= $label ?></span></a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">
        <div class="user-chip">
            <span class="user-ava"><?= mb_strtoupper(mb_substr(current_user_name(), 0, 1)) ?></span>
            <span class="user-name"><?= e(current_user_name()) ?></span>
            <a href="/?p=logout" class="user-out" title="Выйти"><?= icon('logout') ?></a>
        </div>
    </div>
</aside>

<header class="topbar">
    <a href="/" class="topbar-brand"><span class="logo">ИТ</span></a>
    <div class="topbar-title"><?= e($title) ?></div>
    <a href="/?p=settings" class="topbar-act"><?= icon('settings') ?></a>
</header>

<main class="main">
    <?php if (($f = flash()) !== ''): ?><div class="alert ok flash-top"><?= e($f) ?></div><?php endif; ?>
    <?php $content(); ?>
</main>

<nav class="bottombar">
    <?php foreach ($bottom as $key): [$url, $label, $ic] = $nav[$key]; ?>
        <a href="<?= $url ?>" class="bn-item <?= $key === $page ? 'active' : '' ?>"><span class="bn-ic"><?= icon($ic) ?></span><span class="bn-label"><?= $label ?></span></a>
    <?php endforeach; ?>
    <button type="button" class="bn-item <?= in_array($page, $sheetKeys, true) ? 'active' : '' ?>" onclick="document.body.classList.toggle('sheet-open')"><span class="bn-ic"><?= icon('grid') ?></span><span class="bn-label">Ещё</span></button>
</nav>

<div class="sheet-backdrop" onclick="document.body.classList.remove('sheet-open')"></div>
<div class="more-sheet">
    <div class="sheet-grab"></div>
    <?php foreach ($sheetKeys as $key): [$url, $label, $ic] = $nav[$key]; ?>
        <a href="<?= $url ?>" class="sheet-item <?= $key === $page ? 'active' : '' ?>"><?= icon($ic) ?> <?= $label ?></a>
    <?php endforeach; ?>
    <div class="sheet-user">
        <span><?= e(current_user_name()) ?></span>
        <a href="/?p=logout" class="sheet-out"><?= icon('logout') ?> Выйти</a>
    </div>
</div>

<script src="/assets/panel.js?v=<?= @filemtime(PANEL_ROOT . '/public/assets/panel.js') ?>"></script>
<script>if('serviceWorker' in navigator){addEventListener('load',()=>navigator.serviceWorker.getRegistrations().then(rs=>rs.forEach(r=>r.unregister())).catch(()=>{}));}</script>
</body>
</html>
