<?php

declare(strict_types=1);

namespace Sendproof\Laravel\Capture;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Log\Events\MessageLogged;
use Sendproof\Core\Capture\Recorder;
use Sendproof\Core\Capture\SqlRedactor;
use Sendproof\Core\Stores\PreviewTransactionControlException;
use Sendproof\Laravel\Eloquent\ChangeCollector;

/**
 * Subscribes once to Laravel's events and feeds the current request's recorder.
 *
 * Every listener returns immediately unless a preview or observed send is capturing, so the
 * cost for ordinary requests is a single flag check.
 */
final class CaptureListeners
{
    private const LEVELS = [
        'debug' => 'Debug', 'info' => 'Information', 'notice' => 'Information', 'warning' => 'Warning',
        'error' => 'Error', 'critical' => 'Critical', 'alert' => 'Critical', 'emergency' => 'Critical',
    ];

    public function __construct(private readonly Application $app)
    {
    }

    public function register(Dispatcher $events): void
    {
        $events->listen(QueryExecuted::class, fn (QueryExecuted $event) => $this->query($event));
        $events->listen(MessageLogged::class, fn (MessageLogged $event) => $this->log($event));
        foreach (['created' => 'insert', 'updated' => 'update', 'restored' => 'update', 'deleted' => 'delete'] as $name => $operation) {
            $events->listen("eloquent.{$name}: *", function (string $event, array $payload) use ($name, $operation): void {
                $model = $payload[0] ?? null;
                if ($model instanceof Model) {
                    $soft = $name === 'deleted' && method_exists($model, 'isForceDeleting') && !$model->isForceDeleting();
                    $this->app->make(ChangeCollector::class)->record($soft ? 'update' : $operation, $model);
                }
            });
        }
        $events->listen(TransactionCommitting::class, fn (TransactionCommitting $event) => $this->committing($event));
        $events->listen(TransactionBeginning::class, fn () => $this->app->make(ChangeCollector::class)->transactionBegan());
        $events->listen(TransactionCommitted::class, fn () => $this->app->make(ChangeCollector::class)->transactionCommitted());
        $events->listen(TransactionRolledBack::class, fn () => $this->app->make(ChangeCollector::class)->transactionRolledBack());
        if (class_exists(\Illuminate\Mail\Events\MessageSending::class)) {
            $events->listen(\Illuminate\Mail\Events\MessageSending::class, fn ($event) => $this->mail($event));
        }
    }

    /**
     * Refuses a handler's `DB::commit()` that would commit the Preview transaction itself: Laravel
     * fires this event just before the real commit, so throwing keeps the commit from happening.
     * A commit at a deeper level only releases a nested transaction and is allowed.
     */
    private function committing(TransactionCommitting $event): void
    {
        $recorder = $this->app->make(Recorder::class);
        if (!$recorder->isPreview() || $event->connection->transactionLevel() !== 1 || $event->connection->getDriverName() === 'mongodb') {
            return;
        }
        $error = new PreviewTransactionControlException('A commit that would end the Preview transaction was refused during Preview.');
        $recorder->refuse($error);

        throw $error;
    }

    private function query(QueryExecuted $event): void
    {
        $recorder = $this->app->make(Recorder::class);
        // MongoDB commands arrive as query events too, but they are logged as `Db` entries by the
        // store hook, and their text carries the command's values.
        if (!$recorder->isCapturing() || $event->connection->getDriverName() === 'mongodb' || preg_match('/^\s*(?:release\s+|rollback\s+to\s+)?savepoint\b/i', $event->sql)) {
            return;
        }

        $bindings = [];
        $position = 0;
        foreach ($event->bindings as $key => $value) {
            $bindings[] = ['name' => is_string($key) ? ltrim($key, ':') : 'p' . $position, 'value' => $value];
            $position++;
        }
        $private = $this->touchesSessionStorage($event->sql, $event->connection->getTablePrefix());
        [$sql, $truncated] = SqlRedactor::entry($event->sql, $bindings, $this->showValues() && !$private);
        $recorder->recordSql((float) $event->time, $sql, $truncated, 'Illuminate.Database.Query');
    }

    private function log(MessageLogged $event): void
    {
        $recorder = $this->app->make(Recorder::class);
        if (!$recorder->isCapturing()) {
            return;
        }

        $context = $event->context;
        $exception = null;
        if (($context['exception'] ?? null) instanceof \Throwable) {
            $exception = ['type' => $context['exception']::class, 'message' => $context['exception']->getMessage()];
            unset($context['exception']);
        }
        $recorder->log(self::LEVELS[$event->level] ?? ucfirst((string) $event->level), (string) $event->message, 'Laravel.Log', $context, null, $exception);
    }

    /** Records outgoing mail and halts sending while a preview is running. */
    private function mail(object $event): ?bool
    {
        $recorder = $this->app->make(Recorder::class);
        if (!$recorder->isCapturing() || !method_exists($event, 'message') && !isset($event->message)) {
            return null;
        }

        $message = $event->message;
        $addresses = static fn (array $list): array => array_map(static fn ($address): string => $address->getAddress(), $list);
        $from = $addresses($message->getFrom())[0] ?? '';
        $suppress = $recorder->tryRecordEmail(
            'Mail: ' . $message->getSubject(),
            $from,
            $addresses($message->getTo()),
            $addresses($message->getCc()),
            (string) $message->getSubject(),
            (string) $message->getTextBody(),
            $message->getHtmlBody() === null ? null : (string) $message->getHtmlBody(),
        );

        return $suppress ? false : null;
    }

    /**
     * Whether a statement reads or writes the table that stores sessions.
     *
     * Its bindings carry the session id and the serialized session (including the CSRF token),
     * which must never reach an envelope or trace, so every value of such a statement is hidden.
     */
    private function touchesSessionStorage(string $sql, string $prefix): bool
    {
        $config = $this->app->make('config');
        $driver = $config->get('session.driver');
        $tables = [];
        if ($driver === 'database') {
            $tables[] = (string) $config->get('session.table', 'sessions');
        }
        // Cache-backed session drivers keep sessions in a cache store, which may be the database one.
        $store = match ($driver) {
            'database', null => null,
            'cache' => $config->get('session.store') ?? $config->get('cache.default'),
            default => $driver,
        };
        if ($store !== null && $config->get("cache.stores.{$store}.driver") === 'database') {
            $tables[] = (string) $config->get("cache.stores.{$store}.table", 'cache');
        }
        foreach ($tables as $table) {
            if ($table !== '' && preg_match('/(?<![\w$])' . preg_quote($prefix . $table, '/') . '(?![\w$])/i', $sql)) {
                return true;
            }
        }

        return false;
    }

    private function showValues(): bool
    {
        $configured = $this->app->make('config')->get('sendproof.include_sql_parameter_values');

        return $configured ?? $this->app->environment('local', 'development', 'testing');
    }
}
