<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\AsyncEvent\Demo\Subscribe;

use Dtyq\AsyncEvent\Demo\DemoEvent;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;

#[Listener]
class DemoSyncSubscriber implements ListenerInterface
{
    public function listen(): array
    {
        return [
            DemoEvent::class,
        ];
    }

    public function process(object $event): void
    {
        // Simulate processing time
        sleep(2);
        echo 'DemoSyncSubscriber processed event with data: ' . $event->getMessage() . PHP_EOL;
    }
}
