# Bot Guard Module for DarvinCms

## Что внутри

- `files/` — готовые файлы модуля, которые разворачиваются в корень проекта DarvinCms.
- `bin/install.php` — установщик/обновлятор модуля.
- `composer.json` — ограничения совместимости по пакетам Darvin.
- `files/config/snippets/darvin_admin.sections.yaml` — фрагмент для `config/packages/darvin_admin.yaml`.
- `files/translations/admin.ru.bot_guard.yaml` — перевод раздела админки.

## Установка и обновление через git

Рекомендуемый сценарий: держать модуль в отдельном репозитории и подключать его в проекты как `git submodule` или через `git subtree`.

После получения/обновления кода модуля запустите:

```bash
php bot_guard_module/bin/install.php --project-dir=.
```

Установщик:

- проверяет версии `darvinstudio/darvin-admin-bundle` (>= 6.6.0);
- проверяет версии `darvinstudio/darvin-admin-frontend-bundle` (>= 6.2.0);
- копирует/обновляет файлы из `bot_guard_module/files/` в ваш проект.

## Действия после установки

1. Добавьте секции из `config/snippets/darvin_admin.sections.yaml` в `config/packages/darvin_admin.yaml`.
2. Добавьте переводы из `translations/admin.ru.bot_guard.yaml` в `translations/admin.ru.yaml`.
3. Выполните миграции:

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

4. Очистите кэш:

```bash
php bin/console cache:clear --env=prod
```

## Эксплуатация

- Для блокировки пустого `User-Agent` достаточно включенной настройки `blockEmptyUserAgent`.
- Логи блокировок автоматически дедуплицируются в коротком окне, чтобы не перегружать БД при атаках.
- Настройки и правила кешируются краткоживущим кешем, чтобы снизить нагрузку на БД.

## Очистка логов

- В `BotGuardSettings` есть `retentionDays` (срок хранения логов).
- В админке есть кнопка ручной очистки (`BotGuardLog`).
- Для cron:

```bash
php bin/console app:bot-guard:cleanup --env=prod
```

Пример с явным периодом:

```bash
php bin/console app:bot-guard:cleanup --days=60 --env=prod
```

## Базовая проверка

- В админке доступны `BotGuardSettings`, `BotGuardRule`, `BotGuardLog`.
- Запрос с пустым `User-Agent` получает код блокировки (по умолчанию `403`).
- Запрос с `User-Agent: GPTBot` получает код блокировки.

