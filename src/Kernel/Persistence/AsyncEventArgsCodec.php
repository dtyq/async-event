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

    private const DEFAULT_STRATEGY = [
        'min_bytes' => 4096,
        'max_bytes' => 256 * 1024,
        'min_savings_ratio' => 0.5,
    ];

    private const SAMPLE_BYTES = 4096;

    private const SAMPLE_COUNT = 4;

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
     * 开启后先按体积及有限采样筛选，再按最终封装收益决定是否压缩。
     */
    public function encode(string $args): string
    {
        if (! $this->config->get('async_event.args_compression.enabled', true)) {
            return $args;
        }
        if (! function_exists('gzencode') || ! function_exists('gzdecode')) {
            return $args;
        }
        $strategy = $this->compressionStrategy();
        $length = strlen($args);
        if ($length < $strategy['min_bytes'] || $length > $strategy['max_bytes'] || $length > $this->compressionLimit()) {
            return $args;
        }
        $maxStoredRatio = 1 - $strategy['min_savings_ratio'];
        // 大正文不参与采样；候选正文最多检查四个独立窗口，避免拼接放大重复收益。
        if ($length > self::SAMPLE_BYTES * self::SAMPLE_COUNT) {
            for ($i = 0; $i < self::SAMPLE_COUNT; ++$i) {
                $offset = intdiv(($length - self::SAMPLE_BYTES) * $i, self::SAMPLE_COUNT - 1);
                $sample = substr($args, $offset, self::SAMPLE_BYTES);
                if ($this->compress($sample, $maxStoredRatio) === $sample) {
                    return $args;
                }
            }
        }
        return $this->compress($args, $maxStoredRatio);
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

    /**
     * 补齐默认策略并校验边界，策略只限制新写入，不参与历史解码。
     */
    private function compressionStrategy(): array
    {
        $strategy = $this->config->get('async_event.args_compression.strategy', []);
        if (! is_array($strategy)) {
            throw new RuntimeException('Configure a valid async event compression strategy');
        }
        $strategy = array_merge(self::DEFAULT_STRATEGY, $strategy);
        if (! is_int($strategy['min_bytes']) || $strategy['min_bytes'] <= 0
            || ! is_int($strategy['max_bytes']) || $strategy['max_bytes'] < $strategy['min_bytes']
            || $strategy['max_bytes'] > intdiv(PHP_INT_MAX, self::SAMPLE_COUNT)
            || (! is_int($strategy['min_savings_ratio']) && ! is_float($strategy['min_savings_ratio']))
            || ! ($strategy['min_savings_ratio'] > 0 && $strategy['min_savings_ratio'] < 1)) {
            throw new RuntimeException('Configure valid async event compression bounds and savings ratio');
        }
        return $strategy;
    }

    /**
     * 使用 Gzip 1 限制试压成本，收益计入版本头与 Base64，不达标保留原文。
     */
    private function compress(string $args, float $maxStoredRatio): string
    {
        $compressed = gzencode($args, 1);
        if ($compressed === false) {
            throw new RuntimeException('Failed to compress async event args');
        }
        $length = strlen($args);
        $encoded = self::ARGS_PREFIX . $length . ':' . base64_encode($compressed);
        return strlen($encoded) <= $length * $maxStoredRatio ? $encoded : $args;
    }
}
