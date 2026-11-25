<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\AsyncEvent\Kernel\Collector;

use Dtyq\AsyncEvent\AsyncListenerInterface;
use Dtyq\AsyncEvent\Kernel\Annotation\AsyncListener;
use Hyperf\Di\Annotation\AnnotationCollector;
use Hyperf\Event\Contract\ListenerInterface;

/**
 * 异步监听器收集器：从注解收集已注册的异步监听器，供执行器在实例化数据库记录中的 listener 之前校验。
 * 判定规则与 AsyncEventDispatcher 决定哪些监听器异步落库的规则一致，结果缓存在静态属性中。
 */
class AsyncListenerCollector
{
    /**
     * @var null|array<string, true> 已注册的异步监听器，键为小写类名
     */
    protected static ?array $listeners = null;

    /**
     * 返回带 #[AsyncListener] 注解的监听器集合，键为小写类名。
     *
     * @return array<string, true>
     */
    public static function list(): array
    {
        if (self::$listeners !== null) {
            return self::$listeners;
        }
        $listeners = [];
        foreach (AnnotationCollector::getClassesByAnnotation(AsyncListener::class) as $class => $annotation) {
            if (is_string($class) && class_exists($class) && is_subclass_of($class, ListenerInterface::class)) {
                $listeners[strtolower($class)] = true;
            }
        }
        self::$listeners = $listeners;
        return self::$listeners;
    }

    /**
     * 判断类名是否为已注册的异步监听器：带 #[AsyncListener] 注解，或实现 AsyncListenerInterface，且实现 ListenerInterface。
     */
    public static function isRegistered(string $listenerClass): bool
    {
        if ($listenerClass === '') {
            return false;
        }
        if (isset(self::list()[strtolower(ltrim($listenerClass, '\\'))])) {
            return true;
        }
        return class_exists($listenerClass)
            && is_subclass_of($listenerClass, AsyncListenerInterface::class)
            && is_subclass_of($listenerClass, ListenerInterface::class);
    }

    /**
     * 清空缓存，仅供测试在修改注解收集结果后使用。
     */
    public static function clear(): void
    {
        self::$listeners = null;
    }
}
