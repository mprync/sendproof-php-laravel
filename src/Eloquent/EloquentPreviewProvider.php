<?php

declare(strict_types=1);

namespace Sendproof\Laravel\Eloquent;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Sendproof\Core\Capture\Recorder;
use Sendproof\Core\Capture\Statements;
use Sendproof\Core\Contracts\DescribesDatabaseSystem;
use Sendproof\Core\Contracts\PreviewObservation;
use Sendproof\Core\Contracts\PreviewProvider;
use Sendproof\Core\Contracts\PreviewSession;
use Sendproof\Core\Preview\PreviewTurn;
use Sendproof\Core\Preview\PreviewTurns;
use Sendproof\Core\Stores\PreviewTransactionControlException;

/**
 * Preview provider for Laravel's database layer.
 *
 * A preview opens a transaction on every configured connection before the request is dispatched,
 * so route middleware, the handler and queued after-commit work all run inside it. Eloquent
 * events supply the row changes. See {@see ChangeCollector} for what is and is not captured.
 */
class EloquentPreviewProvider implements PreviewProvider, DescribesDatabaseSystem
{
    /**
     * The savepoint a MySQL or MariaDB Preview opens its transaction with, for the backstop in
     * {@see EloquentSession::rollback()}. Laravel names its own savepoints `trans<n>`, so it never
     * makes this one, and a handler's `RELEASE` or `ROLLBACK TO` of it is refused like a `COMMIT`.
     */
    public const SAVEPOINT = 'sendproof_preview';

    /** @var \WeakMap<Connection,true>|null Connections that already refuse transaction control during a Preview */
    private static ?\WeakMap $guarded = null;

    /** @var list<string> Procedures the host declared never commit, lower-cased */
    protected array $safeProcedures;

    /**
     * @param list<string|null> $connections
     * @param Closure(): Recorder|null $recorder Resolves the request's recorder; without it a Preview
     *                                           does not refuse a handler's `COMMIT`
     * @param iterable<string> $safeProcedures On MySQL and MariaDB a Preview refuses `CALL`, because a
     *        stored procedure can commit the Preview transaction. List the procedures that never commit,
     *        by exact name (optionally `schema.name`; case-insensitive, like MySQL), to allow calling
     *        them. The binding trusts the list: a listed procedure that does commit makes the Preview's
     *        writes durable, and the savepoint check before the rollback then answers 500.
     * @throws \InvalidArgumentException when a safe procedure is not an exact name
     */
    public function __construct(
        protected DatabaseManager $database,
        protected ChangeCollector $collector,
        protected array $connections = [null],
        protected ?string $label = null,
        protected ?Closure $recorder = null,
        iterable $safeProcedures = [],
    ) {
        $this->safeProcedures = Statements::normalizeProcedures($safeProcedures);
    }

    public function databaseSystem(): ?string
    {
        try {
            return match ($this->database->connection($this->connections[0] ?? null)->getDriverName()) {
                'sqlite' => 'sqlite',
                'mysql' => 'mysql',
                'mariadb' => 'mariadb',
                'pgsql' => 'postgresql',
                'sqlsrv' => 'sqlserver',
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Whether Previews on this connection take turns. MySQL and MariaDB deadlock when three or
     * more Previews insert one new key at once and the first rolls back, at any isolation level.
     */
    protected function takesTurns(Connection $connection): bool
    {
        return in_array($connection->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * Refuses, before it reaches the database, any statement that would end or escape the Preview
     * transaction (`COMMIT`, `ROLLBACK`, `BEGIN`, `SET autocommit`, the {@see SAVEPOINT}, and on
     * MySQL the statements it commits implicitly, `EXECUTE` and `CALL` of an undeclared procedure).
     * The refusal also fails the Preview with 500, even if the handler catches it. Savepoints stay
     * allowed.
     */
    protected function refuseTransactionControl(Connection $connection): void
    {
        if ($this->recorder === null) {
            return;
        }
        self::$guarded ??= new \WeakMap();
        if (isset(self::$guarded[$connection])) {
            return;
        }
        self::$guarded[$connection] = true;
        $mysql = $this->takesTurns($connection);
        $sqlServer = self::sqlServer($connection);
        $recorder = $this->recorder;
        $safe = $this->safeProcedures;
        $key = self::endedKey($connection);
        $connection->beforeExecuting(static function (string $query) use ($mysql, $sqlServer, $recorder, $safe, $connection, $key): void {
            $current = $recorder();
            if (!$current->isPreview()) {
                return;
            }
            // An `unprepared()` statement runs on the PDO itself; its error stays on the PDO until the next call.
            $pdo = $connection->getRawPdo();
            if (($mysql || $sqlServer) && $pdo instanceof \PDO) {
                self::noticeServerRollback($current, $key, $pdo, $pdo->errorInfo()[1] ?? null, $sqlServer);
            }
            if (($ended = $current->transactionEnded($key)) !== null) {
                // The server already ended the Preview transaction: this statement would run outside it.
                $error = new PreviewTransactionControlException("A statement was refused during Preview: {$ended}, so it would run outside the Preview transaction.");
                $current->refuse($error);

                throw $error;
            }
            if (($reason = Statements::reason($query, $mysql, $safe, self::SAVEPOINT)) !== null) {
                $error = new PreviewTransactionControlException("A statement that would end the Preview transaction was refused during Preview: {$reason}.");
                $current->refuse($error);

                throw $error;
            }
        });
    }

    /** The recorder's key for a connection whose Preview transaction the server may end. */
    private static function endedKey(Connection $connection): string
    {
        return 'eloquent:' . $connection->getName();
    }

    /** Whether the connection is SQL Server, which marks a savepoint with `SAVE TRANSACTION`. */
    private static function sqlServer(Connection $connection): bool
    {
        return $connection->getDriverName() === 'sqlsrv';
    }

    /**
     * Records a MySQL deadlock (or a lock wait timeout with `innodb_rollback_on_timeout`) that ended the
     * Preview transaction on `$pdo`, so every later statement in the Preview is refused. On SQL Server
     * (`$sqlServer`) a deadlock victim (1205) does, and so does any other server error that leaves
     * no transaction open or runs under `XACT_ABORT ON` (see {@see Statements::sqlServerTransactionGone()}).
     */
    private static function noticeServerRollback(Recorder $recorder, string $key, \PDO $pdo, int|string|null $code, bool $sqlServer = false): void
    {
        if ($code === null || $recorder->transactionEnded($key) !== null) {
            return;
        }
        $reason = Statements::serverEndedTransaction($code, true, static function () use ($pdo): bool {
            try {
                return (int) $pdo->query('SELECT @@innodb_rollback_on_timeout')->fetchColumn() === 1;
            } catch (\Throwable) {
                return true; // unknown: assume the transaction is gone
            }
        }, $sqlServer);
        if ($reason === null && $sqlServer) {
            try {
                $statement = $pdo->query(Statements::SQL_SERVER_STATE);
                $state = $statement === false ? null : ($statement->fetch(\PDO::FETCH_NUM) ?: null);
                $statement?->closeCursor();
            } catch (\Throwable) {
                $state = null; // unknown: assume the transaction is gone
            }
            $reason = Statements::sqlServerTransactionGone($state) ? 'the server rolled back the Preview transaction' : null;
        }
        if ($reason !== null) {
            $recorder->endTransaction($key, $reason);
        }
    }

    /** Why Preview is refused on a MySQL connection that uses the application's own statement class. */
    public const CUSTOM_STATEMENT_CLASS_REASON = 'Preview unavailable: a MySQL or SQL Server connection uses its own PDO statement class, '
        . 'so Sendproof could not see a deadlock that ends the Preview transaction and stop a retry from committing.';

    /**
     * The reason Previews can't run on the enrolled connections, or null when they can.
     *
     * Sendproof sees a MySQL deadlock through its own statement class (see {@see WatchedStatement}). A
     * MySQL or MariaDB connection whose PDO already uses another class (`PDO::ATTR_STATEMENT_CLASS`) would
     * hide it, and a retry after the deadlock would commit for real, so Preview refuses to start there.
     */
    public function unavailableReason(): ?string
    {
        if ($this->recorder === null) {
            return null;
        }
        foreach ($this->connections as $name) {
            try {
                $connection = $this->database->connection($name);
                if (in_array($connection->getDriverName(), ['mysql', 'mariadb', 'sqlsrv'], true) && self::customStatementClass($connection->getPdo())) {
                    return self::CUSTOM_STATEMENT_CLASS_REASON;
                }
            } catch (\Throwable) {
                // An unreachable connection is reported by isAvailable().
            }
        }

        return null;
    }

    /** Whether the PDO uses a statement class other than PDO's own (or Sendproof's, mid-Preview). */
    private static function customStatementClass(\PDO $pdo): bool
    {
        $class = $pdo->getAttribute(\PDO::ATTR_STATEMENT_CLASS)[0] ?? \PDOStatement::class;

        return $class !== \PDOStatement::class && $class !== WatchedStatement::class;
    }

    /**
     * Watches every prepared statement on a MySQL Preview connection for the errors that end the Preview
     * transaction (see {@see WatchedStatement}), and answers the closure that puts PDO's own statement
     * class back. A connection with the application's own statement class fails closed: the Preview
     * doesn't start (the middleware refuses it earlier, with {@see unavailableReason()}).
     *
     * @return Closure(): void|null
     * @throws \RuntimeException for a connection with its own statement class
     */
    private function watchStatements(Connection $connection): ?Closure
    {
        if ($this->recorder === null) {
            return null;
        }
        $pdo = $connection->getPdo();
        if (self::customStatementClass($pdo)) {
            throw new \RuntimeException(self::CUSTOM_STATEMENT_CLASS_REASON);
        }
        $recorder = $this->recorder;
        $key = self::endedKey($connection);
        $sqlServer = self::sqlServer($connection);
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [WatchedStatement::class, [static function (\PDOException $error) use ($recorder, $key, $pdo, $sqlServer): void {
            $current = $recorder();
            if ($current->isPreview()) {
                self::noticeServerRollback($current, $key, $pdo, $error->errorInfo[1] ?? null, $sqlServer);
            }
        }]]);

        return static function () use ($pdo): void {
            $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);
        };
    }

    public function isAvailable(): bool
    {
        try {
            foreach ($this->connections as $name) {
                $this->database->connection($name);
            }

            return $this->connections !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    public function database(): ?string
    {
        if ($this->label !== null && $this->label !== '') {
            return $this->label;
        }
        $name = $this->database->connection($this->connections[0] ?? null)->getDatabaseName();

        return $name === ':memory:' || $name === '' ? $name : basename(str_replace('\\', '/', (string) $name));
    }

    public function begin(mixed $context): PreviewSession
    {
        $enrolled = [];
        $turns = [];
        $restore = [];
        try {
            foreach ($this->connections as $name) {
                $connection = $this->database->connection($name);
                $this->refuseTransactionControl($connection);
                if ($this->takesTurns($connection)) {
                    // One Preview at a time per database, taken before the connection is touched.
                    $turns[] = PreviewTurns::take($this->turnKey($connection));
                }
                $before = $connection->transactionLevel();
                $connection->beginTransaction();
                $enrolled[] = [$connection, $before, true];
                // The backstop's mark, on every database, made first thing in the Preview transaction on
                // the PDO itself, so it neither passes the handler's guard nor shows in the SQL log.
                $connection->getPdo()->exec((self::sqlServer($connection) ? 'SAVE TRANSACTION ' : 'SAVEPOINT ') . self::SAVEPOINT);
                if (in_array($connection->getDriverName(), ['mysql', 'mariadb', 'sqlsrv'], true) && ($undo = $this->watchStatements($connection)) !== null) {
                    $restore[] = $undo;
                }
            }
        } catch (\Throwable $error) {
            foreach ($restore as $undo) {
                $undo();
            }
            foreach ($turns as $turn) {
                $turn->release();
            }
            foreach ($enrolled as [$connection, $before, $_]) {
                try {
                    $connection->rollBack($before);
                } catch (\Throwable) {
                    // Nothing more can be done; the original failure is reported.
                }
            }
            throw $error;
        }

        $this->collector->start();

        return new EloquentSession($enrolled, $this->collector, $turns, $restore);
    }

    /** Identifies the database a connection points at, for its turn lock. */
    private function turnKey(Connection $connection): string
    {
        return implode('|', [(string) $connection->getConfig('host'), (string) $connection->getConfig('port'), (string) $connection->getDatabaseName()]);
    }

    public function beginObservation(mixed $context): ?PreviewObservation
    {
        $this->collector->start();

        return new ChangeObservation($this->collector);
    }
}
