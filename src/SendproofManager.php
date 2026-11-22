<?php

declare(strict_types=1);

namespace Sendproof\Laravel;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Sendproof\Core\Capture\Recorder;
use Sendproof\Core\Contracts\PreviewProvider;
use Sendproof\Core\Preview\NoSqlProvider;
use Sendproof\Core\Stores\DataStore;
use Sendproof\Core\Stores\Guard;
use Sendproof\Core\Trace\FileTraceStore;
use Sendproof\Core\Trace\TraceOwner;
use Sendproof\Core\Trace\TraceStore;
use Sendproof\Core\Ui\UiBundle;
use Sendproof\Laravel\Eloquent\EloquentPreviewProvider;
use Sendproof\Laravel\Stores\DataStores;

/**
 * Application-level services for Sendproof: opt-in, identity, authorization, provider and effects.
 *
 * Effect recording is forwarded to the request's recorder, so `Sendproof::tryRecordEmail(...)`
 * works from any handler.
 */
final class SendproofManager
{
    private ?Closure $identity = null;
    private ?Closure $authorizer = null;
    private ?PreviewProvider $provider = null;
    private bool $providerResolved = false;
    private ?TraceStore $store = null;
    private ?Guard $guard = null;
    /** @var list<DataStore> */
    private array $stores = [];
    private bool $storesHooked = false;

    public function __construct(private readonly Application $app)
    {
    }

    /** True only when explicitly enabled and the environment is allow-listed. */
    public function enabled(): bool
    {
        return (bool) $this->config('enabled', false)
            && in_array($this->app->environment(), (array) $this->config('environments', []), true);
    }

    /** Overrides how the signed-in user is shown: `fn (Request $request, ?Authenticatable $user): ?array{name:?string,role?:string}`. */
    public function identifyUsing(Closure $resolver): void
    {
        $this->identity = $resolver;
    }

    /** Overrides the workbench authorization check: `fn (Request $request): bool`. */
    public function authorizeUsing(Closure $authorizer): void
    {
        $this->authorizer = $authorizer;
    }

    /** @return array{name:?string,role?:?string}|null */
    public function identity(Request $request): ?array
    {
        $user = $this->user();
        if ($this->identity !== null) {
            return ($this->identity)($request, $user);
        }

        return $user === null ? null : [
            'name' => $this->attribute($user, 'name') ?? $this->attribute($user, 'email'),
            'role' => $this->attribute($user, 'role'),
        ];
    }

    /** Key that scopes traces to the authenticated subject, or null when there is no stable subject. */
    public function ownerKey(Request $request): ?string
    {
        $user = $this->user();
        $subject = $user?->getAuthIdentifier();

        return TraceOwner::key($this->guardName(), $subject === null ? null : (string) $subject, $request->getBaseUrl());
    }

    /** Whether the current user may use the workbench. */
    public function authorized(Request $request): bool
    {
        if ($this->authorizer !== null) {
            return (bool) ($this->authorizer)($request);
        }
        $ability = $this->config('authorize');

        return $ability === null || $this->app->make('Illuminate\Contracts\Auth\Access\Gate')->allows($ability);
    }

    public function provider(): ?PreviewProvider
    {
        if (!$this->providerResolved) {
            $this->providerResolved = true;
            $configured = $this->config('provider', 'eloquent');
            $this->provider = match (true) {
                $configured === null || $configured === false => null,
                $configured === 'eloquent' => $this->app->make(EloquentPreviewProvider::class),
                default => $this->app->make($configured),
            };
        }

        return $this->provider;
    }

    /**
     * The provider a Preview runs on: the SQL provider, or when the application has none but hooked
     * data stores, a stand-in that rolls nothing back, so a MongoDB-only or guard-only application
     * can still Preview. A configured provider that can't enroll stays unavailable.
     */
    public function previewProvider(): ?PreviewProvider
    {
        $provider = $this->provider();

        return $provider ?? ($this->stores !== [] ? new NoSqlProvider() : null);
    }

    /** The write guard every store hook calls; it resolves the current request's recorder on each call. */
    public function guard(): Guard
    {
        return $this->guard ??= new Guard(fn (): Recorder => $this->app->make(Recorder::class));
    }

    /**
     * Hooks the stores named in `sendproof.data_stores`, once. Called at boot when Sendproof is
     * enabled, so applications that turn it off pay nothing and no client is touched.
     */
    public function hookDataStores(): void
    {
        if ($this->storesHooked) {
            return;
        }
        $this->storesHooked = true;
        $config = (array) $this->config('data_stores', []);
        if ($config !== []) {
            $this->stores = DataStores::hook($this->app, $config, $this->guard());
        }
    }

    /** @return list<DataStore> The hooked data stores, in configuration order */
    public function dataStores(): array
    {
        return $this->stores;
    }

    /**
     * Hooks a DynamoDB client at runtime, for clients the container doesn't hold (a client built
     * inside a service, or one per tenant). The client is guarded in place.
     */
    public function hookDynamoDb(\Aws\DynamoDb\DynamoDbClient $client, ?string $name = null): \Aws\DynamoDb\DynamoDbClient
    {
        \Sendproof\Core\Stores\DynamoDb\DynamoGuard::install($client, $this->guard());
        if (!array_filter($this->stores, static fn (DataStore $store): bool => $store->system() === 'dynamodb')) {
            $this->stores[] = new \Sendproof\Core\Stores\GuardOnlyStore('dynamodb', Guard::storeLabel($name));
        }

        return $client;
    }

    /**
     * Hooks an Elasticsearch client at runtime, for clients the container doesn't hold. The client is
     * guarded in place.
     */
    public function hookElasticsearch(\Elastic\Elasticsearch\Client $client, ?string $name = null): \Elastic\Elasticsearch\Client
    {
        \Sendproof\Core\Stores\Search\ElasticsearchGuard::install($client, $this->guard());
        $this->describeStore('elasticsearch', $name);

        return $client;
    }

    /**
     * Hooks an OpenSearch client at runtime, for clients the container doesn't hold. The client is
     * guarded in place.
     *
     * @throws \LogicException when the client sends through a transport Sendproof can't guard
     */
    public function hookOpenSearch(\OpenSearch\Client $client, ?string $name = null): \OpenSearch\Client
    {
        \Sendproof\Core\Stores\Search\OpenSearchGuard::install($client, $this->guard());
        $this->describeStore('opensearch', $name);

        return $client;
    }

    /**
     * Hooks a Firestore client at runtime, for clients the container doesn't hold. The client is
     * guarded in place.
     */
    public function hookFirestore(\Google\Cloud\Firestore\FirestoreClient $client, ?string $name = null): \Google\Cloud\Firestore\FirestoreClient
    {
        \Sendproof\Core\Stores\Firestore\FirestoreGuard::install($client, $this->guard());
        $this->describeStore('firestore', $name);

        return $client;
    }

    /** Adds a store to discovery once, for a client hooked at runtime. */
    private function describeStore(string $system, ?string $name): void
    {
        if (!array_filter($this->stores, static fn (DataStore $store): bool => $store->system() === $system)) {
            $this->stores[] = new \Sendproof\Core\Stores\GuardOnlyStore($system, Guard::storeLabel($name));
        }
    }

    public function traces(): TraceStore
    {
        return $this->store ??= new FileTraceStore(
            $this->config('trace.path') ?? $this->app->storagePath('framework/sendproof-traces'),
            max(1, (int) $this->config('trace.ttl', 300)),
            (int) $this->config('trace.per_owner', 8),
            (int) $this->config('trace.total', 32),
        );
    }

    public function ui(): UiBundle
    {
        return ($path = $this->config('ui_path')) === null ? UiBundle::embedded() : new UiBundle($path);
    }

    public function recorder(): Recorder
    {
        return $this->app->make(Recorder::class);
    }

    /** @param array<int,mixed> $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        return match ($method) {
            'isPreview' => $this->recorder()->isPreview(),
            default => $this->recorder()->{$method}(...$arguments),
        };
    }

    /** Why Preview is off in this worker, shown in capabilities and in the 409 a Preview request gets. */
    public const CONCURRENT_WORKER_REASON = 'Preview unavailable: this worker runs requests concurrently (Laravel Octane on Swoole or OpenSwoole), '
        . 'so a write could not be told apart from another request\'s and rolled back with the right Preview.';

    /**
     * The reason Previews are refused in this worker, or null when they may run.
     *
     * The PHP binding attributes every write in the process to the Preview that is open, because PHP
     * normally runs one request per process at a time (php-fpm, `php -S`, Octane on RoadRunner or
     * FrankenPHP). Laravel Octane on Swoole or OpenSwoole runs requests concurrently in coroutines in
     * one worker, so another request's write would be counted, guarded or rolled back as the
     * Preview's. Previews are therefore refused there. `sendproof.concurrent_workers` overrides the
     * detection: true refuses, false allows (only for a Swoole server configured to run one request
     * per worker at a time).
     */
    public function concurrencyReason(): ?string
    {
        $configured = $this->config('concurrent_workers');
        if (is_bool($configured)) {
            return $configured ? self::CONCURRENT_WORKER_REASON : null;
        }
        $octane = (bool) ($_SERVER['LARAVEL_OCTANE'] ?? $_ENV['LARAVEL_OCTANE'] ?? getenv('LARAVEL_OCTANE'));
        $server = strtolower((string) ($this->app->make('config')->get('octane.server') ?? getenv('OCTANE_SERVER') ?: ''));
        if ($octane && in_array($server, ['swoole', 'openswoole'], true)) {
            return self::CONCURRENT_WORKER_REASON;
        }
        // Any code running inside a Swoole or OpenSwoole coroutine, with or without Octane.
        foreach (['Swoole\\Coroutine', 'OpenSwoole\\Coroutine'] as $coroutine) {
            if (class_exists($coroutine, false) && method_exists($coroutine, 'getCid') && $coroutine::getCid() > 0) {
                return self::CONCURRENT_WORKER_REASON;
            }
        }

        return null;
    }

    /**
     * Why every Preview is refused right now, before any handler runs: a concurrent worker
     * ({@see concurrencyReason()}), or a provider that can't guard its connections
     * ({@see EloquentPreviewProvider::unavailableReason()}). Null when Previews may run.
     */
    public function previewRefusal(): ?string
    {
        if (($reason = $this->concurrencyReason()) !== null) {
            return $reason;
        }
        $provider = $this->provider();

        return $provider instanceof EloquentPreviewProvider ? $provider->unavailableReason() : null;
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->app->make('config')->get('sendproof.' . $key, $default);
    }

    public function guardName(): string
    {
        return $this->config('guard') ?? $this->app->make('auth')->getDefaultDriver();
    }

    private function user(): ?Authenticatable
    {
        return $this->app->make('auth')->guard($this->config('guard'))->user();
    }

    private function attribute(Authenticatable $user, string $name): ?string
    {
        $value = data_get($user, $name);

        return is_scalar($value) ? (string) $value : null;
    }
}
