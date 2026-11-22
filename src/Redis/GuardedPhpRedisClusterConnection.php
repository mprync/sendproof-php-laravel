<?php

declare(strict_types=1);

namespace Sendproof\Laravel\Redis;

use Illuminate\Redis\Connections\PhpRedisClusterConnection;

/** A Laravel phpredis cluster connection that classifies every command before it is written. See {@see GuardedPhpRedis}. */
final class GuardedPhpRedisClusterConnection extends PhpRedisClusterConnection
{
    use GuardedPhpRedis;
}
