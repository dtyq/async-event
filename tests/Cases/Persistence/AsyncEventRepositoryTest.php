<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace HyperfTest\Cases\Persistence;

use Carbon\Carbon;
use Dtyq\AsyncEvent\Kernel\Persistence\AsyncEventArgsCodec;
use Dtyq\AsyncEvent\Kernel\Persistence\AsyncEventRepository;
use Dtyq\AsyncEvent\Kernel\Persistence\Model\AsyncEventModel;
use Dtyq\AsyncEvent\Kernel\Persistence\RedisAsyncEventStorage;
use Dtyq\AsyncEvent\Kernel\Utils\Locker;
use Hyperf\Config\Config;
use Hyperf\Context\ApplicationContext;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Database\Exception\QueryException;
use Hyperf\Database\MySqlConnection;
use Hyperf\Redis\RedisFactory;
use Hyperf\Redis\RedisProxy;
use HyperfTest\Cases\AbstractTestCase;
use PDO;
use PDOException;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * 存储均为 Mock，只通过合成记录验证旧调用契约、兼容定位和 args 安全边界。
 * @internal
 * @coversNothing
 */
class AsyncEventRepositoryTest extends AbstractTestCase
{
    private const ID = 9007199254740993;

    private ?ContainerInterface $previousContainer;

    private MySqlConnection $database;

    private RedisProxy $redis;

    private RedisFactory $factory;

    private LoggerInterface $logger;

    private Locker $locker;

    private bool $lockAvailable = true;

    private array $queries = [];

    private array $selectResults = [];

    private array $insertResults = [];

    private array $events = [];

    private int $transactionLevel = 0;

    /**
     * 只提供真实 ORM 的查询编译能力，所有 SQL 与 Redis 命令由 Mock 截断。
     */
    protected function setUp(): void
    {
        $this->previousContainer = ApplicationContext::hasContainer() ? ApplicationContext::getContainer() : null;
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())
            ->method('prepare');
        $this->database = $this->getMockBuilder(MySqlConnection::class)
            ->setConstructorArgs([
                $pdo,
                'mock',
            ])
            ->onlyMethods([
                'select',
                'affectingStatement',
                'update',
                'delete',
                'transactionLevel',
            ])
            ->getMock();
        $this->database->method('select')
            ->willReturnCallback(function ($sql, $bindings) {
                $this->events[] = 'select';
                $this->queries[] = [
                    'select',
                    $sql,
                    $bindings,
                ];
                self::assertNotEmpty($this->selectResults, '发生未计划的 SQL 查询');
                $result = array_shift($this->selectResults);
                if ($result instanceof Throwable) {
                    throw $result;
                }
                return $result;
            });
        foreach ([
            'affectingStatement',
            'update',
            'delete',
        ] as $method) {
            $this->database->method($method)
                ->willReturnCallback(function ($sql, $bindings) use ($method) {
                    $method = $method === 'affectingStatement' ? 'insert' : $method;
                    $this->events[] = $method;
                    $this->queries[] = [
                        $method,
                        $sql,
                        $bindings,
                    ];
                    if ($method === 'insert' && $this->insertResults !== []) {
                        $result = array_shift($this->insertResults);
                        if ($result instanceof Throwable) {
                            throw $result;
                        }
                        return $result;
                    }
                    return $method === 'insert' ? 1 : 0;
                });
        }
        $this->database->method('transactionLevel')
            ->willReturnCallback(function () {
                return $this->transactionLevel;
            });
        $resolver = $this->createMock(ConnectionResolverInterface::class);
        $resolver->method('connection')
            ->willReturn($this->database);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willReturnArgument(0);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->willReturnMap([
                [
                    ConnectionResolverInterface::class,
                    $resolver,
                ],
                [
                    EventDispatcherInterface::class,
                    $dispatcher,
                ],
            ]);
        ApplicationContext::setContainer($container);
        $this->redis = $this->createMock(RedisProxy::class);
        $this->factory = $this->createMock(RedisFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->locker = $this->createMock(Locker::class);
        $this->locker->method('acquire')
            ->willReturnCallback(function () {
                return $this->lockAvailable;
            });
        $this->locker->method('release')
            ->willReturn(true);
    }

    /**
     * 恢复容器，避免本测试的 Mock 影响包内既有安全回归。
     */
    protected function tearDown(): void
    {
        $property = new ReflectionProperty(ApplicationContext::class, 'container');
        $property->setAccessible(true);
        $property->setValue(null, $this->previousContainer);
    }

    /**
     * 默认创建、读取及成功删除仅访问 Redis，压缩透明且保留大整数 ID。
     */
    public function testSuccessfulRedisLifecycleDoesNotIssueMysqlQueries(): void
    {
        $record = $this->record(str_repeat('synthetic-payload-', 700));
        $stored = [];
        $this->redis->method('__call')
            ->willReturnCallback(function ($method, $args) use (&$stored) {
                if ($method === 'eval') {
                    self::assertSame(4, $args[2]);
                    self::assertSame((string) self::ID, $args[1][5]);
                    if ($args[1][4] === 'create') {
                        $stored = $this->fields($args[1]);
                    }
                    return 1;
                }
                return $method === 'exists' ? 1 : $stored;
            });
        $repo = $this->repository();
        $created = $repo->create($record);
        self::assertFalse($created->exists);
        self::assertSame($record['args'], $created->args);
        self::assertLessThan(strlen($record['args']), strlen($stored['args']));
        $loaded = $repo->getById(self::ID);
        self::assertSame(self::ID, $loaded->id);
        self::assertSame($record['args'], $loaded->args);
        self::assertSame($record['context_data'], $loaded->context_data);
        self::assertInstanceOf(Carbon::class, $loaded->updated_at);
        self::assertTrue($repo->exists(self::ID));
        self::assertSame(1, $repo->deleteById(self::ID));
        self::assertSame([], $this->queries);
    }

    /**
     * 首次失败先保存 Redis 状态，完整落库后再删 Redis，不重复压缩正文。
     */
    public function testFirstFailureTransfersCompleteRecordBeforeDeletingRedis(): void
    {
        $raw = str_repeat('synthetic-', 1000);
        $encoded = '@async-event-args:v1:' . strlen($raw) . ':' . base64_encode(gzencode($raw));
        $waiting = $this->record($encoded);
        $failed = $this->record($encoded, 1);
        $this->redisRecords([
            $waiting,
            $failed,
        ]);
        $this->selectResults = [
            [],
            [
                $failed,
            ],
        ];
        $this->repository()
            ->updateById(self::ID, [
            'status' => 1,
        ]);
        self::assertSame([
            'redis:update',
            'select',
            'insert',
            'select',
            'redis:delete',
        ], $this->events);
        self::assertStringContainsString('on duplicate key update `id` = values(`id`)', $this->queries[1][1]);
        $stored = $this->insertedRecord();
        self::assertSame((string) self::ID, $stored['id']);
        self::assertSame($encoded, $stored['args']);
        self::assertSame('1', $stored['status']);
        self::assertSame('0', $stored['retry_times']);
        self::assertSame($failed['event'], $stored['event']);
        self::assertSame($failed['listener'], $stored['listener']);
        self::assertSame($failed['context_data'], json_decode($stored['context_data'], true));
        self::assertSame($failed['created_at'], $stored['created_at']);
        self::assertSame($failed['updated_at'], $stored['updated_at']);
    }

    /**
     * Redis 超时事件重试失败递增一次，随后转存数据库等待后续重试。
     */
    public function testRedisRetryFailureTransfersIncrementedCount(): void
    {
        $this->redisRecords([
            $this->record('retry'),
            $this->record('retry', 1, 1),
        ]);
        $this->selectResults = [
            [],
            [
                $this->record('retry', 1, 1),
            ],
        ];
        $this->repository()
            ->retryById(self::ID);
        self::assertSame([
            'redis:retry',
            'select',
            'insert',
            'select',
            'redis:delete',
        ], $this->events);
        self::assertSame('1', $this->insertedRecord()['retry_times']);
    }

    /**
     * 落库失败时保留 Redis，后续读取可补完同一次交接。
     */
    public function testMysqlWriteFailureLeavesRedisForRecovery(): void
    {
        $failed = $this->record('recover', 1);
        $this->redisRecords([
            $this->record('recover'),
            $failed,
            $failed,
        ]);
        $this->selectResults = [
            [],
            [],
            [
                $failed,
            ],
        ];
        $this->insertResults = [
            new RuntimeException('synthetic database outage'),
        ];
        $repo = $this->repository();
        try {
            $repo->updateById(self::ID, [
                'status' => 1,
            ]);
            self::fail('落库失败必须抛错');
        } catch (RuntimeException $exception) {
            self::assertSame('synthetic database outage', $exception->getMessage());
        }
        self::assertNotContains('redis:delete', $this->events);
        self::assertSame('recover', $repo->getById(self::ID)->args);
        self::assertSame('redis:delete', end($this->events));
    }

    /**
     * 落库后删 Redis 失败允许双份，恢复时保留数据库的新重试次数和状态。
     */
    public function testRedisDeleteFailurePreservesDatabaseProgressOnRecovery(): void
    {
        $failed = $this->record('stale', 1);
        $advanced = $this->record('authoritative', 1, 2);
        $this->redisRecords([
            $this->record('stale'),
            $failed,
            $failed,
        ], [
            false,
            1,
        ]);
        $this->selectResults = [
            [],
            [
                $failed,
            ],
            [
                $advanced,
            ],
        ];
        $repo = $this->repository();
        try {
            $repo->updateById(self::ID, [
                'status' => 1,
            ]);
            self::fail('Redis 删除失败必须抛错');
        } catch (RuntimeException $exception) {
            self::assertSame('Failed to persist async event', $exception->getMessage());
        }
        $loaded = $repo->getById(self::ID);
        self::assertSame('authoritative', $loaded->args);
        self::assertSame(2, $loaded->retry_times);
        self::assertSame(1, count(array_filter($this->events, static function ($event) {
            return $event === 'insert';
        })));
        self::assertNotContains('update', $this->events);
    }

    /**
     * 外层事务未提交时不删 Redis，模拟回滚后仍可重新转存。
     */
    public function testUncommittedTransactionKeepsRedisUntilRecovery(): void
    {
        $failed = $this->record('transaction', 1);
        $this->redisRecords([
            $this->record('transaction'),
            $failed,
            $failed,
        ]);
        $this->selectResults = [
            [],
            [
                $failed,
            ],
            [],
            [
                $failed,
            ],
        ];
        $this->transactionLevel = 1;
        $repo = $this->repository();
        $repo->updateById(self::ID, [
            'status' => 1,
        ]);
        self::assertNotContains('redis:delete', $this->events);
        $this->transactionLevel = 0;
        self::assertSame('transaction', $repo->getById(self::ID)->args);
        self::assertSame('redis:delete', end($this->events));
    }

    /**
     * 主键冲突使用无副作用的自赋值，重读数据库而不覆盖已推进的记录。
     */
    public function testDuplicateInsertUsesExistingDatabaseRecord(): void
    {
        $this->redisRecords([
            $this->record('stale', 1),
        ]);
        $this->selectResults = [
            [],
            [
                $this->record('newer', 1, 2),
            ],
        ];
        $this->insertResults = [
            0,
        ];
        $loaded = $this->repository()
            ->getById(self::ID);
        self::assertSame('newer', $loaded->args);
        self::assertSame(2, $loaded->retry_times);
        self::assertSame('redis:delete', end($this->events));
        self::assertNotContains('update', $this->events);
    }

    /**
     * 非唯一键写入错误不得被忽略，也不能删除 Redis 副本。
     */
    public function testNonDuplicateQueryFailureDoesNotDeleteRedis(): void
    {
        $this->redisRecords([
            $this->record('preserve', 1),
        ]);
        $this->selectResults = [
            [],
        ];
        $this->insertResults = [
            $this->queryFailure(1406),
        ];
        try {
            $this->repository()
            ->getById(self::ID);
            self::fail('非唯一键错误必须抛出');
        } catch (QueryException $exception) {
            self::assertSame(1406, $exception->errorInfo[1]);
        }
        self::assertNotContains('redis:delete', $this->events);
    }

    /**
     * 数据库存量继续读取、更新和重试，不再复制回 Redis。
     */
    public function testLegacyDatabaseRecordsStayInDatabase(): void
    {
        $record = $this->record('legacy', 1);
        $this->redisRecords(array_fill(0, 4, []));
        $this->selectResults = array_fill(0, 3, [
            $record,
        ]);
        $repo = $this->repository();
        self::assertSame('legacy', $repo->getById(self::ID)->args);
        $repo->updateById(self::ID, [
            'status' => 3,
        ]);
        $repo->retryById(self::ID);
        self::assertSame(0, $repo->deleteById(self::ID));
        self::assertSame([
            'select',
            'select',
            'update',
            'select',
            'update',
            'delete',
        ], $this->events);
    }

    /**
     * 直接成功清理双份时先删 Redis，避免删除数据库后残留旧失败副本。
     */
    public function testSuccessDeletesRedisResidueBeforeDatabase(): void
    {
        $this->redisRecords([
            $this->record('residue', 1),
        ]);
        self::assertSame(1, $this->repository()
            ->deleteById(self::ID));
        self::assertSame([
            'redis:delete',
            'delete',
        ], $this->events);
    }

    /**
     * Redis 副本未清理成功时，不提前删除数据库的权威恢复记录。
     */
    public function testSuccessCleanupFailureDoesNotDeleteDatabase(): void
    {
        $this->redisRecords([
            $this->record('residue', 1),
        ], [
            false,
        ]);
        try {
            $this->repository()
            ->deleteById(self::ID);
            self::fail('删除失败必须抛错');
        } catch (RuntimeException $exception) {
            self::assertSame('Failed to persist async event', $exception->getMessage());
        }
        self::assertSame([], $this->queries);
    }

    /**
     * 读取异常不能误判缺失而回退数据库。
     */
    public function testRedisReadFailureDoesNotFallBack(): void
    {
        $this->redis->method('__call')
            ->willThrowException(new RuntimeException('synthetic outage'));
        $this->expectException(RuntimeException::class);
        $this->repository()
            ->getById(self::ID);
    }

    /**
     * Redis 返回失败值同样抛错，不能误 ACK 尚未读取的事件。
     */
    public function testRedisFalseReadDoesNotFallBack(): void
    {
        $this->redis->method('__call')
            ->with('hGetAll')
            ->willReturn(false);
        $this->expectException(RuntimeException::class);
        $this->repository()
            ->getById(self::ID);
    }

    /**
     * 数据库中的压缩存量与新 Redis 正文使用相同解码契约。
     */
    public function testCompressedDatabaseRecordIsDecoded(): void
    {
        $raw = str_repeat('synthetic-', 1000);
        $encoded = '@async-event-args:v1:' . strlen($raw) . ':' . base64_encode(gzencode($raw));
        $this->redisRecords([
            [],
        ]);
        $this->selectResults = [
            [
                $this->record($encoded, 1),
            ],
        ];
        self::assertSame($raw, $this->repository()
            ->getById(self::ID)->args);
    }

    /**
     * 永久压缩错误完整转存为失败记录，不把正文交给执行器。
     * @dataProvider invalidArgs
     */
    public function testInvalidCompressedArgsAreTransferredAsFailed(string $encoded): void
    {
        $waiting = $this->record($encoded);
        $this->redisRecords([
            $waiting,
            $waiting,
            $this->record($encoded, 3),
        ]);
        $this->selectResults = [
            [],
            [
                $this->record($encoded, 3),
            ],
        ];
        $this->logger->expects($this->once())
            ->method('warning');
        self::assertNull($this->repository()
            ->getById(self::ID));
        self::assertSame('3', $this->insertedRecord()['status']);
        self::assertSame($encoded, $this->insertedRecord()['args']);
        self::assertSame('redis:delete', end($this->events));
    }

    /**
     * 合成数据覆盖未知版本、损坏编码和伪造解压长度。
     */
    public static function invalidArgs(): array
    {
        return [
            [
                '@async-event-args:v2:1:eA==',
            ],
            [
                '@async-event-args:v1:5:invalid!',
            ],
            [
                '@async-event-args:v1:1:' . base64_encode(gzencode(str_repeat('x', 2000000))),
            ],
        ];
    }

    /**
     * 额度不足属于环境错误，不将合法压缩记录转存为永久失败。
     */
    public function testInsufficientCapacityDoesNotRejectRecord(): void
    {
        $this->redisRecords([
            $this->record('@async-event-args:v1:2000000:unused'),
        ]);
        $this->logger->expects($this->never())
            ->method('warning');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('capacity');
        $this->repository()
            ->getById(self::ID);
    }

    /**
     * 小数据、高熵数据与超额度原文不被压缩成更大或无法读取的格式。
     */
    public function testCompressionKeepsOriginalWhenNotBeneficialOrOverCapacity(): void
    {
        $noise = '';
        for ($i = 0; $i < 300; ++$i) {
            $noise .= hash('sha256', (string) $i, true);
        }
        $large = str_repeat('synthetic-', 120000);
        $stored = [];
        $this->redis->method('__call')
            ->with('eval')
            ->willReturnCallback(function ($method, $args) use (&$stored) {
                $stored[] = $this->fields($args[1])['args'];
                return 1;
            });
        $repo = $this->repository();
        foreach ([
            'small',
            $noise,
            $large,
        ] as $args) {
            $repo->create($this->record($args));
        }
        self::assertSame([
            'small',
            $noise,
            $large,
        ], $stored);
    }

    /**
     * 两端超时 ID 合并去重，Redis 待执行记录扫描时不提前落库。
     */
    public function testTimeoutScanMergesBothStoresWithoutPersistingWaitingRedisRecords(): void
    {
        $this->redisRecords([
            $this->record('waiting'),
        ], [], [
            (string) self::ID,
        ]);
        $this->selectResults = [
            [
                [
                    'id' => self::ID,
                ],
                [
                    'id' => self::ID + 1,
                ],
            ],
        ];
        self::assertSame([
            self::ID,
            self::ID + 1,
        ], $this->repository()
            ->getTimeoutRecordIds('2026-01-02 00:00:00'));
        self::assertSame([
            'select',
        ], $this->events);
    }

    /**
     * 状态 3 的待转存副本由扫描补落库，但不能作为自动重试 ID 返回。
     */
    public function testTimeoutScanTransfersTerminalResidueWithoutExecutingIt(): void
    {
        $this->redisRecords([
            $this->record('terminal', 3),
            $this->record('terminal', 3),
        ], [], [
            (string) self::ID,
        ]);
        $this->selectResults = [
            [],
            [
                $this->record('terminal', 3),
            ],
            [],
        ];
        self::assertSame([], $this->repository()
            ->getTimeoutRecordIds('2026-01-02 00:00:00'));
        self::assertSame('3', $this->insertedRecord()['status']);
        self::assertStringContainsString('`status` in (?, ?)', $this->queries[3][1]);
        self::assertSame([
            'select',
            'insert',
            'select',
            'redis:delete',
            'select',
        ], $this->events);
    }

    /**
     * Redis 旧副本不得使数据库中已经超限的记录重新自动执行。
     */
    public function testTimeoutScanPreservesTerminalDatabaseProgress(): void
    {
        $this->redisRecords([
            $this->record('stale', 1),
            $this->record('stale', 1),
        ], [], [
            (string) self::ID,
        ]);
        $this->selectResults = [
            [
                $this->record('terminal', 3, 3),
            ],
            [],
        ];
        self::assertSame([], $this->repository()
            ->getTimeoutRecordIds('2026-01-02 00:00:00'));
        self::assertNotContains('insert', $this->events);
        self::assertNotContains('update', $this->events);
    }

    /**
     * 扫描多个失败副本时批量查重和落库，不在逐条循环中执行 SQL。
     */
    public function testTimeoutRecoveryTransfersMultipleRecordsInOneBatch(): void
    {
        $first = $this->record('first', 1);
        $second = $this->record('second', 3);
        $second['id'] = self::ID + 1;
        $this->redisRecords([
            $first,
            $second,
            $first,
            $second,
        ], [], [
            (string) self::ID,
            (string) (self::ID + 1),
        ]);
        $this->selectResults = [
            [],
            [
                $first,
                $second,
            ],
            [],
        ];
        $this->locker->expects($this->exactly(2))
            ->method('acquire');
        $this->locker->expects($this->exactly(2))
            ->method('release');
        self::assertSame([], $this->repository()
            ->getTimeoutRecordIds('2026-01-02 00:00:00'));
        self::assertSame([
            'select',
            'insert',
            'select',
            'redis:delete',
            'redis:delete',
            'select',
        ], $this->events);
        self::assertStringContainsString('), (', $this->queries[1][1]);
        self::assertContains((string) self::ID, $this->queries[1][2]);
        self::assertContains((string) (self::ID + 1), $this->queries[1][2]);
    }

    /**
     * 执行器仍持有事件锁时不补转存，避免与成功清理并发重建数据库记录。
     */
    public function testTimeoutRecoverySkipsLockedEvent(): void
    {
        $this->lockAvailable = false;
        $this->redisRecords([
            $this->record('busy', 1),
        ], [], [
            (string) self::ID,
        ]);
        $this->selectResults = [
            [],
        ];
        $this->locker->expects($this->once())
            ->method('acquire')
            ->with('async_event_run_' . self::ID, self::isType('string'));
        $this->locker->expects($this->never())
            ->method('release');
        self::assertSame([], $this->repository()
            ->getTimeoutRecordIds('2026-01-02 00:00:00'));
        self::assertSame([
            'select',
        ], $this->events);
    }

    /**
     * 获取锁前已成功清理的记录，锁内重读缺失后不使用旧快照重新落库。
     */
    public function testTimeoutRecoveryRereadsAfterAcquiringLock(): void
    {
        $this->redisRecords([
            $this->record('already cleaned', 1),
            [],
        ], [], [
            (string) self::ID,
        ]);
        $this->selectResults = [
            [],
        ];
        $this->locker->expects($this->once())
            ->method('release');
        self::assertSame([], $this->repository()
            ->getTimeoutRecordIds('2026-01-02 00:00:00'));
        self::assertSame([
            'select',
        ], $this->events);
    }

    /**
     * 一条失败副本交接故障不阻塞后续 Redis 待执行记录。
     */
    public function testTransferRecoveryFailureDoesNotStarveOtherRedisRecords(): void
    {
        $waiting = $this->record('waiting');
        $waiting['id'] = self::ID + 1;
        $this->redisRecords([
            $this->record('pending', 1),
            $waiting,
            $this->record('pending', 1),
        ], [], [
            (string) self::ID,
            (string) (self::ID + 1),
        ]);
        $this->selectResults = [
            new RuntimeException('synthetic transfer outage'),
            [],
        ];
        $this->logger->expects($this->once())
            ->method('error');
        self::assertSame([
            self::ID + 1,
        ], $this->repository()
            ->getTimeoutRecordIds('2026-01-02 00:00:00'));
    }

    /**
     * 数据库扫描故障时仍返回 Redis 的可恢复待执行记录。
     */
    public function testTimeoutScanRetainsRedisResultsWhenDatabaseFails(): void
    {
        $this->redisRecords([
            $this->record('waiting'),
        ], [], [
            (string) self::ID,
        ]);
        $this->selectResults = [
            new RuntimeException('synthetic database outage'),
        ];
        $this->logger->expects($this->once())
            ->method('error');
        self::assertSame([
            self::ID,
        ], $this->repository()
            ->getTimeoutRecordIds('2026-01-02 00:00:00'));
    }

    /**
     * 两端扫描均失败时抛错，不伪装为没有待恢复记录。
     */
    public function testTimeoutScanThrowsWhenBothStoresFail(): void
    {
        $this->redis->method('__call')
            ->willThrowException(new RuntimeException('synthetic redis outage'));
        $this->selectResults = [
            new RuntimeException('synthetic database outage'),
        ];
        $this->logger->expects($this->exactly(2))
            ->method('error');
        $this->expectException(RuntimeException::class);
        $this->repository()
            ->getTimeoutRecordIds('2026-01-02 00:00:00');
    }

    /**
     * Redis 状态更新已丢失记录时不能再创建 MySQL 孤儿。
     */
    public function testMissingRedisMutationDoesNotCreateDatabaseRecord(): void
    {
        $this->redis->method('__call')
            ->willReturnCallback(function ($method) {
                return $method === 'hGetAll' ? $this->hash($this->record('race')) : 0;
            });
        $this->repository()
            ->updateById(self::ID, [
            'status' => 1,
        ]);
        self::assertSame([], $this->queries);
    }

    /**
     * 保留两端有界历史清理和原有时间、状态条件。
     */
    public function testHistoryCleanupUsesBoundedCandidates(): void
    {
        $this->selectResults = [
            [],
        ];
        $queries = 0;
        $this->redis->method('__call')
            ->willReturnCallback(function ($method, $args) use (&$queries) {
                if ($method === 'zRangeByScore') {
                    self::assertSame(100, $args[3]['limit'][1]);
                    return $queries++ === 0 ? [
                        (string) self::ID,
                    ] : [];
                }
                self::assertSame('purge', $args[1][4]);
                self::assertSame('2', $args[1][7]);
                return 1;
            });
        self::assertSame(1, $this->repository()
            ->deleteHistory([
            [
                'updated_at',
                '<=',
                '2026-01-02 00:00:00',
            ],
            [
                'status',
                '=',
                2,
            ],
        ]));
    }

    /**
     * 固定合成配置，不设置写入后端或兼容、压缩开关。
     */
    private function repository(): AsyncEventRepository
    {
        $this->factory->method('get')
            ->with('default')
            ->willReturn($this->redis);
        $config = new Config([
            'async_event' => [
                'args_compression' => [
                    'max_decoded_bytes' => 1048576,
                ],
            ],
        ]);
        return new AsyncEventRepository(
            new AsyncEventArgsCodec($config),
            new RedisAsyncEventStorage($config, $this->factory),
            $this->logger,
            $this->locker
        );
    }

    /**
     * 显式排队 Redis 快照和删除结果，仅捕获脚本参数，不模拟 Lua 的实现。
     */
    private function redisRecords(array $records, array $deletes = [], array $scanIds = []): void
    {
        $this->redis->method('__call')
            ->willReturnCallback(function ($method, $args) use (&$records, &$deletes, $scanIds) {
                if ($method === 'hGetAll') {
                    self::assertNotEmpty($records, '发生未计划的 Redis 读取');
                    $record = array_shift($records);
                    return $record === [] ? [] : $this->hash($record);
                }
                if ($method === 'zRangeByScore') {
                    self::assertSame(50, $args[3]['limit'][1]);
                    self::assertSame('(' . strtotime('2026-01-02 00:00:00'), $args[2]);
                    return $scanIds;
                }
                self::assertSame('eval', $method);
                $operation = $args[1][4];
                $this->events[] = 'redis:' . $operation;
                if ($operation !== 'create') {
                    self::assertArrayNotHasKey('args', $this->fields($args[1]));
                }
                return $operation === 'delete' && $deletes !== [] ? array_shift($deletes) : 1;
            });
    }

    /**
     * 构造带 MySQL 错误号的合成异常，不包含真实 SQL 或业务数据。
     */
    private function queryFailure(int $code): QueryException
    {
        $previous = new PDOException('synthetic query failure');
        $previous->errorInfo = [
            $code === 1062 ? '23000' : '22001',
            $code,
            'synthetic query failure',
        ];
        return new QueryException('insert synthetic', [], $previous);
    }

    /**
     * 从真实 ORM 编译的单条 INSERT 读取参数，校验完整交接数据。
     */
    private function insertedRecord(): array
    {
        foreach ($this->queries as [$method, $sql, $bindings]) {
            if ($method === 'insert') {
                self::assertSame(1, preg_match('/\(([^)]+)\) values /', $sql, $matches));
                $columns = array_map(static function ($column) {
                    return trim($column, '` ');
                }, explode(',', $matches[1]));
                return array_combine($columns, $bindings);
            }
        }
        self::fail('未发生预期 INSERT');
    }

    /**
     * 使用占位字段与大于双精度精确范围的 ID，避免真实数据并验证精度。
     */
    private function record(string $args, int $status = 0, int $retries = 0): array
    {
        return [
            'id' => self::ID,
            'event' => 'SyntheticEvent',
            'listener' => 'SyntheticListener',
            'args' => $args,
            'context_data' => [
                'language' => 'en',
            ],
            'status' => $status,
            'retry_times' => $retries,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ];
    }

    /**
     * 表达 Redis 返回的标量字段及独立 JSON 上下文。
     */
    private function hash(array $record): array
    {
        $record['context_data'] = json_encode($record['context_data']);
        return array_map('strval', $record);
    }

    /**
     * 从被 Mock 截断的脚本调用读取字段，仅验证调用契约。
     */
    private function fields(array $parameters): array
    {
        $fields = [];
        foreach (array_chunk(array_slice($parameters, 8), 2) as $pair) {
            $fields[$pair[0]] = $pair[1];
        }
        return $fields;
    }
}
