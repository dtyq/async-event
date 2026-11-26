<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\AsyncEvent\Kernel\Persistence;

use Dtyq\AsyncEvent\Kernel\Constants\Status;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Redis\RedisFactory;
use Hyperf\Redis\RedisProxy;
use InvalidArgumentException;
use RuntimeException;

/**
 * 封装 Redis 临时记录、时间索引和原子操作，不负责 MySQL 转存或 args 压缩。
 */
class RedisAsyncEventStorage
{
    /**
     * 正文与时间索引一起维护；更新缺失记录时不重建，清理时重查时间与状态。
     */
    private const MUTATE_SCRIPT = <<<'LUA'
local operation, id, score, filter = ARGV[1], ARGV[2], tonumber(ARGV[3]), ARGV[4]
if not score or (operation ~= 'create' and operation ~= 'update' and operation ~= 'retry' and operation ~= 'delete' and operation ~= 'purge') then
    return redis.error_reply('invalid async event operation')
end
for i, key in ipairs(KEYS) do
    local kind = redis.call('TYPE', key).ok
    if kind ~= 'none' and kind ~= (i == 1 and 'hash' or 'zset') then
        return redis.error_reply('invalid async event key type')
    end
end
local exists = redis.call('EXISTS', KEYS[1]) == 1
if operation == 'delete' or operation == 'purge' then
    if operation == 'purge' and exists then
        local current = tonumber(redis.call('ZSCORE', KEYS[3], id))
        if not current or current > score or (filter ~= '' and redis.call('HGET', KEYS[1], 'status') ~= filter) then
            return 0
        end
    end
    local deleted = redis.call('DEL', KEYS[1])
    for i = 2, 4 do redis.call('ZREM', KEYS[i], id) end
    return deleted
end
if operation == 'create' and exists then
    return redis.error_reply('async event already exists')
end
if not exists and operation ~= 'create' then return 0 end
local status = redis.call('HGET', KEYS[1], 'status')
for i = 5, #ARGV, 2 do
    if ARGV[i] == 'status' then status = ARGV[i + 1] end
end
if status ~= '0' and status ~= '1' and status ~= '2' and status ~= '3' then
    return redis.error_reply('invalid async event status')
end
if operation == 'retry' then
    local retries = redis.call('HGET', KEYS[1], 'retry_times')
    if not retries or not string.match(retries, '^%d+$') then
        return redis.error_reply('invalid async event retry count')
    end
    redis.call('HINCRBY', KEYS[1], 'retry_times', 1)
end
if #ARGV >= 5 then redis.call('HSET', KEYS[1], unpack(ARGV, 5)) end
redis.call('ZADD', KEYS[3], score, id)
-- 失败副本在确认落库前仍可被扫描，状态 3 只转存、不自动执行。
if status == '0' or status == '1' or status == '3' then
    redis.call('ZADD', KEYS[2], score, id)
else
    redis.call('ZREM', KEYS[2], id)
end
if status == '2' then
    redis.call('ZADD', KEYS[4], score, id)
else
    redis.call('ZREM', KEYS[4], id)
end
return 1
LUA;

    private ConfigInterface $config;

    private RedisFactory $redisFactory;

    private ?RedisProxy $redis = null;

    /**
     * 校验 key 隔离配置并保存依赖，构造时不建立网络连接。
     */
    public function __construct(ConfigInterface $config, RedisFactory $redisFactory)
    {
        $this->config = $config;
        $this->redisFactory = $redisFactory;
        $namespace = $config->get('async_event.storage.redis.namespace', 'async_event');
        if (! is_string($namespace) || ! preg_match('/\A[a-zA-Z0-9:_-]+\z/', $namespace)) {
            throw new InvalidArgumentException('Invalid async event Redis namespace');
        }
    }

    /**
     * 原子保存完整记录与索引，args 保留调用方已处理的存储字节。
     */
    public function create(array $data): void
    {
        $this->mutateRedis('create', (int) $data['id'], $data);
    }

    /**
     * 只查询 key 存在性，读取故障不视为缺失。
     */
    public function exists(int $id): bool
    {
        $exists = $this->getRedis()
            ->exists($this->redisKeys($id)[0]);
        if ($exists === false) {
            throw new RuntimeException('Failed to locate async event');
        }
        return $exists > 0;
    }

    /**
     * 返回原记录字段，只还原 JSON 上下文，不解压 args 或水合 ORM 模型。
     */
    public function getById(int $id): ?array
    {
        $data = $this->getRedis()
            ->hGetAll($this->redisKeys($id)[0]);
        if ($data === false) {
            throw new RuntimeException('Failed to read async event');
        }
        if ($data === []) {
            return null;
        }
        $data['context_data'] = json_decode($data['context_data'], true, 512, JSON_THROW_ON_ERROR);
        return $data;
    }

    /**
     * 原子更新字段与时间索引，缺失记录不重新创建。
     */
    public function updateById(int $id, array $data): int
    {
        $data['updated_at'] = $data['updated_at'] ?? date('Y-m-d H:i:s');
        return $this->mutateRedis('update', $id, $data);
    }

    /**
     * 原子递增失败次数并刷新待重试状态，不重写已有 args。
     */
    public function retryById(int $id): int
    {
        return $this->mutateRedis('retry', $id, [
            'status' => Status::STATE_IN_EXECUTION,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 同时删除临时记录与索引，重复删除返回零。
     */
    public function deleteById(int $id): int
    {
        return $this->mutateRedis('delete', $id);
    }

    /**
     * 按严格早于阈值查询有界 ID，业务重试与失败交接由仓储编排。
     */
    public function getTimeoutRecordIds(string $datetime, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }
        $ids = $this->getRedis()
            ->zRangeByScore($this->redisKeys(0)[1], '-inf', '(' . $this->timestamp($datetime), [
                'limit' => [
                    0,
                    $limit,
                ],
            ]);
        if ($ids === false) {
            throw new RuntimeException('Failed to scan async events');
        }
        return array_map('intval', $ids);
    }

    /**
     * 分批清理 Redis 过期记录，脚本内再次校验时间和状态。
     */
    public function deleteHistory(array $where): int
    {
        $deleted = 0;
        [$date, $status] = $this->historyFilter($where);
        $keys = $this->redisKeys(0);
        $key = $status === null ? $keys[2] : $keys[3];
        $cutoff = $this->timestamp($date);
        $processed = 0;
        while ($processed < 10000) {
            $ids = $this->getRedis()
                ->zRangeByScore($key, '-inf', (string) $cutoff, [
                    'limit' => [
                        0,
                        100,
                    ],
                ]);
            if ($ids === false) {
                throw new RuntimeException('Failed to scan async event history');
            }
            if ($ids === []) {
                break;
            }
            $processed += count($ids);
            $batch = 0;
            foreach ($ids as $id) {
                $batch += $this->mutateRedis('purge', (int) $id, [], $cutoff, $status);
            }
            $deleted += $batch;
            if ($batch === 0) {
                break;
            }
        }
        return $deleted;
    }

    /**
     * 仅在使用 Redis 时解析连接池，不在构造阶段建立连接。
     */
    private function getRedis(): RedisProxy
    {
        if ($this->redis === null) {
            $this->redis = $this->redisFactory->get($this->config->get('async_event.storage.redis.pool', 'default'));
        }
        return $this->redis;
    }

    /**
     * 一个 namespace 的正文与索引同槽，ID 始终保留十进制字符串。
     */
    private function redisKeys(int $id): array
    {
        $prefix = '{' . $this->config->get('async_event.storage.redis.namespace', 'async_event') . '}:';
        return [
            $prefix . 'record:' . $id,
            $prefix . 'retry',
            $prefix . 'history',
            $prefix . 'completed',
        ];
    }

    /**
     * 一次变更同时维护索引；元数据更新不重复编码现有正文。
     */
    private function mutateRedis(string $operation, int $id, array $data = [], ?int $cutoff = null, ?int $status = null): int
    {
        $score = $cutoff ?? $this->timestamp((string) ($data['updated_at'] ?? date('Y-m-d H:i:s')));
        $args = [
            $operation,
            (string) $id,
            (string) $score,
            $status === null ? '' : (string) $status,
        ];
        foreach ($data as $field => $value) {
            if (! in_array($field, [
                'id',
                'event',
                'listener',
                'args',
                'context_data',
                'status',
                'retry_times',
                'created_at',
                'updated_at',
            ], true)) {
                throw new InvalidArgumentException('Unknown async event field');
            }
            if ($field === 'context_data') {
                $value = json_encode($value, JSON_THROW_ON_ERROR);
            }
            $args[] = $field;
            $args[] = (string) $value;
        }
        $result = $this->getRedis()
            ->eval(self::MUTATE_SCRIPT, array_merge($this->redisKeys($id), $args), 4);
        if ($result === false) {
            throw new RuntimeException('Failed to persist async event');
        }
        return (int) $result;
    }

    /**
     * Redis 仅接受当前服务明确使用的过期与完成记录条件。
     */
    private function historyFilter(array $where): array
    {
        if (count($where) < 1 || count($where) > 2 || count($where[0]) !== 3 || $where[0][0] !== 'updated_at' || $where[0][1] !== '<=') {
            throw new InvalidArgumentException('Unsupported async event history filter');
        }
        if (isset($where[1]) && $where[1] !== [
            'status',
            '=',
            Status::STATE_COMPLETE,
        ]) {
            throw new InvalidArgumentException('Unsupported async event history status');
        }
        return [
            $where[0][2],
            isset($where[1]) ? Status::STATE_COMPLETE : null,
        ];
    }

    /**
     * 索引与查询共用应用时间基准，非法时间不能转换成零。
     */
    private function timestamp(string $datetime): int
    {
        $timestamp = strtotime($datetime);
        if ($timestamp === false) {
            throw new InvalidArgumentException('Invalid async event timestamp');
        }
        return $timestamp;
    }
}
