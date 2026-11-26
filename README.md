# 异步事件

- 事件将会放到一个协程中，然后按照顺序执行  
- 核心代码为`\Dtyq\AsyncEvent\AsyncEventDispatcher::dispatch`

## 安装
- 安装
```
composer require dtyq/async-event
```
- 发布配置
```
php bin/hyperf.php vendor:publish dtyq/async-event
```
- 运行数据库迁移
```
php bin/hyperf.php migrate
```

## 使用方式

- 为了不影响原有逻辑，采用新的dispatcher即可

demo
```php
<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */
 
namespace App\Controller;

use App\Event\DemoEvent;
use Hyperf\Di\Annotation\Inject;
use Dtyq\AsyncEvent\AsyncEventDispatcher;

class IndexController extends AbstractController
{
    /**
     * @Inject()
     */
    protected AsyncEventDispatcher $asyncEventDispatcher;

    public function index()
    {
        $user = $this->request->input('user', 'Hyperf');
        $method = $this->request->getMethod();

        $this->asyncEventDispatcher->dispatch(new DemoEvent([123,222], 9));

        return [
            'method' => $method,
            'message' => "Hello {$user}.",
        ];
    }
}

```

- 达到最大执行次数，可以进行消息提醒，但是需要自己增加配置，本项目仅提供达到最大重试事件


## 注意事项

- 事件中尽量不要使用协程上下文来传递数据，因为事件是异步的，可能会导致数据不一致

## 临时记录存储与 args 压缩

Redis 与 MySQL 固定共存，上层调用及 AMQP 消息格式不变。`AsyncEventRepository` 编排生命周期、失败落库和恢复；`RedisAsyncEventStorage` 封装 Redis 记录、JSON 上下文、时间索引和 Lua 原子操作；`AsyncEventArgsCodec` 负责 args 压缩、解压、格式校验和解压额度保护。

| 阶段 | 保存与清理 |
| --- | --- |
| 执行前 | 完整恢复记录只写 Redis，args 默认按策略压缩，可显式关闭 |
| 执行成功 | 删除 Redis，不访问 MySQL 临时事件表 |
| 执行失败 | 按原 ID 保存完整记录到 MySQL，确认提交后删除 Redis |
| 中断、漏投或重启 | 超时扫描合并两端的待执行 ID，去重后由原执行器重试 |
| Redis 超时记录重试 | 成功直接删 Redis，失败才转存 MySQL |
| MySQL 记录重试 | 失败更新原记录与次数，成功删除，不搬回 Redis |

Redis 与 MySQL 的生命周期固定，兼容读取始终开启。压缩写入由 `async_event.args_compression.enabled` 控制，缺失时默认按策略压缩，为 `false` 时保留原文。配置如下：

| 环境变量 | 默认值 | 用途 |
| --- | --- | --- |
| `ASYNC_EVENT_STORAGE_REDIS_POOL` | `default` | 已配置的 Hyperf Redis 连接池 |
| `ASYNC_EVENT_STORAGE_REDIS_NAMESPACE` | `async_event` | 专用记录及索引命名空间，仅允许字母、数字、冒号、下划线和连字符 |
| `ASYNC_EVENT_ARGS_COMPRESSION_ENABLED` | `true` | 是否按策略压缩新写入的 args；设为 `false` 后写原文，关闭不影响历史压缩数据读取 |
| `ASYNC_EVENT_ARGS_MAX_DECODED_BYTES` | `16777216`（16 MiB） | 解压输出上限，可按正文大小与并发内存预算调整为正整数；更大的原文不压缩 |

正常路径不双写；失败交接可以短暂两端同 ID 共存。先在 Redis 保存失败状态，再写 MySQL，最后删除 Redis；MySQL 写入失败保留 Redis 并抛错，Redis 清理失败则由后续读取或超时扫描恢复交接。MySQL 已有同 ID 记录时以数据库进度为准，不用 Redis 旧数据覆盖重试次数。批量转存使用原 ID 唯一键去重，冲突只自赋值 ID，不重置状态、时间或次数，不忽略其他写入错误。查询沿用应用默认数据库连接；外层事务未提交时保留 Redis 副本，后续独立扫描确认提交或在回滚后重建失败记录。成功清理双份记录时先删 Redis，再删 MySQL。

超时扫描和历史清理始终覆盖两端。超时扫描默认每端最多 50 条，Redis 待执行记录直接参与恢复，失败副本获取与执行器相同的事件锁、锁内重读后批量补交接，再由 MySQL 的状态和时间条件决定是否自动重试；永久失败副本只转存，不返回为自动重试 ID。一端扫描失败保留另一端结果，转存批次出错时保留 Redis 副本，其他普通待执行记录仍可返回，错误仅记录 ID、存储和异常类型等安全摘要。旧 MySQL 记录仍按原位置消费。批次写入错误会保留整批失败副本，需要排查错误后再恢复；不承诺坏记录的逐条写入隔离。

Redis 使用 Hash 保存完整记录、ZSET 保存重试与历史索引，Lua 同时维护正文和索引。失败副本在完成交接前仍保留扫描索引，包括永久失败记录，以便数据库故障恢复后补落库。同一命名空间的 key 使用相同 Redis Cluster hash tag；共享实例的不同应用或环境应使用不同命名空间。不设置短 TTL，保留原历史清理开关和期限。Redis 必须允许相关 Lua、Hash、ZSET 命令，并配置合适的持久化、复制和内存策略，避免任意淘汰恢复记录。应用重启恢复依赖记录仍在，Redis 自身故障后的数据保障由部署配置决定。

开启压缩后只处理 `args`，使用 `async_event.args_compression.strategy` 的默认策略，缺失或部分配置时补齐默认值：

```php
'args_compression' => [
    'enabled' => (bool) env('ASYNC_EVENT_ARGS_COMPRESSION_ENABLED', true),
    'strategy' => [
        'min_bytes' => 4096,
        'max_bytes' => 256 * 1024,
        'min_savings_ratio' => 0.5,
    ],
    'max_decoded_bytes' => (int) env('ASYNC_EVENT_ARGS_MAX_DECODED_BYTES', 16 * 1024 * 1024),
],
```

正文先用字节长度筛选，默认小于 4 KiB 或大于 256 KiB 直接保留原文，不采样、不压缩。候选正文不超过 16 KiB 时直接用 Gzip 1 试压；更大时在全文均匀分散取四段、每段 4 KiB，分别试压，各段均达到收益线才尝试完整压缩。样本和最终正文均计算版本头、原始长度及 Base64 的实际存储体积，默认至少节省 50% 才采用；采样误判后仍由最终收益检查回退原文。采样只是启发式，回退不能撤销已花费的 CPU，体积上限用于限制单次同步工作量，不保证所有并发下的响应时延。

策略只开放体积和收益阈值，采样数量及 Gzip 1 固定。`min_bytes`、`max_bytes` 必须是正整数且前者不大于后者；`min_savings_ratio` 为大于 0、小于 1 的数值，非法策略在开启压缩写入时抛出配置异常。实际压缩体积同时受 `max_decoded_bytes` 限制，提高解压额度不会自动扩大写入策略上限。默认策略优先节约同步 CPU，并不承诺对所有业务数据都是最优压缩策略。

缺少 gzencode/gzdecode 或不满足策略时保留原文；转存 MySQL 不重复压缩已编码字节。读取始终兼容旧原文与压缩存量，对外模型仍返回原始 args，新写入策略不限制历史大正文或旧压缩等级的读取。损坏或未知压缩格式转存为 MySQL 失败记录并保留，不执行正文；读取压缩记录时，额度不足或缺少 gzdecode 属于环境错误，会抛出异常并保留记录；原文读取不依赖这些函数。

包发布配置与 magic-service 配置均支持上述环境变量；已发布过配置的应用需补充 `args_compression.enabled` 配置项才能通过环境变量控制，配置项缺失时默认按策略压缩，已有显式 `false` 配置仍保持关闭。修改环境变量后需按应用部署方式重新加载配置并重启常驻进程。

接入与回滚：

1. 所有目标进程需具备 Redis 连接和足够的解压额度；可能消费压缩记录的进程需具备 gzdecode。16 MiB 是初始保护值，需按实际正文与并发内存预算核对。
2. 开启新写入压缩前，先升级所有消费者、超时重试及人工重试进程。无法分离升级读取方时，短暂停投，待旧进程退出且读取方就绪后再恢复；不能直接混用压缩生产者与不支持压缩读取的旧消费者滚动发布。
3. 新版本没有切回 MySQL 写入的配置。回退不支持 Redis/压缩的旧代码前，需要停投并妥善处理两端存量；不能通过切换 driver 代替排空，也不能在存量未处理时随意改变 Redis pool/namespace 或降低解码额度。

Redis 登记不受 MySQL 业务事务回滚控制，需确认事务内派发要求。执行和锁沿用原逻辑，仍可能重复执行，监听器应保持幂等。本变更不会自动发布或更新应用安装的 Composer 包，接入时需确认实际加载版本。
