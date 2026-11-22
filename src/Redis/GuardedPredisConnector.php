<?php

declare(strict_types=1);

namespace Sendproof\Laravel\Redis;

use Illuminate\Redis\Connectors\PredisConnector;
use Sendproof\Core\Stores\Guard;
use Sendproof\Core\Stores\Redis\PredisGuard;
use Sendproof\Core\Stores\Redis\RedisHook;

/**
 * Builds Predis connections that Sendproof guards. Registered with `Redis::extend()` in place of
 * Laravel's own connector: it builds the same connection and hooks its client in place.
 */
final class GuardedPredisConnector extends PredisConnector
{
    /** @param array<string,true>|null $reads Host-declared reads, upper-case names */
    public function __construct(private readonly Guard $guard, private readonly ?array $reads = null)
    {
    }

    public function connect(array $config, array $options)
    {
        $connection = parent::connect($config, $options);
        PredisGuard::install($connection->client(), new RedisHook($this->guard, $this->reads, (string) ($config['database'] ?? 0)));

        return $connection;
    }

    public function connectToCluster(array $config, array $clusterOptions, array $options)
    {
        $connection = parent::connectToCluster($config, $clusterOptions, $options);
        PredisGuard::install($connection->client(), new RedisHook($this->guard, $this->reads, null));

        return $connection;
    }
}
