<?php

declare(strict_types=1);

namespace Sendproof\Laravel\Redis;

use Illuminate\Redis\Connections\PhpRedisConnection;

/** A Laravel phpredis connection that classifies every command before it is written. See {@see GuardedPhpRedis}. */
final class GuardedPhpRedisConnection extends PhpRedisConnection
{
    use GuardedPhpRedis;
}
