<?php

declare(strict_types=1);

namespace Sendproof\Laravel\Redis;

use Sendproof\Core\Stores\CommandKind;
use Sendproof\Core\Stores\Redis\RedisHook;

/**
 * The phpredis hook shared by the guarded connection classes. A Laravel phpredis connection sends
 * every command through `command()`, so overriding it classifies each command before the client
 * writes it. Pipelines and `MULTI` objects reach the raw client, so they are wrapped; subscribing
 * is refused as a write, because a delivered message can't be recalled.
 *
 * Only commands sent through the Laravel connection are covered. The raw client (`->client()`)
 * and a `\Redis` object created elsewhere are not.
 */
trait GuardedPhpRedis
{
    private ?RedisHook $hook = null;

    /** Client methods that are not Redis commands: allowed, never logged. */
    private const CLIENT_METHODS = [
        'close', 'isconnected', 'getoption', 'setoption', 'getlasterror', 'clearlasterror', 'getreadtimeout', 'gettimeout', 'gethost', 'getport',
        'getdbnum', 'getauth', 'getpersistentid', 'getmode', 'connect', 'pconnect', 'open', 'popen', '_prefix', '_serialize', '_unserialize',
        '_pack', '_unpack', '_compress', '_uncompress', 'select', 'auth', 'ping', 'echo',
    ];

    /** Installs the guard. Returns the connection for chaining. */
    public function guardWith(RedisHook $hook): static
    {
        $this->hook = $hook;

        return $this;
    }

    public function command($method, array $parameters = [])
    {
        if ($this->hook === null || in_array(strtolower((string) $method), self::CLIENT_METHODS, true)) {
            return parent::command($method, $parameters);
        }
        [$name, $args] = GuardedRedisPipeline::redisCommand((string) $method, $parameters);
        $kind = $this->hook->check($name, $args);
        $started = hrtime(true);
        try {
            $result = parent::command($method, $parameters);
        } catch (\Throwable $error) {
            $this->hook->record($name, $args, $kind, $started, $error);
            throw $error;
        }
        $this->hook->record($name, $args, $kind, $started);

        // In MULTI or pipeline mode phpredis returns the client itself (`Redis::multi()->set(…)`):
        // hand back the guarded wrapper, or the chained commands would reach the raw client.
        return $result === $this->client ? new GuardedRedisPipeline($result, $this->hook) : $result;
    }

    public function pipeline(?callable $callback = null)
    {
        if ($this->hook === null) {
            return parent::pipeline($callback);
        }
        $hook = $this->hook;
        if ($callback === null) {
            return new GuardedRedisPipeline(parent::pipeline(), $hook);
        }

        return parent::pipeline(static fn ($pipe) => $callback(new GuardedRedisPipeline($pipe, $hook)));
    }

    public function transaction(?callable $callback = null)
    {
        if ($this->hook === null) {
            return parent::transaction($callback);
        }
        $hook = $this->hook;
        if ($callback === null) {
            return new GuardedRedisPipeline(parent::transaction(), $hook);
        }

        return parent::transaction(static fn ($transaction) => $callback(new GuardedRedisPipeline($transaction, $hook)));
    }

    public function subscribe($channels, \Closure $callback)
    {
        $this->hook?->check('SUBSCRIBE', [$channels]);

        return parent::subscribe($channels, $callback);
    }

    public function psubscribe($channels, \Closure $callback)
    {
        $this->hook?->check('PSUBSCRIBE', [$channels]);

        return parent::psubscribe($channels, $callback);
    }

    public function createSubscription($channels, \Closure $callback, $method = 'subscribe')
    {
        $this->hook?->check(strtoupper((string) $method), [$channels]);

        return parent::createSubscription($channels, $callback, $method);
    }
}
