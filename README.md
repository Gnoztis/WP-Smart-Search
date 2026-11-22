# WP Smart Search

Поиск по записям и по индексированным ссылкам для WordPress: AJAX-поиск в
админке и REST API (Application Password) для внешних инструментов —
дедупликации, аудита источников, внутренних ссылок.

Версия 2.3.0. Требуется WordPress 6.0+, PHP 8.0+.

## Установка

1. Скопировать каталог `wp-smart-search` в `wp-content/plugins/`.
2. Активировать плагин в разделе «Плагины».
3. Создать Application Password в профиле пользователя — он нужен для
   доступа к API.
4. Поиск в админке: «Записи» → «Умный поиск».

## Возможности

- Ajax-поиск по записям и меткам в админке.
- REST API для внешних инструментов: поиск по записям и по ссылкам
  (домен, хост, точный URL, текст анкора).
- Морфология: слова ищутся по основам, между словами — логическое И.
  «обход песочницы» находит «обходу песочницу».
- Индексация ссылок из контента: таблица `wss_links` обновляется при
  сохранении записи.
- Проверка дубликатов перед публикацией: поиск охватывает опубликованные,
  запланированные, черновики и записи «на утверждении» всех авторов.
- Batch-поиск по ссылкам: до 50 запросов за один вызов.
- Обновление из GitHub через штатный механизм WordPress.
- Корректное удаление: `uninstall.php` убирает таблицу, опции и кэш.

## REST API

Базовый путь: `/wp-json/wss/v1`.

Аутентификация — только заголовок `Authorization: Basic …` с
[Application Password](https://wordpress.org/documentation/article/application-passwords/).
Cookie-аутентификация для этих маршрутов не поддерживается. Минимальная
роль — та, у которой есть `publish_posts` (Автор и выше).

```bash
curl -s -u "user:xxxx xxxx xxxx xxxx xxxx xxxx" \
  "https://example.com/wp-json/wss/v1/me"
```

### `GET|POST /wss/v1/search`

| Параметр | Описание |
|---|---|
| `q` | Запрос. Слова ищутся по основам, между словами — И. Максимум 5 слов, 200 символов. |
| `scope` | `posts` (по умолчанию), `links`, `all`. |
| `type` | `all`, `tags`, `posts` (для `scope=posts/all`). |
| `match` | `auto` (по умолчанию), `fuzzy`, `exact` (URL/ID записи). |
| `page`, `per_page` | Пагинация, `per_page` 1..50. |
| `statuses` | Статусы массивом или строкой. Неизвестный статус — 400. |
| `date_range` | `any`, `today`, `week`, `month`, `year`. |
| `date_from`, `date_to` | ISO-даты, включительно. |
| `author` | ID автора (учитывается только при `edit_others_posts`). |
| `post_type` | Тип записи, по умолчанию `post`. Неизвестный — 400. |
| `exclude`, `exclude_id` | ID для исключения (максимум 200). |
| `url`, `domain`, `host`, `anchor` | Фильтры для `scope=links`. |
| `fields` | Поля `links.items` через запятую. Всегда возвращаются `post_id` и `match_type`. |
| `exact_total` | `false` — не считать `total` (экономит один `COUNT`). |

Ответ:

```json
{
  "query": "обход песочницы",
  "scope": "posts",
  "meta": { "terms": ["обход", "песочниц"], "tokens": 2, "dropped_tokens": 0 },
  "posts": { "items": [], "total": 1, "page": 1, "per_page": 10, "total_pages": 1 },
  "links": { "items": [], "total": 1, "total_exact": true, "page": 1, "per_page": 50, "has_more": false }
}
```

`meta.terms` — по каким основам реально искали,
`meta.dropped_tokens` — сколько слов отброшено лимитом в 5 слов.

### `POST /wss/v1/links/batch`

До 50 поисков за один вызов. Тело — JSON:

```json
{
  "domains": ["example.com", "example.org"],
  "hosts": [],
  "urls": [],
  "anchors": [],
  "per_page": 5,
  "statuses": ["publish"],
  "fields": "post_id,url,anchor"
}
```

Ответ: `{ "results": [ { "lookup": { "domain": "…" }, "items": [], "has_more": false } ], "meta": { "count": 2, "truncated": false } }`.
В batch-режиме `total` не считается (`total_exact: false`).

### `GET /wss/v1/me`

Текущий пользователь, его права и лимиты API: `can_search`,
`can_filter_by_author`, `allowed_statuses`, `visibility_mode`,
`visible_statuses`, `private_scope`, `cache_backend`, `max_per_page`,
`max_words`, `batch_limit`, `link_fields`, `plugin_version`.

## Обновления

Плагин обновляется штатным механизмом WordPress из репозитория
[Gnoztis/WP-Smart-Search](https://github.com/Gnoztis/WP-Smart-Search):
берётся релиз или тег вида `vX.Y.Z`, архив — zipball GitHub.

Проверка кэшируется на 6 часов; кнопка «Проверить обновления» в строке
плагина сбрасывает кэш. Для обхода лимита GitHub API можно задать токен:
`define( 'WSS_GITHUB_TOKEN', '…' );` в `wp-config.php` или фильтр
`wss_github_token`.

## Кэширование

Кэш работает на Redis через PHP-расширение `redis` (phpredis). Других
зависимостей нет: ни плагины объектного кэша, ни drop-in `object-cache.php`
не нужны и не используются.

1. Если расширение `redis` загружено и сервер отвечает — результаты поиска
   кэшируются в Redis. Адрес, порт, пароль и база задаются константами
   `WSS_REDIS_*` в `wp-config.php` или фильтром `wss_redis_config`.
2. Если расширения нет или Redis не отвечает — плагин работает без кэша.
   Ошибки кэша не влияют на поиск: он просто идёт в базу.

```php
// wp-config.php
define( 'WSS_REDIS_HOST', '127.0.0.1' );
define( 'WSS_REDIS_PORT', 6379 );
define( 'WSS_REDIS_PASSWORD', '' );
define( 'WSS_REDIS_DATABASE', 0 );
```

Транзиенты как кэш не используются: они пишутся в `wp_options` и стоят
дороже запроса, который должны экономить.

Доступность Redis проверяется по требованию — на первом обращении к кэшу
в запросе, и после сбоя не чаще раза в минуту (время ожидания подключения
0.2 с). Счётчики rate limit при недоступном Redis хранятся в транзиентах,
иначе лимит запросов исчез бы.

Активный бэкенд виден в ответе `/wss/v1/me` в поле `cache_backend`:
`redis` или `none`.

## Безопасность

- Запросы к БД — через `$wpdb->prepare()`, `LIKE`-значения — `esc_like()`.
- REST: `permission_callback`, обязательный заголовок `Authorization`,
  capability `publish_posts`.
- Видимость записей: пользователь с правом публикации видит опубликованные,
  запланированные, черновики и записи «на утверждении» всех авторов — это
  нужно для проверки дубликатов. Приватные записи (`private`) остаются
  автору и редакторам. Строгий режим («только опубликованное и своё»)
  включается константой `WSS_VISIBILITY_MODE = 'own'` в `wp-config.php` или
  фильтром `wss_visibility_mode`.
- CORS: строгий allowlist для `/wss/v1/*`; остальные маршруты REST
  обслуживает ядро WordPress.
- Пакет обновления принимается только по `https` и только с доменов GitHub.
- Секретов в коде нет; токен GitHub — константа или фильтр.

## Лицензия

GPL-2.0-or-later.
