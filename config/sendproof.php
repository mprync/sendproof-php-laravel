<?php

declare(strict_types=1);

/*
 * Sendproof configuration.
 *
 * The workbench is opt-in. It is mounted only when `enabled` is true AND the application
 * environment is listed in `environments`. Production is never listed by default.
 */
return [
    // Master switch. Nothing is mounted and previews are refused unless this is true.
    'enabled' => (bool) env('SENDPROOF_ENABLED', false),

    // Application environments (APP_ENV) where the workbench may run.
    'environments' => array_values(array_filter(array_map('trim', explode(',', (string) env('SENDPROOF_ENVIRONMENTS', 'local'))))),

    // Display name in the workbench. Defaults to the application name.
    'api_name' => env('SENDPROOF_API_NAME'),

    // Application-relative URL of the OpenAPI document, plus any additional documents.
    'openapi_url' => '/openapi/v1.json',
    'documents' => [], // [['name' => 'Inventory', 'url' => '/openapi/v1.json']]

    // Arazzo 1.1 JSON files, e.g. [base_path('journeys/store.arazzo.json')].
    'journeys' => [],

    // Same-origin ES modules that add custom field renderers. Host configuration only.
    'field_modules' => [],

    // Sign-in methods advertised to the workbench: [['label' => 'Staff', 'kind' => 'oidc', 'url' => '/login']]
    'auth_providers' => [],

    // Middleware for the /sendproof routes. Null uses the `web` group when the application defines one.
    'middleware' => null,

    // Gate ability required to open the workbench and to preview or observe operations. Null = no extra check.
    'authorize' => null,

    // Auth guard used to resolve the signed-in user. Null uses the default guard.
    'guard' => null,

    // 'eloquent' (default), null to disable previews, or a class implementing PreviewProvider.
    'provider' => 'eloquent',

    // Database connections enrolled in a preview. Null is the default connection. Add every
    // connection a previewed operation writes to; unlisted connections are NOT rolled back.
    'connections' => [null],

    // MySQL and MariaDB: a Preview refuses CALL, because a stored procedure can commit the Preview
    // transaction. List procedures that never commit, by exact name ('refresh_totals' or
    // 'reports.rebuild'; case-insensitive; no wildcards). Sendproof trusts this list.
    'safe_procedures' => [],

    // Laravel Octane on Swoole or OpenSwoole runs requests concurrently in one worker, so Previews are
    // refused there (detected). True refuses, false allows: only for a server that runs one request per
    // worker at a time. Null detects.
    'concurrent_workers' => null,

    // Display label for the database. Defaults to the connection's database name without its path.
    'database_label' => env('SENDPROOF_DATABASE_LABEL'),

    // Show SQL parameter values. Null shows them in local, development and testing only.
    'include_sql_parameter_values' => null,

    // Data stores beyond SQL, hooked when Sendproof is enabled. Only the clients listed here are covered:
    // writes through any other client are neither blocked nor rolled back.
    //   'mongodb'  => ['connection' => 'mongodb', 'name' => 'catalog'],   // a mongodb/laravel-mongodb connection
    //   'redis'    => ['name' => 'cache'],                                  // every connection Laravel's Redis manager creates
    //   'dynamodb' => ['clients' => [DynamoDbClient::class], 'name' => 'orders'],
    //   'elasticsearch' => ['clients' => [Elastic\Elasticsearch\Client::class], 'name' => 'search'],
    //   'opensearch'    => ['clients' => [OpenSearch\Client::class], 'name' => 'search'],
    //   'firestore'     => ['clients' => [Google\Cloud\Firestore\FirestoreClient::class], 'name' => 'docs'],
    // Each also takes 'treat_as_read' (exact command names you state never write); mongodb takes 'preview_timeout_ms'.
    // elasticsearch and opensearch also take 'path_prefix' for a host such as https://proxy/search.
    // See adapters/php/data-stores.md. Mark an operation with ->sendproofPreview(false, 'reason') when you know it writes to one.
    'data_stores' => [],

    'trace' => [
        // How long an observed send trace can be read, in seconds.
        'ttl' => (int) env('SENDPROOF_TRACE_TTL', 300),
        'per_owner' => 8,
        'total' => 32,
        // Private directory for traces. Defaults to storage/framework/sendproof-traces.
        'path' => null,
    ],

    // Directory holding the embedded UI bundle (manifest.json plus assets). Defaults to the package copy.
    'ui_path' => null,
];
