<?php

declare(strict_types=1);

namespace Sendproof\Laravel\Eloquent;

use Sendproof\Core\Contracts\PreviewObservation;

/** Watches Eloquent changes during an ordinary send without touching its transaction. */
final class ChangeObservation implements PreviewObservation
{
    public function __construct(private readonly ChangeCollector $collector)
    {
    }

    public function changes(): array
    {
        return $this->collector->changes();
    }

    public function changesTruncated(): bool
    {
        return $this->collector->truncated();
    }

    public function dispose(): void
    {
        $this->collector->stop();
    }
}
