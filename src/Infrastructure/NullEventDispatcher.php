<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Payroad\Domain\DomainEvent;
use Payroad\Port\Event\DomainEventDispatcherInterface;

/**
 * No-op dispatcher — domain events are simply discarded.
 *
 * In a real application replace this with a Symfony Messenger dispatcher
 * (see payroad/payroad-symfony-demo for a full example).
 */
final class NullEventDispatcher implements DomainEventDispatcherInterface
{
    public function dispatch(DomainEvent ...$events): void
    {
        // events are intentionally ignored in the quickstart
    }
}
