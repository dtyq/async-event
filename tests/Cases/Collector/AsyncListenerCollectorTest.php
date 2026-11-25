<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace HyperfTest\Cases\Collector;

use Dtyq\AsyncEvent\AsyncListenerInterface;
use Dtyq\AsyncEvent\Kernel\Annotation\AsyncListener;
use Dtyq\AsyncEvent\Kernel\Collector\AsyncListenerCollector;
use Hyperf\Di\Annotation\AnnotationCollector;
use Hyperf\Event\Contract\ListenerInterface;
use HyperfTest\Cases\AbstractTestCase;
use stdClass;

/**
 * 测试夹具：带 #[AsyncListener] 注解的监听器。
 */
class CollectorAnnotatedListener implements ListenerInterface
{
    public function listen(): array
    {
        return [];
    }

    public function process(object $event): void
    {
    }
}

/**
 * 测试夹具：通过接口声明为异步的监听器。
 */
class CollectorInterfaceListener implements ListenerInterface, AsyncListenerInterface
{
    public function listen(): array
    {
        return [];
    }

    public function process(object $event): void
    {
    }
}

/**
 * 测试夹具：普通同步监听器。
 */
class CollectorSyncListener implements ListenerInterface
{
    public function listen(): array
    {
        return [];
    }

    public function process(object $event): void
    {
    }
}

/**
 * 验证已注册异步监听器的判定：注解与接口两种方式放行，其余类拒绝。
 * @internal
 * @coversNothing
 */
class AsyncListenerCollectorTest extends AbstractTestCase
{
    protected function setUp(): void
    {
        AnnotationCollector::collectClass(CollectorAnnotatedListener::class, AsyncListener::class, new AsyncListener());
        AsyncListenerCollector::clear();
    }

    public function testRegisteredListeners(): void
    {
        $this->assertTrue(AsyncListenerCollector::isRegistered(CollectorAnnotatedListener::class));
        $this->assertTrue(AsyncListenerCollector::isRegistered('\\' . CollectorAnnotatedListener::class));
        $this->assertTrue(AsyncListenerCollector::isRegistered(CollectorInterfaceListener::class));
    }

    public function testRejectsOtherClasses(): void
    {
        $this->assertFalse(AsyncListenerCollector::isRegistered(CollectorSyncListener::class));
        $this->assertFalse(AsyncListenerCollector::isRegistered(stdClass::class));
        $this->assertFalse(AsyncListenerCollector::isRegistered('Not\Existing\ClassName'));
        $this->assertFalse(AsyncListenerCollector::isRegistered(''));
    }
}
