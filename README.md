# Sendproof for Laravel

Sendproof adds an API workbench to a Laravel application: browse your OpenAPI document, edit requests, **Send** them, **Preview** a write with a real database rollback, and read the SQL, logs, effects and row changes that an **Observed Send** produced.

The package implements [Sendproof protocol v1](https://github.com/mprync/sendproof/blob/6d116184ca5d46a98a86dc2be09861542f0d48bf/spec/README.md). Preview dispatches the real request through your own routing, middleware, validation and handler in the same process, inside a transaction that is always rolled back.

> **Status:** `0.1.0-alpha.2`. Tested with Laravel 13.43 on PHP 8.4 and SQLite. The package allows Laravel 11–13 and PHP 8.2+, but only the tested combination is verified.

## Install

```sh
composer require sendproof/laravel
```

The service provider and `Sendproof` facade are auto-discovered. Publish the configuration if you want to edit it:

```sh
php artisan vendor:publish --tag=sendproof-config
```

## Turn it on

The workbench is **off by default** and refuses to run in production. It is mounted only when both conditions hold:

```dotenv
SENDPROOF_ENABLED=true
SENDPROOF_ENVIRONMENTS=local   # comma-separated APP_ENV values, default "local"
```

Open `/sendproof/`. With the switch off, or in an unlisted environment, no workbench route exists and `/sendproof/_preview/...` returns 404.

Do not list `production` unless you have also restricted access with `sendproof.authorize` and understand that previews run real application code.

## Opt operations in

Preview and Observed Send are explicit per route. Add `->sendproofPreview()` (or the `sendproof.preview` middleware) **after** your authentication and authorization middleware:

```php
Route::middleware(['auth:sanctum', 'can:manage-items'])->group(function () {
    Route::post('/items', [ItemController::class, 'store'])->sendproofPreview();
    Route::patch('/items/{item}', [ItemController::class, 'update'])->sendproofPreview();
});
```

Unmarked routes still work normally with Send. A preview of an unmarked route returns 409 and never runs the handler.

## What Preview guarantees

- The request `POST /sendproof/_preview/items` is rewritten to `POST /items` and dispatched in-process. Method, query, headers and body are preserved. Nothing leaves the application.
- A transaction opens on every connection in `sendproof.connections` before routing starts, and is rolled back afterwards. The result is confirmed only if rollback is verified. If the handler commits or closes the preview transaction, or rollback fails, you get an error response and no envelope.
- Responses of 401 or 403, redirects before the handler, unhandled exceptions, responses over 1 MiB, unavailable providers and missing preferences never produce a confirmed envelope.
- Cookies and `Location` from the inner response are dropped.
- Outgoing mail is captured and **not sent**. Everything else you deliver yourself must call the effect API below.

### Record external effects

Call the facade before sending anything outside the database. It returns `true` during a preview, so skip the effect then:

```php
use Sendproof\Laravel\Facades\Sendproof;

if (! Sendproof::tryRecordHttp('Notify billing', 'POST', $url, body: $payload)) {
    Http::post($url, $payload);
}
```

`tryRecordEmail`, `tryRecordHttp`, `tryRecordJob`, `tryRecordMessage` and `tryRecordEffect` follow the conventional shapes in the protocol. In an Observed Send they record the effect as `delivered: true` and return `false`.

## What is captured

| Source | How |
| --- | --- |
| Row changes | Eloquent `created`, `updated`, `deleted` and `restored` events with before and after snapshots. Hidden and secret-named attributes are `[REDACTED]`. |
| SQL | `QueryExecuted`, with text, bindings and timings. Values are shown only in `local`, `development` and `testing`, and anything near a secret-named binding or statement is `[REDACTED]`. |
| Logs | `Log::*` calls as `MessageLogged`. |
| Mail | `MessageSending`: captured, suppressed in a preview. |

Limits match the .NET adapter: 500 logs, 100 effects, 200 changes, 32 log properties and 64 SQL parameters. Truncation is reported with `logsTruncated`, `effectsTruncated` and `changesTruncated`.

## Known limits

- **Bulk and raw writes are not row changes.** `Model::where()->update()`, `DB::insert()`, `upsert()` and triggers fire no model events. They still run inside the transaction, are rolled back, and appear in the SQL log, but are missing from `changes`.
- **Only listed connections are rolled back.** Add every connection a previewed operation writes to in `sendproof.connections`. Other connections, caches, queues, files and external services are not undone.
- **Transaction escapes are refused before they are sent.** `COMMIT`, `ROLLBACK`, `BEGIN`, `SET autocommit` anywhere in a `SET`, and the internal `sendproof_preview` savepoint; on MySQL and MariaDB also every implicitly committing statement (DDL other than `TEMPORARY` tables, `LOCK TABLES`, `GRANT`, `CHECK TABLE`, replication control and the rest of MySQL's list), SQL-level `PREPARE` and `EXECUTE` (and MariaDB's `EXECUTE IMMEDIATE`), executable comments containing any of these, and `CALL`. The Preview answers 500 and nothing is written. Statements sent on `getPdo()` itself are not checked.
- **Stored procedures (MySQL and MariaDB).** A procedure can `COMMIT`, so `CALL` is refused unless you list it in `sendproof.safe_procedures` by exact name (`'refresh_totals'` or `'reports.rebuild'`; case-insensitive like MySQL; no wildcards). Sendproof trusts the list: a listed procedure that commits makes the Preview's writes durable, and the backstop below then answers 500.
- **Backstop (every database).** The Preview opens its transaction with `SAVEPOINT sendproof_preview` (`SAVE TRANSACTION` on SQL Server) and, before rolling back, rolls back to it (on SQL Server, `ROLLBACK TRANSACTION sendproof_preview`, which fails once the transaction has ended; `@@TRANCOUNT` can't tell, because `pdo_sqlsrv` runs with implicit transactions on and the next statement after a commit opens a new one). On SQL Server a Preview also refuses `SET IMPLICIT_TRANSACTIONS ON` (`OFF` stays allowed) and, once the server has rolled the Preview transaction back itself (a deadlock victim, error 1205, or any error under `XACT_ABORT ON`), every later statement, like the MySQL deadlock rule below. This is exercised against SQL Server 2022 with `pdo_sqlsrv`. Any commit erases the savepoint, so a Preview whose transaction was committed anyway (on the PDO directly, or by a listed procedure) answers 500 without confirmation. `PDO::inTransaction()` alone can't tell: with autocommit off MySQL opens a new transaction by itself after a commit. The writes made before that commit are durable; the check only stops the Preview from claiming otherwise.
- **Deadlocks (MySQL and MariaDB).** A deadlock (1213), or a lock wait timeout (1205) with `innodb_rollback_on_timeout` on, rolls back the whole Preview transaction, and PDO runs MySQL with autocommit on outside `BEGIN`, so a retry would commit for real. The handler still gets the original `QueryException`, but every later statement in that Preview is refused before it is sent, and the Preview answers 500 without confirmation. Laravel reports no failed query, so during a MySQL Preview the connection's PDO uses Sendproof's statement class (`PDO::ATTR_STATEMENT_CLASS`) to see the error, and puts the default back afterwards. A MySQL connection configured with its own statement class would hide the error, so Preview refuses to start there: it answers 409 before the handler runs, and capabilities list each operation as unavailable with that reason. The lock wait timeout case shares the deadlock code path and is covered by unit tests only, because `innodb_rollback_on_timeout` can be set only at server startup.
- **Laravel Octane on Swoole or OpenSwoole: no Preview.** The binding counts every write in the process as the open Preview's, because PHP normally runs one request per process at a time (php-fpm, `php -S`, Octane on RoadRunner or FrankenPHP, all supported). Swoole and OpenSwoole run requests concurrently in coroutines in one worker, so another request's write would be guarded or rolled back as the Preview's. There, every Preview answers 409 before the handler runs, capabilities list each opted-in operation under `previewUnavailable` with that reason, and Send and observed Send work as usual. Detection uses `LARAVEL_OCTANE` with `octane.server`, or a running coroutine; `sendproof.concurrent_workers` overrides it (`false` only for a server you run with one request per worker at a time).
- **Sessions and rate limiters** that use a store outside the listed connections are not rolled back.
- **`rowsAffected`** is not reported because Laravel's `QueryExecuted` does not expose it.
- **Queued jobs, HTTP client calls and notifications** are not captured automatically. Record them with the effect API.
- **Streamed and file responses** cannot be previewed.
- The preview middleware is prepended to the global stack, so it runs before `TrustProxies`. Behind a proxy that rewrites the host, the same-origin check compares the host Laravel sees.

## Configuration

See [`config/sendproof.php`](https://github.com/mprync/sendproof/blob/6d116184ca5d46a98a86dc2be09861542f0d48bf/adapters/php/laravel/config/sendproof.php). The common settings:

| Key | Purpose |
| --- | --- |
| `enabled`, `environments` | Opt-in switch and allowed `APP_ENV` values. |
| `openapi_url`, `documents` | Where your OpenAPI document lives. |
| `auth_providers` | Sign-in methods shown by the workbench: `[['label' => 'Staff', 'kind' => 'oidc', 'url' => '/login']]`. |
| `authorize` | Gate ability required to open the workbench and to preview or observe. |
| `connections` | Connections enrolled in a preview. |
| `trace.ttl` | Seconds an observed-send trace stays readable. Default 300. |
| `provider` | `'eloquent'`, `null` to disable previews, or your own `PreviewProvider`. |

Customise the identity shown in the workbench or the authorization check from a service provider:

```php
Sendproof::identifyUsing(fn ($request, $user) => $user ? ['name' => $user->email, 'role' => $user->role] : null);
Sendproof::authorizeUsing(fn ($request) => $request->user()?->isStaff() === true);
```

## Observed Send

Send an operation with `Prefer: trace`. The response is untouched, plus an `X-Sendproof-Trace` header when an authenticated user with a stable identifier made the call. The trace is readable at `/sendproof/_api/traces/{id}` only by that user, for `sendproof.trace.ttl` seconds, with at most 8 traces per user and 32 in total. Traces are files in `storage/framework/sendproof-traces`, so they work across PHP-FPM workers.

## Tests

```sh
composer install && vendor/bin/phpunit
```

The Laravel conformance fixture is in [`../examples/laravel-fixture`](https://github.com/mprync/sendproof/blob/6d116184ca5d46a98a86dc2be09861542f0d48bf/adapters/php/examples/laravel-fixture/README.md).

## Data stores

Preview can also cover MongoDB (`mongodb/laravel-mongodb`), Redis (Predis and phpredis), DynamoDB, Elasticsearch, OpenSearch and Firestore. List the stores in `sendproof.data_stores`, and mark an operation you know writes to a guarded store with `->sendproofPreview(false, 'Clears the Redis session')`. Only the clients you hook are covered. See [data stores in the PHP binding](https://github.com/mprync/sendproof/blob/6d116184ca5d46a98a86dc2be09861542f0d48bf/adapters/php/data-stores.md).
