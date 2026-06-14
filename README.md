# Интерсити Тур — панель (мини-CRM уведомлений)

Операционная панель автобусного перевозчика **ООО «ТерраТрансКрым»** (бренд «Интерсити Тур», рейсы в Крым). Единый рабочий хаб: уведомления пассажирам по нескольким каналам, ведомости из CSV, документы водителю/дорожные, база контактов (CRM) и двусторонние чаты.

Боевой адрес: `https://crm.terratranskrym.ru` (VPS, nginx + PHP-FPM + MariaDB).

## Возможности
- **Уведомления пассажирам** по каналам: WhatsApp (Evolution API self-hosted и Green API), MAX/Telegram (Green API), e-mail (SMTP smtp.bz). Группировка по станции посадки, шаблоны, переменные, времена из GDS.
- **Ведомости из CSV** (Jasper): распознавание рейса и пассажиров, редактор пассажиров.
- **Свободная рассылка** по списку номеров (с картинкой), с троттлингом.
- **Документы**: ведомость водителя и дорожная ведомость → PDF (Gotenberg) / Word / печать; печать и подпись; договоры-фрахтователи.
- **CRM**: авто-сбор контактов из всех отправок, страница контактов, экспорт CSV.
- **Чаты**: диалоги по контактам (входящие + исходящие в одной ленте), ответ через активный канал, вложения входящих медиа.
- **Статусы и входящие** — через webhooks (Evolution и Green API): доставлено/прочитано, ответы пассажиров.
- **Сотрудники**: логин/роли, учёт «кто что отправил».
- **Мониторинг**: снимки занятости рейсов из GDS (cron) + дашборд.

## Стек
- **PHP 8.3** — процедурный стиль + небольшие классы, без composer
- **MariaDB** (через PDO)
- **nginx + PHP-FPM**
- **Gotenberg** (Docker) — HTML→PDF и скриншоты
- Внешние API: **Evolution API**, **Green API**, **smtp.bz** (SMTP), **GDS** avtovokzal.ru (SOAP)

## Архитектура
Фронт-контроллер: `public/index.php` маршрутизирует `?p=<страница>`. JSON-API — `?p=api&a=<action>` в `app/api.php`. Вьюхи — `app/views/*.php`, общий каркас (сайдбар/мобильная навигация) — `app/views/layout.php`.

Каналы — через единый интерфейс: `active_wa_client()` возвращает клиента Evolution или Green API по активному аккаунту из таблицы `wa_accounts` (одинаковые методы `sendText/sendImage/...`). Входящие сообщения и статусы принимают `public/webhook.php` (Evolution) и `public/greenapi-webhook.php` (Green API) и пишут в таблицы `messages` (исходящие/статусы) и `inbox` (входящие).

Секреты в коде **не хранятся** — читаются из `/etc/panel.env` через `env_get()`.

## Структура
```
panel/
├── app/
│   ├── bootstrap.php           # сессия, БД (PDO), авторизация, хелперы
│   ├── api.php                 # JSON-API диспетчер (?p=api&a=...) + env_get()
│   ├── manifests_controller.php, manifest_import.php
│   ├── contacts.php, doc_templates.php
│   └── views/                  # страницы + layout.php
├── lib/
│   ├── EvolutionApiClient.php, GreenApiClient.php   # WhatsApp/MAX/Telegram
│   ├── SmtpMailer.php, EmailChannel.php             # e-mail (smtp.bz)
│   ├── ManifestParser.php, MessageTemplate.php
│   ├── PdfService.php          # Gotenberg (HTML→PDF)
│   ├── GdsRace.php, GdsLookup.php, gds/             # расписания GDS (SOAP)
│   └── inbox_media.php         # скачивание/кэш входящих вложений
├── public/
│   ├── index.php               # фронт-контроллер
│   ├── webhook.php             # приёмник Evolution
│   ├── greenapi-webhook.php    # приёмник Green API
│   └── assets/                 # panel.css, panel.js
├── schema.sql … schema10.sql   # миграции БД (применять по порядку)
├── seed_catalogs.sql, backfill_contacts.php
└── docs/                       # спеки/дизайн-документы
```

## Конфигурация (`/etc/panel.env`, `chmod 600` — НЕ в репозитории)
```
DB_PASS=...
EVO_URL=http://127.0.0.1:8080
EVO_INSTANCE=intercity
EVO_APIKEY=...
GREENAPI_URL=https://####.api.green-api.com
GREENAPI_ID=...
GREENAPI_TOKEN=...
GREENAPI_WEBHOOK_TOKEN=...
WEBHOOK_TOKEN=...                 # секрет для webhook Evolution
SMTP_HOST=connect.smtp.bz
SMTP_PORT=2525
SMTP_USER=...
SMTP_PASS=...
SMTP_FROM=...
SMTP_FROM_NAME=Интерсити Тур
GDS_LOGIN=...
GDS_PASSWORD=...
ADMIN_FALLBACK_PASSWORD=          # пусто = аварийный вход выключен
```

## Развёртывание (с нуля)
1. nginx + PHP-FPM 8.3 + MariaDB. Создать БД `panel` и пользователя `panel`.
2. Код — в `/var/www/panel` (корень сайта nginx — `/var/www/panel/public`).
3. Применить схемы **по порядку**: `schema.sql`, затем `schema2.sql` … `schema10.sql`, плюс `seed_catalogs.sql`.
4. Заполнить `/etc/panel.env` (см. выше).
5. Поднять Gotenberg (Docker) на `:3001` для генерации PDF.

## Деплой изменений
Изменённый файл → `scp` в `/var/www/panel/...` → `systemctl reload php8.3-fpm` (FPM держит opcache; иногда нужен полный `restart`). CSS/JS подключаются с cache-busting `?v=<filemtime>`. Визуальная проверка вёрстки — скриншоты через Gotenberg.

## Безопасность
- Все секреты — только в `/etc/panel.env` (не в коде и не в git).
- CSRF: `window.CSRF` в JS, заголовок `X-CSRF`, `csrf_check()` на сервере.
- Версионируется **только каталог `panel/`**; родительская рабочая папка содержит ключи и персональные данные и в репозиторий не попадает.
