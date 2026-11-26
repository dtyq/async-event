<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace HyperfTest\Cases\Persistence;

use Dtyq\AsyncEvent\Kernel\Persistence\AsyncEventArgsCodec;
use Hyperf\Config\Config;
use HyperfTest\Cases\AbstractTestCase;
use RuntimeException;

/**
 * 使用合成字符串验证压缩筛选、采样误判和历史兼容，不访问外部存储。
 * @internal
 * @coversNothing
 */
class AsyncEventArgsCodecTest extends AbstractTestCase
{
    /**
     * 体积边界包含两端，小正文和大正文保留原文。
     * @dataProvider payloadSizes
     */
    public function testDefaultStrategyRespectsSizeBounds(int $bytes, bool $compressed): void
    {
        $raw = str_repeat('x', $bytes);
        $codec = $this->codec();
        $encoded = $codec->encode($raw);
        self::assertSame($compressed, $encoded !== $raw);
        if ($compressed) {
            self::assertLessThanOrEqual(strlen($raw) * 0.5, strlen($encoded));
        }
        self::assertSame($raw, $codec->decode($encoded));
    }

    /**
     * 覆盖最小值、直接试压与采样的分界、最大值及越界。
     */
    public static function payloadSizes(): array
    {
        return [
            [4095, false],
            [4096, true],
            [16384, true],
            [16385, true],
            [262144, true],
            [262145, false],
        ];
    }

    /**
     * 高熵数据及其 Base64 文本均不值得为有限空间收益压缩。
     */
    public function testIncompressiblePayloadsStayRaw(): void
    {
        $noise = $this->noise(65536);
        foreach ([$noise, base64_encode($noise)] as $raw) {
            self::assertSame($raw, $this->codec()->encode($raw));
        }
    }

    /**
     * 头尾重复不足以决定全文收益，分散采样可发现中间的高熵内容。
     */
    public function testMixedContentStaysRaw(): void
    {
        $raw = str_repeat('x', 8192) . $this->noise(49152) . str_repeat('x', 8192);
        self::assertSame($raw, $this->codec()->encode($raw));
    }

    /**
     * 所有采样窗口均可压缩时，最终全文仍需达到收益线，防止采样误判。
     */
    public function testFinalBenefitCheckRejectsMisleadingSamples(): void
    {
        $raw = $this->noise(262144);
        for ($i = 0; $i < 4; ++$i) {
            $offset = intdiv((strlen($raw) - 4096) * $i, 3);
            $raw = substr_replace($raw, str_repeat('x', 4096), $offset, 4096);
        }
        self::assertSame($raw, $this->codec()->encode($raw));
    }

    /**
     * 应用可覆盖体积和收益阈值，部分策略配置由默认值补齐。
     */
    public function testConfiguredStrategyControlsCompression(): void
    {
        $codec = $this->codec(['strategy' => ['min_bytes' => 8192, 'max_bytes' => 32768]]);
        self::assertSame(str_repeat('x', 4096), $codec->encode(str_repeat('x', 4096)));
        self::assertNotSame(str_repeat('x', 16384), $codec->encode(str_repeat('x', 16384)));
        self::assertSame(str_repeat('x', 65536), $codec->encode(str_repeat('x', 65536)));
        $strict = $this->codec(['strategy' => ['min_savings_ratio' => 0.99]]);
        self::assertSame(str_repeat('x', 4096), $strict->encode(str_repeat('x', 4096)));
    }

    /**
     * 即使写入策略允许，超过解压额度的原文也不能压缩。
     */
    public function testDecodedCapacityStillLimitsWrites(): void
    {
        $raw = str_repeat('x', 16384);
        self::assertSame($raw, $this->codec(['max_decoded_bytes' => 8192])->encode($raw));
    }

    /**
     * 历史大正文和 Gzip 3 数据不受新写入策略限制。
     */
    public function testLegacyCompressedPayloadIgnoresWriteStrategy(): void
    {
        $raw = str_repeat('synthetic-', 40000);
        $legacy = '@async-event-args:v1:' . strlen($raw) . ':' . base64_encode(gzencode($raw, 3));
        self::assertSame($raw, $this->codec()->decode($legacy));
        self::assertSame($raw, $this->codec(['enabled' => false])->decode($legacy));
    }

    /**
     * 非法策略应清楚报错，不能悄悄扩大同步压缩范围。
     * @dataProvider invalidStrategies
     * @param mixed $strategy
     */
    public function testInvalidStrategyIsRejected($strategy): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('compression');
        $this->codec(['strategy' => $strategy])->encode(str_repeat('x', 4096));
    }

    /**
     * 使用合成配置覆盖类型、上下界和收益比例的异常值。
     */
    public static function invalidStrategies(): array
    {
        return [
            ['adaptive'],
            [['min_bytes' => 0]],
            [['min_bytes' => '4096']],
            [['max_bytes' => 4095]],
            [['max_bytes' => 262144.0]],
            [['min_savings_ratio' => '0.5']],
            [['min_savings_ratio' => 0]],
            [['min_savings_ratio' => 1]],
            [['min_savings_ratio' => NAN]],
            [['min_savings_ratio' => INF]],
        ];
    }

    /**
     * 关闭时不执行压缩策略，配置尚未补齐也能原文写入。
     */
    public function testDisabledCompressionDoesNotEvaluateStrategy(): void
    {
        $raw = str_repeat('x', 65536);
        self::assertSame($raw, $this->codec(['enabled' => false, 'strategy' => 'unused'])->encode($raw));
    }

    /**
     * 构造无外部依赖的配置，默认开启以验证策略本身。
     */
    private function codec(array $settings = []): AsyncEventArgsCodec
    {
        return new AsyncEventArgsCodec(new Config([
            'async_event' => [
                'args_compression' => array_merge(['enabled' => true], $settings),
            ],
        ]));
    }

    /**
     * 使用确定性摘要拼接高熵合成字节，避免真实数据或随机测试波动。
     */
    private function noise(int $bytes): string
    {
        $noise = '';
        for ($i = 0; strlen($noise) < $bytes; ++$i) {
            $noise .= hash('sha256', (string) $i, true);
        }
        return substr($noise, 0, $bytes);
    }
}
