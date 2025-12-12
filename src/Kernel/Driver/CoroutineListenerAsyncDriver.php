<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\AsyncEvent\Kernel\Driver;

use Dtyq\AsyncEvent\Kernel\Persistence\Model\AsyncEventModel;
use Dtyq\AsyncEvent\Kernel\Service\AsyncEventService;
use Dtyq\AsyncEvent\Kernel\Utils\Locker;
use Dtyq\AsyncEvent\Kernel\Utils\LogUtil;
use Hyperf\Engine\Coroutine;
use Psr\Container\ContainerInterface;
use Throwable;

class CoroutineListenerAsyncDriver implements ListenerAsyncDriverInterface
{
    private Locker $locker;

    private AsyncEventService $asyncEventService;

    public function __construct(
        ContainerInterface $container,
    ) {
        $this->locker = $container->get(Locker::class);
        $this->asyncEventService = $container->get(AsyncEventService::class);
    }

    public function publish(AsyncEventModel $asyncEventModel, object $event, callable $listener): void
    {
        Coroutine::defer(function () use ($asyncEventModel, $event, $listener) {
            $this->locker->get(function () use ($asyncEventModel, $listener, $event) {
                $exception = null;
                try {
                    $listener($event);
                    $this->asyncEventService->delete($asyncEventModel->id);
                } catch (Throwable $exception) {
                    $this->asyncEventService->markAsExecuting($asyncEventModel->id);
                } finally {
                    LogUtil::dump($asyncEventModel->id, $asyncEventModel->listener, $asyncEventModel->event, $exception);
                }
            }, "async_event_retry_{$asyncEventModel->id}");
        });
    }
}
