<?php /** @var string $error */ ?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Вход — Интерсити Тур</title>
<link rel="stylesheet" href="/assets/panel.css">
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
</body>
</html>
