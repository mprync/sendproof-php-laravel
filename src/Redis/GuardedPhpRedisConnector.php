<?php

declare(strict_types=1);

namespace Sendproof\Laravel\Redis;

use Illuminate\Redis\Connectors\PhpRedisConnector;
use Illuminate\Support\Arr;
use Sendproof\Core\Stores\Guard;
use Sendproof\Core\Stores\Redis\RedisHook;

/**
 * Builds phpredis connections that Sendproof guards. Registered with `Redis::extend()` in place of
 * Laravel's own connector; it builds the same connections with the same configuration.
 */
final class GuardedPhpRedisConnector extends PhpRedisConnector
{
    /** @param array<string,true>|null $reads Host-declared reads, upper-case names */
    public function __construct(private readonly Guard $guard, private readonly ?array $reads = null)
    {
    }

    public function connect(array $config, array $options)
    {
        $formattedOptions = Arr::pull($config, 'options', []);
        if (isset($config['prefix'])) {
            $formattedOptions['prefix'] = $config['prefix'];
        }
        $connector = function () use ($config, $options, $formattedOptions) {
            return $this->createClient(array_merge($config, $options, $formattedOptions));
        };

        return (new GuardedPhpRedisConnection($connector(), $connector, $config))
            ->guardWith(new RedisHook($this->guard, $this->reads, (string) ($config['database'] ?? 0)));
    }

    public function connectToCluster(array $config, array $clusterOptions, array $options)
    {
        $options = array_merge($options, $clusterOptions, Arr::pull($config, 'options', []));
        $connector = fn () => $this->createRedisClusterInstance(array_map($this->buildClusterConnectionString(...), $config), $options);

        return (new GuardedPhpRedisClusterConnection($connector(), $connector, array_merge($config, [
            'command_retries' => (int) Arr::get($options, 'command_retries', 0),
        ])))->guardWith(new RedisHook($this->guard, $this->reads, null));
    }
}
