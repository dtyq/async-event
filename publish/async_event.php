<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */
return [
    // 事件执行驱动配置 支持 coroutine、queue_amqp
    'listener_exec_driver' => env('ASYNC_EVENT_LISTENER_EXEC_DRIVER', 'coroutine'),

    // 新事件固定暂存 Redis，失败转存 MySQL；这里只配置 Redis 连接与 key 隔离。
    'storage' => [
        'redis' => [
            'pool' => env('ASYNC_EVENT_STORAGE_REDIS_POOL', 'default'),
            'namespace' => env('ASYNC_EVENT_STORAGE_REDIS_NAMESPACE', 'async_event'),
        ],
    ],

    // args 默认按策略压缩；开关只控制新写入，始终兼容读取历史压缩数据。
    'args_compression' => [
        'enabled' => (bool) env('ASYNC_EVENT_ARGS_COMPRESSION_ENABLED', true),
        // 开启后按此策略筛选；固定 Gzip 1，最多采样四段各 4 KiB。
        'strategy' => [
            'min_bytes' => 4096,
            'max_bytes' => 256 * 1024,
            'min_savings_ratio' => 0.5,
        ],
        'max_decoded_bytes' => (int) env('ASYNC_EVENT_ARGS_MAX_DECODED_BYTES', 16 * 1024 * 1024),
    ],

    // 重试配置
    'retry' => [
        // 重试间隔 秒
        'interval' => 600,
        // 重试最大次数
        'times' => 3,
    ],

    // 最大重试后的回调，如可以发送钉钉消息
    'max_retry_callback' => [null],

    // 协程上下文复制keys
    'context_copy_keys' => ['request-id', 'language'],

    // 是否清理历史事件
    'clear_history' => env('ASYNC_EVENT_CLEAR_HISTORY', true),
];
