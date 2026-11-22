<?php

declare(strict_types=1);

namespace Sendproof\Laravel\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Sendproof\Core\Capture\SqlRedactor;
use Sendproof\Core\Limits;

/**
 * Records row changes made through Eloquent models while a preview or observed send runs.
 *
 * Coverage: `create`, `save`, `update`, `delete`, `restore` and `forceDelete` on model instances,
 * which fire Eloquent events. Query-builder and raw statements (`Model::where()->update()`,
 * `DB::insert()`, bulk `insert`/`upsert`) fire no model events, so they appear in the SQL log but
 * not as row changes. Changes made inside a nested transaction that rolls back are discarded.
 */
final class ChangeCollector
{
    private bool $active = false;
    private bool $truncated = false;
    /** @var list<array<string,mixed>> */
    private array $changes = [];
    /** @var list<int> Change counts at each nested transaction start */
    private array $marks = [];

    public function start(): void
    {
        $this->active = true;
        $this->truncated = false;
        $this->changes = [];
        $this->marks = [];
    }

    public function stop(): void
    {
        $this->active = false;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /** @return list<array<string,mixed>> */
    public function changes(): array
    {
        return $this->changes;
    }

    public function truncated(): bool
    {
        return $this->truncated;
    }

    /** @param 'insert'|'update'|'delete' $operation */
    public function record(string $operation, Model $model): void
    {
        // A MongoDB model fires the same events, but its changes are read back by the MongoDB store, with
        // whole nested documents. Recording them here too would list each change twice.
        if (!$this->active || $model->getConnection()->getDriverName() === 'mongodb') {
            return;
        }
        if (count($this->changes) >= Limits::CHANGES) {
            $this->truncated = true;
            return;
        }

        $before = $operation === 'insert' ? null : $this->row($model, $model->getRawOriginal(), $model);
        $after = $operation === 'delete' ? null : $this->row($model, $model->getAttributes(), $model);
        $this->changes[] = [
            'operation' => $operation,
            'table' => $model->getTable(),
            'key' => $this->row($model, [$model->getKeyName() => $model->getKey()], $model),
            'before' => $before,
            'after' => $after,
        ];
    }

    /** A nested transaction (savepoint) began. */
    public function transactionBegan(): void
    {
        if ($this->active) {
            $this->marks[] = count($this->changes);
        }
    }

    /** A nested transaction was released. */
    public function transactionCommitted(): void
    {
        if ($this->active) {
            array_pop($this->marks);
        }
    }

    /** A nested transaction rolled back, so its changes never happened. */
    public function transactionRolledBack(): void
    {
        if ($this->active && $this->marks !== []) {
            $mark = array_pop($this->marks);
            $this->changes = array_slice($this->changes, 0, $mark);
        }
    }

    /**
     * Hides hidden and secret-looking columns so snapshots do not expose credentials.
     *
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    private function row(Model $model, array $attributes, Model $source): array
    {
        $hidden = $source->getHidden();
        foreach ($attributes as $name => $value) {
            if (in_array($name, $hidden, true) || SqlRedactor::looksSecret((string) $name)) {
                $attributes[$name] = Limits::REDACTED;
            }
        }

        return $attributes;
    }
}
