<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\AsyncEvent\Kernel\Security;

use __PHP_Incomplete_Class;
use DateTimeInterface;
use ReflectionClass;
use Serializable;
use Throwable;
use UnitEnum;

/**
 * 受限反序列化异步事件参数：只允许事件类型树中的类、树中类型在同一顶层命名空间下的实现与子类，
 * 以及与类型树同一顶层命名空间、且不带反序列化入口方法的类（兜底未声明元素类型的数组等宽松属性）。
 * 先以不允许任何类的方式解析出实际出现的类名并逐个校验，全部通过后才正式反序列化，名单外的类不会被实例化。
 */
class AsyncEventArgsUnserializer
{
    /**
     * 递归检查的最大深度，防止异常数据导致无限递归。
     */
    private const MAX_DEPTH = 256;

    private const INCOMPLETE_CLASS_NAME_KEY = '__PHP_Incomplete_Class_Name';

    /**
     * 始终允许的 PHP 内置类：日期类和 stdClass 没有可被利用的魔术方法，且不属于任何命名空间。
     */
    private const BUILTIN_SAFE_CLASSES = ['DateTime', 'DateTimeImmutable', 'DateTimeZone', 'DateInterval', 'DatePeriod', 'stdClass'];

    /**
     * 允许作为 DateTimeInterface 实现出现的日期库顶层命名空间（小写）。事件中常以 DateTimeInterface 声明、实际存放 Carbon。
     */
    private const DATE_LIBRARY_ROOTS = ['carbon'];

    /**
     * 反序列化利用链的入口方法：反序列化完成或对象销毁时由 PHP 自动调用。兜底放行的类不能定义这些方法。
     */
    private const UNSERIALIZE_ENTRY_METHODS = ['__wakeup', '__unserialize', '__destruct'];

    /**
     * 反序列化记录中的 args，返回事件对象；任何一项校验不通过都抛出 AsyncEventPayloadException。
     *
     * @param string $args 记录中的序列化参数
     * @param string $eventClass 记录中的事件类名
     * @param array<int|string, mixed> $listenedEvents 监听器 listen() 声明的事件类
     */
    public static function unserialize(string $args, string $eventClass, array $listenedEvents): object
    {
        $eventClass = ltrim($eventClass, '\\');
        self::assertListenedEvent($eventClass, $listenedEvents);

        // 第一遍：不允许任何类，对象全部成为不完整对象，不会实例化真实的类，也不会触发魔术方法
        $raw = @unserialize($args, ['allowed_classes' => false]);
        if (! $raw instanceof __PHP_Incomplete_Class) {
            throw new AsyncEventPayloadException('async event args top level is not an object');
        }
        $names = [];
        $seen = [];
        self::collectClassNames($raw, $names, $seen, 0);
        $topName = (string) (((array) $raw)[self::INCOMPLETE_CLASS_NAME_KEY] ?? '');
        if (strcasecmp($topName, $eventClass) !== 0) {
            throw new AsyncEventPayloadException(sprintf('async event args class %s does not match event %s', $topName, $eventClass));
        }

        $allowed = self::allowedClassNames($eventClass, $names);

        // 第二遍：只允许已校验的类；带类型属性遇到名单外的类时 PHP 会抛出 TypeError
        try {
            $event = unserialize($args, ['allowed_classes' => array_values($allowed)]);
        } catch (Throwable $throwable) {
            throw new AsyncEventPayloadException('async event args unserialize failed: ' . $throwable->getMessage(), 0, $throwable);
        }
        if (! is_object($event) || strcasecmp(get_class($event), $eventClass) !== 0) {
            throw new AsyncEventPayloadException('async event args unserialize result does not match event');
        }
        $seen = [];
        if (self::containsIncompleteClass($event, $seen, 0)) {
            throw new AsyncEventPayloadException('async event args contains classes outside the event definition');
        }
        return $event;
    }

    /**
     * 事件类必须是监听器声明的事件或其子类；通过父类或接口匹配时，事件类须与声明类型位于同一顶层命名空间。
     *
     * @param array<int|string, mixed> $listenedEvents
     */
    private static function assertListenedEvent(string $eventClass, array $listenedEvents): void
    {
        if ($eventClass === '' || ! class_exists($eventClass)) {
            throw new AsyncEventPayloadException(sprintf('async event class %s does not exist', $eventClass));
        }
        foreach ($listenedEvents as $declared) {
            if (! is_string($declared) || $declared === '') {
                continue;
            }
            $declared = ltrim($declared, '\\');
            if (strcasecmp($declared, $eventClass) === 0) {
                return;
            }
            if (is_a($eventClass, $declared, true) && self::sameRootNamespace($eventClass, $declared)) {
                return;
            }
        }
        throw new AsyncEventPayloadException(sprintf('async event class %s is not listened by the listener', $eventClass));
    }

    /**
     * 根据事件类型树计算允许的类。不在树中的类名，满足以下任一条件才放行，并继续展开其类型树：
     * 是树中接口、抽象类或具体类在同一顶层命名空间下的实现或子类（例如扩展包继承主仓库实体）；
     * 或是 DateTimeInterface 在已知日期库命名空间下的实现（如 Carbon）。
     * 仍未放行的类名再走兜底规则（见 isInertClassInRoots）；兜底放行的类不展开其类型树，其中出现的类各自校验。
     *
     * @param array<string, string> $names 第一遍解析出的类名，键为小写类名
     * @return array<string, string> 允许的类，键为小写类名
     */
    private static function allowedClassNames(string $eventClass, array $names): array
    {
        $tree = EventPayloadClassResolver::resolve($eventClass);
        $classes = $tree['classes'];
        foreach (self::BUILTIN_SAFE_CLASSES as $builtin) {
            $classes[strtolower($builtin)] = $builtin;
        }
        $abstracts = $tree['abstracts'];

        do {
            $changed = false;
            foreach ($names as $key => $name) {
                if (isset($classes[$key]) || ! self::isAcceptedExtension($name, $abstracts + $classes)) {
                    continue;
                }
                $extension = EventPayloadClassResolver::resolve($name);
                $classes += $extension['classes'];
                $abstracts += $extension['abstracts'];
                $changed = true;
            }
        } while ($changed);

        $roots = self::rootNamespaces($classes);
        foreach (array_diff_key($names, $classes) as $key => $name) {
            if (self::isInertClassInRoots($name, $roots)) {
                $classes[$key] = $name;
            }
        }

        $rejected = array_diff_key($names, $classes);
        if ($rejected !== []) {
            throw new AsyncEventPayloadException('async event args contains classes outside the event definition: ' . implode(', ', $rejected));
        }
        return array_intersect_key($classes, $names);
    }

    /**
     * 判断类型树之外的类名能否放行：先做命名空间字符串判断再加载类，避免加载其他命名空间的类。
     *
     * @param array<string, string> $bases 类型树中的接口、抽象类和具体类
     */
    private static function isAcceptedExtension(string $name, array $bases): bool
    {
        $root = strstr(ltrim($name, '\\'), '\\', true);
        if ($root !== false && in_array(strtolower($root), self::DATE_LIBRARY_ROOTS, true)) {
            return class_exists($name) && is_subclass_of($name, DateTimeInterface::class);
        }
        foreach ($bases as $base) {
            if (self::sameRootNamespace($name, $base) && class_exists($name) && is_subclass_of($name, $base)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 兜底规则：类型树之外、位于类型树某个顶层命名空间下的用户类，只要没有反序列化入口方法、也没有实现 Serializable，就放行。
     * 用于未声明元素类型的数组等宽松属性中存放的项目内部对象（例如只声明为 array、实际存放值对象的属性）。
     * 这类对象在反序列化时不会自动执行任何代码，无法作为利用链的起点；先做命名空间字符串判断再加载类，避免加载其他命名空间的类。
     *
     * @param array<string, true> $roots 小写顶层命名空间
     */
    private static function isInertClassInRoots(string $name, array $roots): bool
    {
        $root = strstr(ltrim($name, '\\'), '\\', true);
        if ($root === false || ! isset($roots[strtolower($root)]) || ! class_exists($name)) {
            return false;
        }
        $reflection = new ReflectionClass($name);
        if ($reflection->isInternal() || ! $reflection->isInstantiable() || $reflection->implementsInterface(Serializable::class)) {
            return false;
        }
        foreach (self::UNSERIALIZE_ENTRY_METHODS as $method) {
            if ($reflection->hasMethod($method)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 收集允许类所在的顶层命名空间（小写）；没有命名空间的类（如 PHP 内置类）不计入。
     *
     * @param array<string, string> $classes
     * @return array<string, true>
     */
    private static function rootNamespaces(array $classes): array
    {
        $roots = [];
        foreach ($classes as $class) {
            $root = strstr(ltrim($class, '\\'), '\\', true);
            if ($root !== false) {
                $roots[strtolower($root)] = true;
            }
        }
        return $roots;
    }

    /**
     * 递归收集第一遍解析结果中出现的类名（不完整对象的原始类名）；枚举不受 allowed_classes 限制且不能定义魔术方法，跳过。
     *
     * @param array<string, string> $names
     * @param array<int, true> $seen
     */
    private static function collectClassNames(mixed $value, array &$names, array &$seen, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new AsyncEventPayloadException('async event args nesting is too deep');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                self::collectClassNames($item, $names, $seen, $depth + 1);
            }
            return;
        }
        if (! is_object($value) || $value instanceof UnitEnum) {
            return;
        }
        $id = spl_object_id($value);
        if (isset($seen[$id])) {
            return;
        }
        $seen[$id] = true;
        $properties = (array) $value;
        if ($value instanceof __PHP_Incomplete_Class) {
            $name = (string) ($properties[self::INCOMPLETE_CLASS_NAME_KEY] ?? '');
            unset($properties[self::INCOMPLETE_CLASS_NAME_KEY]);
        } else {
            $name = get_class($value);
        }
        if ($name !== '') {
            $names[strtolower($name)] = $name;
        }
        foreach ($properties as $item) {
            self::collectClassNames($item, $names, $seen, $depth + 1);
        }
    }

    /**
     * 检查反序列化结果中任意位置是否残留不完整对象。
     *
     * @param array<int, true> $seen
     */
    private static function containsIncompleteClass(mixed $value, array &$seen, int $depth): bool
    {
        if ($depth > self::MAX_DEPTH) {
            throw new AsyncEventPayloadException('async event args nesting is too deep');
        }
        if ($value instanceof __PHP_Incomplete_Class) {
            return true;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::containsIncompleteClass($item, $seen, $depth + 1)) {
                    return true;
                }
            }
            return false;
        }
        if (! is_object($value) || $value instanceof UnitEnum) {
            return false;
        }
        $id = spl_object_id($value);
        if (isset($seen[$id])) {
            return false;
        }
        $seen[$id] = true;
        foreach ((array) $value as $item) {
            if (self::containsIncompleteClass($item, $seen, $depth + 1)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 两个类名是否位于同一顶层命名空间（如都在 App\ 下）；没有命名空间的类不视为同一命名空间。
     */
    private static function sameRootNamespace(string $a, string $b): bool
    {
        $rootA = strstr(ltrim($a, '\\'), '\\', true);
        $rootB = strstr(ltrim($b, '\\'), '\\', true);
        return $rootA !== false && $rootB !== false && strcasecmp($rootA, $rootB) === 0;
    }
}
