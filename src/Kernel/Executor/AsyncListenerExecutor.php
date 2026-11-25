<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\AsyncEvent\Kernel\Executor;

use Dtyq\AsyncEvent\AsyncEventUtil;
use Dtyq\AsyncEvent\Kernel\Collector\AsyncListenerCollector;
use Dtyq\AsyncEvent\Kernel\Event\AsyncEventRetryMaxEvent;
use Dtyq\AsyncEvent\Kernel\Persistence\Model\AsyncEventModel;
use Dtyq\AsyncEvent\Kernel\Security\AsyncEventArgsUnserializer;
use Dtyq\AsyncEvent\Kernel\Security\AsyncEventPayloadException;
use Dtyq\AsyncEvent\Kernel\Service\AsyncEventService;
use Dtyq\AsyncEvent\Kernel\Utils\ContextDataUtil;
use Dtyq\AsyncEvent\Kernel\Utils\Locker;
use Dtyq\AsyncEvent\Kernel\Utils\LogUtil;
use Hyperf\Event\Contract\ListenerInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class AsyncListenerExecutor
{
    private Locker $locker;

    private AsyncEventService $asyncEventService;

    private LoggerInterface $logger;

    public function __construct(
        ContainerInterface $container,
    ) {
        $this->locker = $container->get(Locker::class);
        $this->asyncEventService = $container->get(AsyncEventService::class);
        $this->logger = $container->get(LoggerInterface::class);
    }

    public function runWithId(int $id, string $driver = ''): void
    {
        $this->locker->get(function () use ($id, $driver) {
            $record = $this->asyncEventService->getById($id);
            if (! $record) {
                return;
            }

            // Restore context data before executing listener
            $this->restoreContextData($record);

            $exception = null;
            try {
                $this->executeRecord($record);
            } catch (AsyncEventPayloadException $payloadException) {
                $exception = $payloadException;
                $this->rejectRecord($record, $payloadException);
            } catch (Throwable $throwable) {
                $exception = $throwable;
                $this->asyncEventService->markAsExecuting($record->id);
            } finally {
                LogUtil::dump($id, $record->listener, $record->event, $exception, $driver ? ['driver' => $driver] : []);
            }
        }, 'async_event_run_' . $id);
    }

    public function run(AsyncEventModel $asyncEventModel, object $event, callable $listener, string $driver = ''): void
    {
        $this->locker->get(function () use ($asyncEventModel, $listener, $event, $driver) {
            // Restore context data before executing listener
            $this->restoreContextData($asyncEventModel);

            $exception = null;
            try {
                $listener($event);
                $this->asyncEventService->delete($asyncEventModel->id);
            } catch (Throwable $exception) {
                $this->asyncEventService->markAsExecuting($asyncEventModel->id);
            } finally {
                LogUtil::dump($asyncEventModel->id, $asyncEventModel->listener, $asyncEventModel->event, $exception, $driver ? ['driver' => $driver] : []);
            }
        }, "async_event_run_{$asyncEventModel->id}");
    }

    public function retry(int $id): void
    {
        $this->locker->get(function () use ($id) {
            $record = $this->asyncEventService->getById($id);
            if (! $record) {
                return;
            }

            // Restore context data before executing listener (important for retry)
            $this->restoreContextData($record);

            $exception = null;
            try {
                $this->executeRecord($record);
            } catch (AsyncEventPayloadException $payloadException) {
                $exception = $payloadException;
                $this->rejectRecord($record, $payloadException);
            } catch (Throwable $throwable) {
                $exception = $throwable;
                $this->asyncEventService->retry($record->id);
                $this->logger->notice('AsyncEventRetryFailed', [
                    'id' => $id,
                    'exception' => $throwable->getMessage(),
                    'trace' => $throwable->getTraceAsString(),
                ]);
                if (($record->retry_times + 1) >= \Hyperf\Config\config('async_event.retry.times', 3)) {
                    $this->asyncEventService->fail($id);
                    AsyncEventUtil::dispatch(new AsyncEventRetryMaxEvent($record));
                }
            } finally {
                LogUtil::dump($id, $record->listener, $record->event, $exception);
            }
        }, 'async_event_run_' . $id);
    }

    /**
     * 执行数据库中的异步事件记录：先校验 listener 已注册，再实例化，校验事件类并受限反序列化 args，最后执行。
     * 记录中的 listener 与 args 都来自数据库，校验必须在实例化与反序列化之前完成。
     *
     * @throws AsyncEventPayloadException 记录未通过安全校验
     */
    private function executeRecord(AsyncEventModel $record): void
    {
        $listenerClass = (string) $record->listener;
        if (! AsyncListenerCollector::isRegistered($listenerClass)) {
            throw new AsyncEventPayloadException(sprintf('async listener %s is not registered', $listenerClass));
        }
        $listener = \Hyperf\Support\make($listenerClass);
        if (! $listener instanceof ListenerInterface) {
            throw new AsyncEventPayloadException(sprintf('async listener %s is not a listener', $listenerClass));
        }
        $event = AsyncEventArgsUnserializer::unserialize((string) $record->args, (string) $record->event, $listener->listen());
        $listener->process($event);
        $this->asyncEventService->delete($record->id);
    }

    /**
     * 安全校验失败属于数据问题，重试不会改变结果：直接标记失败，不进入重试计数，也不派发重试达上限事件。
     */
    private function rejectRecord(AsyncEventModel $record, AsyncEventPayloadException $exception): void
    {
        $this->asyncEventService->fail($record->id);
        // 只记录定位所需字段，不记录 args 原文
        $this->logger->warning('AsyncEventPayloadRejected', [
            'id' => $record->id,
            'listener' => $record->listener,
            'event' => $record->event,
            'reason' => $exception->getMessage(),
        ]);
    }

    private function restoreContextData(AsyncEventModel $record): void
    {
        if (empty($record->context_data)) {
            return;
        }

        if (is_array($record->context_data)) {
            ContextDataUtil::setContextData($record->context_data);
        }
    }
}
