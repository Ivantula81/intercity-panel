<?php
// This key protects only local workspace copies. It is never an auth credential.
function workspace_config(): array
{
    if (empty($_SESSION['panel_user'])) { return []; }
    $owner = (string) $_SESSION['panel_user'];
    if (($_SESSION['workspace_crypto']['owner'] ?? '') !== $owner) {
        $_SESSION['workspace_crypto'] = [
            'owner' => $owner,
            'scope' => bin2hex(random_bytes(16)),
            'key' => base64_encode(random_bytes(32)),
        ];
    }
    return array_intersect_key($_SESSION['workspace_crypto'], ['scope'=>true, 'key'=>true]);
}

function workspace_missing(string $section): void
{
    http_response_code(404);
    view('layout', ['title'=>'Объект недоступен', 'page'=>$section, 'content'=>static function () use ($section): void {
        echo '<div class="card" data-workspace-missing><h1>Объект недоступен</h1>';
        echo '<p>Он удалён или больше не доступен. Выберите другой объект в разделе.</p>';
        echo '<a class="btn" href="/?p=' . e($section) . '">Вернуться к списку</a></div>';
    }]);
}

function workspace_logout_page(string $scope): void
{
    header('Cache-Control: no-store');
    header('Content-Type: text/html; charset=utf-8');
    $key = json_encode('panel-workspace:v1:' . $scope, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    echo '<!doctype html><html lang="ru"><meta charset="utf-8"><title>Выход</title><body><p>Вы вышли из аккаунта. <a href="/?p=login">Войти</a></p>';
    echo '<script>const k=' . $key . ';try{sessionStorage.removeItem(k);localStorage.removeItem(k);localStorage.setItem(k+":logout",String(Date.now()));}catch(_){}location.replace("/?p=login");</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=/?p=login"></noscript></body></html>';
}
