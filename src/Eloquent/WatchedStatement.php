<?php

declare(strict_types=1);

namespace Sendproof\Laravel\Eloquent;

use Closure;

/**
 * The statement class a MySQL or MariaDB connection uses during a Preview (`PDO::ATTR_STATEMENT_CLASS`).
 *
 * Laravel reports no failed query, so the provider watches `execute()` itself: a deadlock (1213), or a
 * lock wait timeout (1205) with `innodb_rollback_on_timeout`, means the server rolled back the whole
 * Preview transaction. The error is passed to `$onError` and then rethrown unchanged, so the handler
 * still sees it.
 */
class WatchedStatement extends \PDOStatement
{
    /** @param Closure(\PDOException): void $onError */
    protected function __construct(private readonly Closure $onError)
    {
    }

    public function execute(?array $params = null): bool
    {
        try {
            return parent::execute($params);
        } catch (\PDOException $error) {
            ($this->onError)($error);

            throw $error;
        }
    }
}
