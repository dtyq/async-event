<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\AsyncEvent\Kernel\Persistence;

use Hyperf\Contract\ConfigInterface;
use RuntimeException;
use UnexpectedValueException;

/**
 * 负责 args 压缩存储格式、解压校验及额度保护，不访问存储或反序列化事件。
 */
class AsyncEventArgsCodec
{
    private const ARGS_PREFIX = '@async-event-args:v1:';

    private const COMPRESSION_THRESHOLD = 4096;

    // 默认最多展开 16 MiB；更大的原文不压缩，部署可按内存预算调整额度。
    private const DEFAULT_MAX_DECODED_BYTES = 16 * 1024 * 1024;

    private ConfigInterface $config;

    /**
     * 保存配置并校验解压额度，不要求当前环境具备压缩能力。
     */
    public function __construct(ConfigInterface $config)
    {
        $this->config = $config;
        $this->compressionLimit();
    }

    /**
     * 默认保留原文，显式开启且具备能力、最终存储体积更小时才压缩。
     */
    public function encode(string $args): string
    {
        if (! $this->config->get('async_event.args_compression.enabled', false)) {
            return $args;
        }
        if (! function_exists('gzencode') || ! function_exists('gzdecode')) {
            return $args;
        }
        $limit = $this->compressionLimit();
        $length = strlen($args);
        if ($length < self::COMPRESSION_THRESHOLD || $length > $limit) {
            return $args;
        }
        $compressed = gzencode($args, 3);
        if ($compressed === false) {
            throw new RuntimeException('Failed to compress async event args');
        }
        $encoded = self::ARGS_PREFIX . $length . ':' . base64_encode($compressed);
        return strlen($encoded) < $length ? $encoded : $args;
    }

    /**
     * 先限制展开规模，再严格验证格式，旧原文保持原有读取契约。
     */
    public function decode(string $args): string
    {
        if (strncmp($args, '@async-event-args:', 18) !== 0) {
            return $args;
        }
        if (strncmp($args, self::ARGS_PREFIX, strlen(self::ARGS_PREFIX)) !== 0) {
            throw new UnexpectedValueException('Unsupported async event args version');
        }
        $parts = explode(':', substr($args, strlen(self::ARGS_PREFIX)), 2);
        if (count($parts) !== 2 || ! ctype_digit($parts[0]) || (string) (int) $parts[0] !== $parts[0]) {
            throw new UnexpectedValueException('Invalid async event args header');
        }
        if (! function_exists('gzdecode')) {
            throw new RuntimeException('Enable gzdecode to read compressed async event args');
        }
        $limit = $this->compressionLimit();
        if ((int) $parts[0] > $limit) {
            throw new RuntimeException('Async event args exceed configured decoding capacity');
        }
        $compressed = base64_decode($parts[1], true);
        $decoded = $compressed === false ? false : @gzdecode($compressed, $limit + 1);
        if ($decoded === false || strlen($decoded) !== (int) $parts[0] || strlen($decoded) > $limit) {
            throw new UnexpectedValueException('Invalid compressed async event args');
        }
        return $decoded;
    }

    /**
     * 编解码共用解压额度，非法额度属于配置错误。
     */
    private function compressionLimit(): int
    {
        $limit = $this->config->get('async_event.args_compression.max_decoded_bytes', self::DEFAULT_MAX_DECODED_BYTES);
        if (! is_int($limit) || $limit <= 0 || $limit >= PHP_INT_MAX) {
            throw new RuntimeException('Configure a positive async event decoding capacity');
        }
        return $limit;
    }
}
