# Адаптация стандартных Laravel-пакетов для TrueAsync (корутины)

## Контекст

В TrueAsync каждый HTTP-запрос выполняется в отдельной корутине внутри одного PHP-воркера.
Это означает, что **все синглтоны, статические свойства и глобальное состояние разделяются между конкурентными запросами**.

Пакет `laravel-spawn` (ветка `feature/scope-per-request`) решает часть проблем через:
- `AsyncApplication` — перехват резолва сервисов с проверкой `current_context()`
- `ScopedServiceProxy` — прокси для фасадов, чтобы каждый вызов шёл в контекст текущей корутины
- `ScopedService` enum — список сервисов, изолированных по корутинам (`request`, `session`, `auth`, `auth.driver`, `cookie`)
- PDO Pool на уровне C — изоляция соединений к БД без скоупинга `DatabaseManager`

---

## Основные проблемы в корутинном окружении

| Тип проблемы | Описание |
|---|---|
| **Статические свойства / синглтоны** | Хранят состояние запроса → утечка между корутинами |
| **Кэш фасадов** | `Facade::$resolvedInstance` — статический массив, общий для всех корутин |
| **Глобальные переменные** | `$_GET`, `$_POST`, `$_SERVER`, `$_SESSION` — не изолированы (решается на уровне сервера) |
| **Блокирующий I/O** | Блокирует весь планировщик корутин, если не использовать async I/O |
| **Счётчик транзакций** | `Connection::$transactions` — общий между корутинами |

---

## Анализ пакетов

### Критические — требуют изоляции per-coroutine

| Пакет | Статус в spawn | Проблема | Решение |
|---|---|---|---|
| **Auth** | ✅ Scoped | `AuthManager::$guards[]` кэширует guard-ы с состоянием пользователя. `SessionGuard::$user` хранит аутентифицированного пользователя | `ScopedService::AUTH` + `ScopedServiceProxy` |
| **Session** | ✅ Scoped | `SessionManager` / `Store::$attributes` хранят данные сессии конкретного запроса | `ScopedService::SESSION` + `ScopedServiceProxy` |
| **Cookie** | ✅ Scoped | `CookieJar::$queued` — очередь cookies per-request | `ScopedService::COOKIE` (без прокси, т.к. type-hint `QueueingFactory`) |
| **Request** | ✅ Scoped | Каждый запрос — уникальный объект с URL, заголовками, телом | `ScopedService::REQUEST` через `current_context()` |

### Требуют внимания — частично решены или имеют ограничения

| Пакет | Статус | Проблема | TODO |
|---|---|---|---|
| **Database / Eloquent** | ⚠️ Частично | `Model::$resolver` — статическое свойство (нельзя скоупить, иначе segfault). `Connection::$transactions` — общий счётчик | Физическая изоляция через PDO Pool на уровне C. **TODO:** Решить проблему общего счётчика транзакций — использовать `DB::transaction()` вместо ручного `beginTransaction()`/`commit()` |
| **Events / Dispatcher** | ⚠️ Риск | `Dispatcher::$deferringEvents` (bool) и `$deferredEvents` (array) — глобальное состояние. Если два запроса одновременно откладывают события, они смешаются | **TODO:** Исследовать необходимость скоупинга `$deferredEvents` или отключения deferred events в async-режиме |
| **Broadcasting** | ⚠️ Неизвестно | `BroadcastManager::$drivers[]` кэширует broadcaster-ы. Pusher/Ably/Redis драйверы могут хранить состояние соединения | **TODO:** Проверить, хранят ли конкретные broadcaster-драйверы per-request состояние. Возможно, потребуется скоупинг |
| **View / Blade** | ⚠️ Низкий риск | `Factory::$shared` — массив данных, расшаренных через `View::share()`. Если middleware добавляет per-request данные через `share()`, они утекут в другие запросы | **TODO:** Проверить использование `View::share()` в middleware. Рекомендация: передавать данные через `view()->with()` или `compact()` вместо `share()` |
| **Routing** | ⚠️ Низкий риск | Маршруты загружаются при старте (безопасно). Но middleware может хранить состояние в свойствах экземпляра | **TODO:** Проверить, создаются ли middleware заново для каждого запроса или переиспользуются как синглтоны |

### Безопасные — не требуют адаптации

| Пакет | Почему безопасен |
|---|---|
| **Cache** | `CacheManager` — синглтон, но store-бэкенды (Redis, File, Memcached) stateless. Подтверждено тестами |
| **Queue** | `QueueManager` кэширует соединения, но dispatch stateless. Подтверждено тестами |
| **Mail** | `MailManager` кэширует mailer-ы, но они не хранят per-request состояние. Подтверждено тестами |
| **Log** | Логгирование — side-effect, состояние не хранится |
| **Validation** | `Validator` создаётся заново для каждой валидации, `Factory::$extensions` — read-only |
| **Filesystem / Storage** | `FilesystemManager` кэширует disk-и, но они stateless |
| **HTTP Client** | `PendingRequest` создаётся заново каждый раз |
| **Notifications** | Использует Queue/Mail под капотом (безопасны) |
| **Config** | Загружается при старте, immutable в runtime |
| **Encryption** | Stateless операции с ключом приложения |
| **Hashing** | Stateless алгоритмы |
| **Pagination** | Создаётся заново per-request |

---

## TODO — план адаптации

### Приоритет 1 (Критично)

- [x] **Auth** — изолирован через `ScopedService::AUTH` + proxy
- [x] **Session** — изолирован через `ScopedService::SESSION` + proxy
- [x] **Cookie** — изолирован через `ScopedService::COOKIE`
- [x] **Request** — изолирован через `ScopedService::REQUEST`
- [x] **Database** — PDO Pool на уровне C для изоляции соединений

### Приоритет 2 (Требуют исследования и возможной адаптации)

- [x] **Database: счётчик транзакций** — `Connection::$transactions` общий на все корутины, хотя PDO Pool
  даёт каждой корутине своё физическое соединение. Результат: корутина B видит счётчик A и шлёт SAVEPOINT
  вместо BEGIN. **Решение: трейт `CoroutineTransactions`** (`src/Database/CoroutineTransactions.php`) —
  переопределяет все методы ManagesTransactions, заменяя `$this->transactions` на `coroutine_context()`.
  Используется `coroutine_context()` (не `current_context()`), т.к. PDO Pool привязывает соединение
  к корутине, а не к скоупу
- [x] **Events: deferred events** — `Dispatcher::$deferringEvents` и `$deferredEvents` не изолированы.
  Обычный `dispatch()` безопасен — листенеры shared read-only. Проблема только в `Event::defer()`:
  если две корутины одновременно вызовут `defer()`, флаг `$deferringEvents` утечёт из одной в другую,
  и события будут ошибочно отложены. **Решение: не требует фикса** — `defer()` не вызывается в Laravel
  internals при обработке запроса, это user-space API. Достаточно документировать ограничение:
  не использовать `Event::defer()` в async-режиме, либо гарантировать отсутствие конкурентных вызовов
- [x] **Broadcasting** — проверено: `BroadcastManager` и драйверы (Pusher, Redis, Ably) не хранят
  per-request состояние. Методы аутентификации принимают `$request` как параметр, не сохраняют в свойствах.
  **Решение: не требует фикса.**
- [x] **View::share()** — `ShareErrorsFromSession` middleware вызывает `View::share('errors', ...)`
  на каждый запрос. На singleton Factory ошибки валидации одного запроса утекают в другой.
  **Решение: `AsyncViewFactory`** (`src/View/AsyncViewFactory.php`) — переопределяет `share()` и
  `getShared()`. До `bootCompleted()` — пишет в общий `$shared` (boot-time данные: `app`, `__env`).
  После — пишет в `current_context()` per-coroutine. `getShared()` мержит оба, контекст побеждает.
- [x] **Router: $current / $currentRequest** — `Router::dispatch()` и `findRoute()` перезаписывают
  `$current` и `$currentRequest` на каждый запрос. При конкурентных запросах `Route::current()` вернёт
  чужой маршрут. **Решение: `AsyncRouter`** (`src/Routing/AsyncRouter.php`) — хранит оба свойства в
  `current_context()` после `bootCompleted()`.
- [x] **Middleware lifecycle** — middleware создаются через `$container->make()` в Pipeline на каждый
  запрос (не синглтоны). Экземпляры не переиспользуются между запросами. **Не проблема.**

### Приоритет 3 (Рекомендации для пользователей пакета)

- [ ] **Документация**: описать какие паттерны unsafe в корутинах:
  - `static $property` для per-request данных
  - `View::share()` в middleware
  - Ручные транзакции (`beginTransaction()` / `commit()`)
  - Singleton-сервисы с mutable state
- [ ] **Линтер / статический анализ**: рассмотреть PHPStan-правило для обнаружения static state в request-контексте
- [ ] **Тесты конкурентности**: добавить тесты, запускающие N параллельных запросов и проверяющие изоляцию для каждого пакета из списка "Требуют внимания"

---

## Архитектурные заметки

### Почему `db` нельзя скоупить
`DatabaseServiceProvider::boot()` устанавливает `Model::$resolver = $app['db']` как статическое свойство.
Если `db` скоупить — после завершения корутины статическая ссылка будет указывать на уничтоженный объект → **segfault**.
Решение: `db` остаётся синглтоном, физическая изоляция через PDO Pool.

### Почему Cookie не проксируется
`AuthManager` передаёт `$app['cookie']` в `setCookieJar(QueueingFactory $cookie)`.
`ScopedServiceProxy` не реализует `QueueingFactory` → `TypeError`.
Решение: Cookie скоупится напрямую через `resolve()`, без прокси через `offsetGet()`.

### Паттерн для сторонних пакетов
```php
// config/async.php
'scoped_services' => [
    \SomePackage\Manager::class,
],

// Или программно
$app->scopedSingleton(\SomePackage\Manager::class, fn($app) => new Manager());
```
