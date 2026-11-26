<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\AsyncEvent\Kernel\Persistence;

use Carbon\Carbon;
use Dtyq\AsyncEvent\Kernel\Constants\Status;
use Dtyq\AsyncEvent\Kernel\Persistence\Model\AsyncEventModel;
use Dtyq\AsyncEvent\Kernel\Utils\Locker;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * 编排 Redis 临时存储与 MySQL 失败记录，统一编排交接恢复和 args 编解码。
 */
class AsyncEventRepository
{
    private const STORES = [
        'redis',
        'database',
    ];

    private AsyncEventArgsCodec $argsCodec;

    private RedisAsyncEventStorage $redisStorage;

    private LoggerInterface $logger;

    private Locker $locker;

    /**
     * 只装配存储、编解码器、日志与锁，构造时不执行存储读写。
     */
    public function __construct(
        AsyncEventArgsCodec $argsCodec,
        RedisAsyncEventStorage $redisStorage,
        LoggerInterface $logger,
        Locker $locker
    ) {
        $this->argsCodec = $argsCodec;
        $this->redisStorage = $redisStorage;
        $this->logger = $logger;
        $this->locker = $locker;
    }

    /**
     * 执行前只保存 Redis，正常成功路径不访问 MySQL。
     */
    public function create(array $data): AsyncEventModel
    {
        $stored = $data;
        $stored['args'] = $this->argsCodec->encode($data['args']);
        $this->redisStorage->create($stored);
        return $this->makeModel($data);
    }

    /**
     * 仅检查两端存在性，不解压正文或触发转存。
     */
    public function exists(int $id): bool
    {
        return $this->redisStorage->exists($id) || AsyncEventModel::query()
            ->where('id', '=', $id)
            ->exists();
    }

    /**
     * 首次失败先标记 Redis 再落库；已有数据库记录只更新数据库。
     */
    public function updateById(int $id, array $data): void
    {
        $model = $this->findStored($id);
        if ($model === null) {
            return;
        }
        if (isset($data['args'])) {
            $data['args'] = $this->argsCodec->encode($data['args']);
        }
        if ($model->exists) {
            AsyncEventModel::query()
                ->where('id', '=', $id)
                ->update($data);
            return;
        }
        if ($this->redisStorage->updateById($id, $data) > 0 && $this->isFailure((int) ($data['status'] ?? $model->status))) {
            // 重新读取已保存的状态，避免用更新前的正文与次数覆盖转存结果。
            $this->findStored($id);
        }
    }

    /**
     * Redis 超时事件重试失败后转存，数据库事件只递增原有次数。
     */
    public function retryById(int $id): void
    {
        $model = $this->findStored($id);
        if ($model === null) {
            return;
        }
        if ($model->exists) {
            AsyncEventModel::query()
                ->where('id', '=', $id)
                ->increment('retry_times', 1, [
                    'updated_at' => Carbon::now(),
                ]);
        } elseif ($this->redisStorage->retryById($id) > 0) {
            $this->findStored($id);
        }
    }

    /**
     * 先恢复未完成交接再还原 args；永久载荷错误转存为数据库失败记录。
     */
    public function getById(int $id): ?AsyncEventModel
    {
        $model = $this->findStored($id);
        if ($model === null) {
            return null;
        }
        try {
            return $this->restoreArgs($model, $this->argsCodec->decode((string) $model->args));
        } catch (UnexpectedValueException $exception) {
            $this->updateById($id, [
                'status' => Status::STATE_EXCEEDED,
            ]);
            $this->logger->warning('AsyncEventArgsRejected', [
                'id' => $id,
                'reason' => $exception->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * 合并两端有界超时扫描，失败副本只完成交接，由数据库状态决定是否重试。
     */
    public function getTimeoutRecordIds(string $datetime, int $limit = 100): array
    {
        if ($limit <= 0) {
            return [];
        }
        $stores = self::STORES;
        $ids = [];
        $failure = null;
        $successes = 0;
        foreach ($stores as $index => $store) {
            $quota = intdiv($limit, count($stores)) + ($index < $limit % count($stores) ? 1 : 0);
            if ($quota === 0) {
                continue;
            }
            try {
                if ($store === 'redis') {
                    $pending = $this->redisStorage->getTimeoutRecordIds($datetime, $quota);
                    $found = [];
                    $transfers = [];
                    foreach ($pending as $id) {
                        try {
                            $model = $this->readRedisModel((int) $id);
                            if ($model !== null && $this->isFailure($model->status)) {
                                $transfers[] = $model->id;
                            } elseif ($model !== null && $model->status === Status::STATE_WAIT) {
                                $found[] = (int) $id;
                            }
                        } catch (Throwable $exception) {
                            $this->logger->error('AsyncEventTransferRecoveryFailed', [
                                'id' => (int) $id,
                                'exception_type' => get_class($exception),
                            ]);
                        }
                    }
                    if ($transfers !== []) {
                        try {
                            $this->recoverTransfers($transfers);
                        } catch (Throwable $exception) {
                            $this->logger->error('AsyncEventTransferRecoveryFailed', [
                                'exception_type' => get_class($exception),
                            ]);
                        }
                    }
                } else {
                    $found = AsyncEventModel::query()
                        ->where('updated_at', '<', $datetime)
                        ->whereIn('status', [
                            Status::STATE_WAIT,
                            Status::STATE_IN_EXECUTION,
                        ])
                        ->orderBy('updated_at')
                        ->orderBy('id')
                        ->limit($quota)
                        ->pluck('id')
                        ->toArray();
                }
                $ids = array_merge($ids, $found);
                ++$successes;
            } catch (Throwable $exception) {
                $failure = $exception;
                $this->logger->error('AsyncEventScanFailed', [
                    'storage' => $store,
                    'exception_type' => get_class($exception),
                ]);
            }
        }
        if ($successes === 0 && $failure !== null) {
            throw $failure;
        }
        return array_values(array_unique($ids));
    }

    /**
     * 成功时先清理 Redis 副本，再删除数据库记录；普通 Redis 成功不访问数据库。
     */
    public function deleteById(int $id): int
    {
        $model = $this->readRedisModel($id);
        $deleted = 0;
        if ($model !== null) {
            $deleted = $this->redisStorage->deleteById($id);
            if (! $this->isFailure($model->status)) {
                return $deleted;
            }
        }
        // 临时恢复记录完成后物理回收，先清 Redis 防止旧失败副本再次恢复。
        return $deleted + AsyncEventModel::query()
            ->where('id', '=', $id)
            ->delete();
    }

    /**
     * 保留数据库历史条件，Redis 仅支持包内既有的两类清理条件。
     */
    public function deleteHistory(array $where)
    {
        $deleted = 0;
        foreach (self::STORES as $store) {
            if ($store === 'redis') {
                $deleted += $this->redisStorage->deleteHistory($where);
            } else {
                $ids = AsyncEventModel::query()
                    ->where($where)
                    ->limit(10000)
                    ->pluck('id')
                    ->toArray();
                if ($ids !== []) {
                    // 过期恢复记录是运行时数据，按既有保留期限物理回收。
                    $deleted += AsyncEventModel::query()
                        ->whereIn('id', $ids)
                        ->delete();
                }
            }
        }
        return $deleted;
    }

    /**
     * 待执行记录直接读 Redis，失败副本交接后以数据库进度为准。
     */
    private function findStored(int $id): ?AsyncEventModel
    {
        $model = $this->readRedisModel($id);
        if ($model === null) {
            return AsyncEventModel::query()
                ->find($id);
        }
        if ($this->isFailure($model->status)) {
            $models = $this->moveToDatabase([
                $model->id => $model,
            ]);
            return $models[$id];
        }
        return $model;
    }

    /**
     * 只水合 Redis 字段，明确区分读取失败与记录缺失。
     */
    private function readRedisModel(int $id): ?AsyncEventModel
    {
        $data = $this->redisStorage->getById($id);
        return $data === null ? null : $this->makeModel($data);
    }

    /**
     * 沿用原状态，状态 1 是执行器失败捕获后的待重试状态，状态 3 保留供排查。
     */
    private function isFailure(int $status): bool
    {
        return $status === Status::STATE_IN_EXECUTION || $status === Status::STATE_EXCEEDED;
    }

    /**
     * 扫描补交接复用执行锁，锁内重读后批量落库，避免成功清理后被旧快照重建。
     */
    private function recoverTransfers(array $ids): void
    {
        $owner = uniqid('async_event_transfer_', true);
        $locked = [];
        $models = [];
        try {
            foreach ($ids as $id) {
                $name = 'async_event_run_' . $id;
                if ($this->locker->acquire($name, $owner)) {
                    $locked[] = $name;
                    $model = $this->readRedisModel($id);
                    if ($model !== null && $this->isFailure($model->status)) {
                        $models[$id] = $model;
                    }
                }
            }
            if ($models !== []) {
                $this->moveToDatabase($models);
            }
        } finally {
            foreach ($locked as $name) {
                try {
                    $this->locker->release($name, $owner);
                } catch (Throwable $exception) {
                    // 单把锁释放失败不能阻塞其他锁释放，原租期仍限制残留时间。
                    $this->logger->error('AsyncEventTransferUnlockFailed', [
                        'exception_type' => get_class($exception),
                    ]);
                }
            }
        }
    }

    /**
     * 按原 ID 批量幂等转存，冲突只自赋值主键，重读进度后再清理 Redis。
     */
    private function moveToDatabase(array $redisModels): array
    {
        $ids = array_keys($redisModels);
        $models = AsyncEventModel::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id')
            ->all();
        $missing = array_diff_key($redisModels, $models);
        if ($missing !== []) {
            $rows = array_map(static function (AsyncEventModel $model): array {
                // 保持原存储字节与 JSON cast，不重复压缩，时间和次数也不重置。
                return $model->getAttributes();
            }, array_values($missing));
            // 临时表只有 ID 唯一键；冲突只自赋值 ID，其他写入错误正常抛出。
            // 使用底层 Query Builder，避免 ORM upsert 自动覆盖已有 updated_at。
            AsyncEventModel::query()
                ->getQuery()
                ->upsert($rows, [
                    'id',
                ], [
                    'id',
                ]);
            $models = AsyncEventModel::query()
                ->whereIn('id', $ids)
                ->get()
                ->keyBy('id')
                ->all();
        }
        if (array_diff_key($redisModels, $models) !== []) {
            throw new RuntimeException('Failed to confirm async event transfer');
        }
        if (reset($models)->getConnection()
            ->transactionLevel() === 0) {
            foreach ($ids as $id) {
                $this->redisStorage->deleteById($id);
            }
        }
        return $models;
    }

    /**
     * Redis 记录仅在内存水合，日期转换不触发数据库连接。
     */
    private function makeModel(array $data): AsyncEventModel
    {
        $model = new AsyncEventModel();
        $model->setDateFormat('Y-m-d H:i:s');
        $model->fill($data);
        return $model;
    }

    /**
     * 对外保持原始 args 且同步原值，避免把还原误认为待保存变更。
     */
    private function restoreArgs(AsyncEventModel $model, string $args): AsyncEventModel
    {
        $model->setAttribute('args', $args);
        $model->syncOriginalAttribute('args');
        return $model;
    }
}
