# Интерсити Тур — панель управления

Внутренняя панель автобусной компании: рассылка уведомлений пассажирам по нескольким мессенджерам, ведомости и документы для водителей, CRM-контакты, аналитика продаж, чаты с пассажирами.

## Стек
- **PHP** (vanilla, server-rendered) + **ванильный JS** + свой **CSS**. Без фреймворков — сознательно (поддерживаемо для этого масштаба).
- **MariaDB**. Точка входа `public/index.php` (роутер `?p=<страница>`); API — `app/api.php` (`?p=api&a=<действие>`, JSON, CSRF в заголовке `X-CSRF`).
- Вьюхи — `app/views/*.php`, общий каркас `app/views/layout.php`, бутстрап/хелперы — `app/bootstrap.php`.
- Прод: **nginx + PHP-FPM**, docroot — `public/`.

## Инфраструктура и деплой
- Боевой сервер: VPS `45.12.74.60`, сайт `https://crm.terratranskrym.ru`.
- Рабочая копия на сервере: `/root/panel-src`. Деплой: **`/root/deploy.sh`** (rsync → `/var/www/panel`, lint, reload php8.3-fpm). Боевой docroot — `/var/www/panel/public`.
- Секреты — в **`/etc/panel.env`** (НЕ в репозитории).
  ⚠️ Права файла должны быть **`640 root:www-data`**. НЕ `600` — иначе PHP-FPM (www-data) не прочитает env → `db()` падает → весь сайт 500.
- Git: каждый коммит **авто-пушится** на GitHub (хук `post-commit`). Репозиторий **приватный** — публичным не делать (в `schema*.sql` есть чувствительное).
- Рабочая сессия на сервере: `/root/work.sh` (tmux `dev`). Можно подключаться с ноута и планшета — одна общая сессия.

## Каналы сообщений
Единый реестр — `lib/Channels.php` (`configured` / `presence` / `sendText`). Наличие канала у номера и его chatId — через `CheckAccount`.
- **WhatsApp** = Evolution API (инстанс `rabochiy_86ed8`), `lib/EvolutionApiClient.php`.
- **MAX** и **Telegram** = Green API (разные инстансы), `lib/GreenApiClient.php`. Ключи `GREENAPI_*` (MAX) и `GREENAPI_TG_*` (Telegram).
- **SMS** = SMS.RU (`lib/SmsRuClient.php`, ключ `SMSRU_API_ID`, сервисные сообщения).
- **Email** = SMTP smtp.bz (`lib/SmtpMailer.php`).
- Статусы доставки/прочтения и входящие приходят в `public/webhook.php` (Evolution) и `public/greenapi-webhook.php` (Green API) → таблицы `messages` / `inbox`.
- ⚠️ Для **MAX/Telegram** chatId из `CheckAccount` — голый числовой id, слать как есть, **без `@c.us`** (суффикс только для WhatsApp-телефонов). В `GreenApiClient` это разруливает параметр `$messenger` (whatsapp|max|telegram).

## Конвенции
- Любая правка → lint (`php -l`, `node --check`) → `/root/deploy.sh` → проверка.
- Меняешь дизайн/логику — **сначала макет/план, потом код** (требование владельца).
- Цвета и метки каналов — единый источник `msg_channel_meta()` в `app/bootstrap.php`.
- Деструктивные/конфиг-действия должны требовать роль admin (в работе, задача #26).

## Грабли (проверены на практике)
- Много частых SSH-сессий с паролем → **fail2ban** временно банит IP (симптом: `Connection closed by … port 22`, при этом сайт по HTTP жив). Спадает за ~10 мин.
- **RAM** (VPS 4 ГБ): Twenty CRM (Docker, «для оценки») и утечка chromium у Gotenberg съедают память → панель тормозит. Лечится остановкой Twenty + `docker restart gotenberg-gotenberg-1`.

## Дорожная карта / аудит
- `CODEX_RECOMMENDATIONS.md` и `docs/ux-audit/REPORT-2026-06-21.md` — независимый UX-аудит + предложения.
- Текущий фокус: **Чаты как рабочий inbox** (фильтры каналов, входящие Telegram, статус разговора, контекст рейса).
- При параллельной работе нескольких агентов (Claude / Codex) — координация и разделение зон в `COORDINATION.md` (если заведён).
