<?php

declare(strict_types=1);

namespace Sendproof\Laravel\Stores;

use Aws\DynamoDb\DynamoDbClient;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Redis\RedisManager;
use MongoDB\Laravel\Connection as MongoConnection;
use Psr\Log\LoggerInterface;
use Sendproof\Core\Stores\DataStore;
use Sendproof\Core\Stores\DynamoDb\DynamoGuard;
use Sendproof\Core\Stores\Firestore\FirestoreGuard;
use Sendproof\Core\Stores\Guard;
use Sendproof\Core\Stores\GuardOnlyStore;
use Sendproof\Core\Stores\MongoDb\GuardedClient;
use Sendproof\Core\Stores\MongoDb\MongoEngine;
use Sendproof\Core\Stores\MongoDb\MongoHook;
use Sendproof\Core\Stores\MongoDb\MongoStore;
use Sendproof\Core\Stores\Search\ElasticsearchGuard;
use Sendproof\Core\Stores\Search\OpenSearchGuard;
use Sendproof\Laravel\Redis\GuardedPhpRedisConnector;
use Sendproof\Laravel\Redis\GuardedPredisConnector;

/**
 * Hooks the data stores named in `sendproof.data_stores` and returns them for the Preview runner
 * and discovery. Each hook covers only the client it is installed on (see spec/data-stores.md):
 *
 * - `mongodb`: the named `mongodb/laravel-mongodb` connection. Its client and database are replaced
 *   in place by guarded ones, so Eloquent models, the query builder and `DB::connection()` use them.
 * - `redis`: Laravel's Redis manager. Every connection it creates afterwards is guarded.
 * - `dynamodb`: the listed `Aws\DynamoDb\DynamoDbClient` services, hooked in place.
 * - `elasticsearch`, `opensearch`, `firestore`: the listed client services (`Elastic\Elasticsearch\Client`,
 *   `OpenSearch\Client`, `Google\Cloud\Firestore\FirestoreClient`), hooked in place.
 */
final class DataStores
{
    /**
     * @param array<string,array<string,mixed>> $config The `data_stores` configuration
     * @return list<DataStore>
     */
    public static function hook(Application $app, array $config, Guard $guard): array
    {
        $stores = [];
        if (isset($config['mongodb'])) {
            $stores[] = self::mongodb($app, $config['mongodb'], $guard);
        }
        if (isset($config['redis'])) {
            $stores[] = self::redis($app, $config['redis'], $guard);
        }
        if (isset($config['dynamodb'])) {
            $stores[] = self::dynamodb($app, $config['dynamodb'], $guard);
        }
        if (isset($config['elasticsearch'])) {
            $stores[] = self::elasticsearch($app, $config['elasticsearch'], $guard);
        }
        if (isset($config['opensearch'])) {
            $stores[] = self::opensearch($app, $config['opensearch'], $guard);
        }
        if (isset($config['firestore'])) {
            $stores[] = self::firestore($app, $config['firestore'], $guard);
        }

        return $stores;
    }

    /**
     * Hooks every client the container resolves for the listed ids or class names, now and later, and
     * refuses a listed id that isn't a `$class`. A binding that isn't shared, or one the application
     * rebinds, builds new clients after boot: each is hooked as the container hands it out, so none
     * is left unguarded. A client the container doesn't hold is hooked with the manager's `hook*()` method.
     *
     * @param array<string,mixed> $config
     * @param class-string $class
     * @param \Closure(object):void $install
     */
    private static function hookClients(Application $app, array $config, string $class, string $option, \Closure $install): void
    {
        foreach ((array) ($config['clients'] ?? []) as $id) {
            if (is_string($id)) {
                $app->afterResolving($id, static function (mixed $client) use ($class, $install): void {
                    if ($client instanceof $class) {
                        $install($client);
                    }
                });
            }
            $client = is_object($id) ? $id : $app->make($id);
            if (!$client instanceof $class) {
                throw new \InvalidArgumentException(sprintf('sendproof.data_stores.%s must name %s services.', $option, $class));
            }
            $install($client);
        }
    }

    /** @param array<string,mixed> $config */
    private static function elasticsearch(Application $app, array $config, Guard $guard): GuardOnlyStore
    {
        $reads = Guard::readSet($config['treat_as_read'] ?? null);
        $prefix = isset($config['path_prefix']) ? (string) $config['path_prefix'] : null;
        self::hookClients($app, $config, \Elastic\Elasticsearch\Client::class, 'elasticsearch.clients', static function (object $client) use ($guard, $reads, $prefix): void {
            ElasticsearchGuard::install($client, $guard, $reads, $prefix);
        });

        return new GuardOnlyStore('elasticsearch', Guard::storeLabel($config['name'] ?? null));
    }

    /** @param array<string,mixed> $config */
    private static function opensearch(Application $app, array $config, Guard $guard): GuardOnlyStore
    {
        $reads = Guard::readSet($config['treat_as_read'] ?? null);
        $prefix = isset($config['path_prefix']) ? (string) $config['path_prefix'] : null;
        self::hookClients($app, $config, \OpenSearch\Client::class, 'opensearch.clients', static function (object $client) use ($guard, $reads, $prefix): void {
            OpenSearchGuard::install($client, $guard, $reads, $prefix);
        });

        return new GuardOnlyStore('opensearch', Guard::storeLabel($config['name'] ?? null));
    }

    /** @param array<string,mixed> $config */
    private static function firestore(Application $app, array $config, Guard $guard): GuardOnlyStore
    {
        $reads = Guard::readSet($config['treat_as_read'] ?? null);
        self::hookClients($app, $config, \Google\Cloud\Firestore\FirestoreClient::class, 'firestore.clients', static function (object $client) use ($guard, $reads): void {
            FirestoreGuard::install($client, $guard, $reads);
        });

        return new GuardOnlyStore('firestore', Guard::storeLabel($config['name'] ?? null));
    }

    /** @param array<string,mixed> $config */
    private static function mongodb(Application $app, array $config, Guard $guard): MongoStore
    {
        $connection = $app->make('db')->connection($config['connection'] ?? 'mongodb');
        if (!$connection instanceof MongoConnection) {
            throw new \InvalidArgumentException('sendproof.data_stores.mongodb.connection must name a mongodb/laravel-mongodb connection.');
        }
        $client = $connection->getClient();
        $engine = new MongoEngine(
            $client->getManager(),
            $guard,
            Guard::readSet($config['treat_as_read'] ?? null, strtolower(...)),
            (int) ($config['preview_timeout_ms'] ?? MongoEngine::DEFAULT_TIMEOUT_MS),
        );
        $guarded = new GuardedClient($client, new MongoHook($engine));
        // A Preview whose abort was lost holds its session until it is killed. Do it once each request
        // is done: a long-lived worker (Octane, RoadRunner, FrankenPHP) runs no shutdown function between
        // requests. A kill that fails is a warning in Laravel's log.
        MongoEngine::useLogger($app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null);
        $app->terminating(static fn () => MongoEngine::endLostTransactions());
        // The connection has no setter for its client, so replace it from inside. Its `getClient()`,
        // `getDatabase()` and `getCollection()` then hand out guarded handles, and the transaction
        // helpers (`DB::transaction()`) start their session on the guarded client.
        $install = static function (MongoConnection $connection) use ($guarded): void {
            \Closure::bind(function (GuardedClient $guarded): void {
                $this->connection = $guarded;
                $this->db = $guarded->getDatabase($this->database);
            }, $connection, MongoConnection::class)($guarded);
        };
        $install($connection);
        // `DB::purge()` or `DB::reconnect()` builds a new connection object with its own client:
        // it gets the same guarded client, so it never sends around the hook.
        $name = $connection->getName();
        $app->make('events')->listen(ConnectionEstablished::class, static function (ConnectionEstablished $event) use ($install, $name, $guarded): void {
            if ($event->connection instanceof MongoConnection && $event->connection->getName() === $name && $event->connection->getClient() !== $guarded) {
                $install($event->connection);
            }
        });

        return new MongoStore($engine, Guard::storeLabel($config['name'] ?? null));
    }

    /** @param array<string,mixed> $config */
    private static function redis(Application $app, array $config, Guard $guard): GuardOnlyStore
    {
        $reads = Guard::readSet($config['treat_as_read'] ?? null, strtoupper(...));
        /** @var RedisManager $manager */
        $manager = $app->make('redis');
        $driver = (string) $app['config']->get('database.redis.client', 'phpredis');
        $manager->extend($driver, fn () => $driver === 'predis' ? new GuardedPredisConnector($guard, $reads) : new GuardedPhpRedisConnector($guard, $reads));
        // Connections made before the hook was installed are dropped, so the next use builds a guarded one.
        $manager->purge();

        return new GuardOnlyStore('redis', Guard::storeLabel($config['name'] ?? null));
    }

    /** @param array<string,mixed> $config */
    private static function dynamodb(Application $app, array $config, Guard $guard): GuardOnlyStore
    {
        $reads = Guard::readSet($config['treat_as_read'] ?? null);
        foreach ((array) ($config['clients'] ?? []) as $id) {
            if (is_string($id)) {
                // A binding that isn't shared, or one the application rebinds, builds new clients
                // after boot: each is hooked as the container hands it out.
                $app->afterResolving($id, static function (mixed $client) use ($guard, $reads): void {
                    if ($client instanceof DynamoDbClient) {
                        DynamoGuard::install($client, $guard, $reads);
                    }
                });
            }
            $client = is_object($id) ? $id : $app->make($id);
            if (!$client instanceof DynamoDbClient) {
                throw new \InvalidArgumentException('sendproof.data_stores.dynamodb.clients must name Aws\DynamoDb\DynamoDbClient services.');
            }
            DynamoGuard::install($client, $guard, $reads);
        }

        return new GuardOnlyStore('dynamodb', Guard::storeLabel($config['name'] ?? null));
    }
}
