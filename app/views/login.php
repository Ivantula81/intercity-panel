<?php /** @var string $error */ ?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#5b50e0">
<meta name="application-name" content="Интерсити Тур">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Интерсити Тур">
<title>Вход — Интерсити Тур</title>
<link rel="manifest" href="/manifest.webmanifest">
<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
<link rel="icon" type="image/svg+xml" href="/assets/icons/app-icon.svg">
<link rel="stylesheet" href="/assets/panel.css?v=<?= @filemtime(PANEL_ROOT . '/public/assets/panel.css') ?>">
</head>
<body>
<div class="login-wrap">
    <form class="login-card" method="post" action="/?p=login">
        <div class="brand" style="color:var(--ink);padding:0"><span class="logo" style="color:#fff">ИТ</span></div>
        <h1>Панель управления</h1>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label class="f">Логин
            <input type="text" name="login" autofocus required autocomplete="username">
        </label>
        <label class="f">Пароль
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        <?php if ($error !== ''): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>
        <button class="btn" style="width:100%;justify-content:center">Войти</button>
    </form>
</div>
<script>if('serviceWorker' in navigator){addEventListener('load',()=>navigator.serviceWorker.register('/sw.js').catch(()=>{}));}</script>
</body>
</html>
