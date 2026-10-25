<div id="sessionWarning" class="session-warning" role="status" aria-live="polite" hidden>
    <span id="sessionWarningText"></span>
    <button type="button" class="btn ghost" id="sessionContinue">Продолжить работу</button>
    <button type="button" class="btn ghost" id="sessionLoginEarly">Войти снова</button>
</div>
<dialog id="sessionLock" class="session-lock" aria-labelledby="sessionLockTitle">
    <form id="sessionReauth" class="session-lock-card" method="post" action="/?p=login">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <h1 id="sessionLockTitle">Войдите снова</h1>
        <p id="sessionLockReason">Время входа закончилось. Доступ к панели приостановлен.</p>
        <p class="muted" id="sessionDraftNote">Ввод остаётся на этой странице. Не закрывайте её. После входа той же учётной записью проверьте несохранённые изменения; отправки не повторяются автоматически.</p>
        <label class="f">Логин<input name="login" type="text" required autocomplete="username" maxlength="200"></label>
        <label class="f">Пароль<input name="password" type="password" required autocomplete="current-password" maxlength="4096"></label>
        <p id="sessionError" class="alert err" role="alert" hidden></p>
        <button class="btn" type="submit" id="sessionReauthButton">Войти и продолжить</button>
        <a class="btn ghost" id="sessionOtherLogin" href="/?p=logout">Выйти и очистить рабочее место</a>
    </form>
</dialog>
