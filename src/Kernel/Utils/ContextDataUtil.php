<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\AsyncEvent\Kernel\Utils;

use Hyperf\Context\Context;

class ContextDataUtil
{
    /**
     * Read context data based on config keys.
     */
    public static function readContextData(): array
    {
        $copyKeys = \Hyperf\Config\config('async_event.context_copy_keys', []);
        if (empty($copyKeys)) {
            return [];
        }

        $contextData = [];
        foreach ($copyKeys as $key) {
            if (Context::has($key)) {
                $value = Context::get($key);
                // Only copy serializable types (recursively check arrays)
                if (self::isSerializable($value)) {
                    $contextData[$key] = $value;
                }
            }
        }

        return $contextData;
    }

    /**
     * Set context data to current coroutine context.
     */
    public static function setContextData(array $contextData): void
    {
        // 记录中的 context_data 来自数据库，只恢复写入时允许复制的键，避免写入任意上下文
        $copyKeys = \Hyperf\Config\config('async_event.context_copy_keys', []);
        if (empty($copyKeys)) {
            return;
        }
        foreach ($contextData as $key => $value) {
            if (in_array($key, $copyKeys, true)) {
                Context::set($key, $value);
            }
        }
    }

    /**
     * Check if value is serializable (recursively check arrays).
     * @param mixed $value
     */
    private static function isSerializable($value): bool
    {
        // Null is serializable
        if (is_null($value)) {
            return true;
        }

        // Scalar types are serializable
        if (is_scalar($value)) {
            return true;
        }

        // Recursively check arrays
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::isSerializable($item)) {
                    return false;
                }
            }
            return true;
        }

        // Objects, resources, etc. are not serializable
        return false;
    }
}
