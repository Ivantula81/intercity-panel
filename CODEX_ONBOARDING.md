# Онбординг для Codex — панель «Интерсити Тур»

Ты — второй ИИ-агент на этом проекте (рядом работает Claude). Это инструкция: куда подключиться, где настроиться, как работать, чтобы не толкаться с Claude и видеть его работу.

## 1. Подключение к серверу
Боевой VPS (Ubuntu 24.04). По SSH:
```
ssh root@45.12.74.60
```
Пароль — у владельца. Уже установлено: Node 22, git, `gh` (залогинен под Ivantula81), tmux. Сайт: https://crm.terratranskrym.ru.

## 2. Установка Codex CLI (если ещё нет)
```
npm i -g @openai/codex
codex --version
```
Авторизуйся своим аккаунтом/ключом OpenAI.

## 3. Твоё рабочее место (отдельный каталог + ветка)
Работай в СВОЁМ git-worktree — НЕ в общем каталоге:
```
cd /root/panel-codex     # твой каталог, ветка codex/work
codex
```
Если каталога ещё нет — создай:
```
git -C /root/panel-src worktree add /root/panel-codex -b codex/work
git -C /root/panel-codex config user.name "Codex"
git -C /root/panel-codex config user.email "codex@intercity.local"
```
Лучше запускать в постоянной tmux-сессии, чтобы переживать обрывы связи:
```
tmux new -A -s codex -c /root/panel-codex
```

## 4. Сначала — контекст
Прочитай в корне репозитория:
- **`CLAUDE.md`** — бриф проекта (стек, инфра, каналы, грабли, деплой).
- **`COORDINATION.md`** — правила, зоны ответственности, журнал работ.
- **`CODEX_RECOMMENDATIONS.md`** + `docs/ux-audit/` — твой же аудит (план развития).

## 5. Рабочий цикл
1. `git fetch --all && git log --all --oneline` — увидеть, что сделал Claude.
2. Правь файлы **своей зоны** (см. `COORDINATION.md`): надёжность рассылок (outbox/идемпотентность), conversations-модель, рефакторинг `app/api.php`, тесты, безопасность.
3. Перед коммитом — lint: `php -l <файл>`, при правке JS — `node --check public/assets/panel.js`.
4. Коммить (авто-пуш на GitHub сработает сам). Свой статус дописывай в журнал `COORDINATION.md`.
5. Готово и проверено → PR (`gh pr create`) → мёрдж в `main` → деплой: `git -C /root/panel-src pull && /root/deploy.sh`.

## 6. Важные правила
- **Живой сайт — только из `main`.** WIP-ветки на прод не льём (`/root/deploy.sh` берёт `/root/panel-src` = main).
- **Секреты** — в `/etc/panel.env` (НЕ в репо, права `640 root:www-data`, НЕ `600`). Репозиторий приватный — публичным не делать.
- **`app/api.php`** — общий с Claude. Пока разбиваешь его (#28), Claude туда не лезет — согласуй в `COORDINATION.md`.
- Меняешь дизайн/логику — сначала макет/план, потом код (требование владельца).

## 7. Видеть работу Claude (через «хаб» = GitHub)
- `git fetch --all && git log --all --oneline` — все коммиты обоих.
- `gh pr list` — что у кого в работе; открыть PR = видно ровно что сделал другой.
- Ветки `claude/*` — его работа; твои — `codex/*`.

Конкретные задачи тебе даст владелец.
