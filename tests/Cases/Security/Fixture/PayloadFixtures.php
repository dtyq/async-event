<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace HyperfTest\Cases\Security\Fixture;

use DateTimeImmutable;
use HyperfTest\Cases\Security\Fixture\FixtureMeta as FixtureDate;

/**
 * 测试夹具：模拟业务事件的类型树，包含嵌套实体、联合类型、接口类型、循环引用、枚举和日期。
 */
enum FixtureStatus: string
{
    case Active = 'active';
}

interface FixtureFileInterface
{
}

class FixtureLocalFile implements FixtureFileInterface
{
    public string $path = 'local';

    public ?FixtureDate $meta = null;
}

class FixtureMeta
{
    public string $name = 'meta';
}

/**
 * 测试夹具：同一顶层命名空间下继承具体类的子类，模拟扩展包继承主仓库实体。
 */
class FixtureExtendedMeta extends FixtureDate
{
    public ?FixtureLocalFile $extra = null;
}

class FixtureEntity
{
    public FixtureStatus $status = FixtureStatus::Active;

    public ?FixtureFileInterface $file = null;

    public ?DateTimeImmutable $createdAt = null;

    public ?FixtureEntity $parent = null;

    public FixtureLocalFile|FixtureMeta|null $either = null;

    /** @var FixtureDate[] */
    public array $metas = [];

    /** @var array<string, FixtureDate> */
    public array $dates = [];
}

/**
 * 测试夹具：构造器提升属性通过 @param 声明数组元素类型。
 */
class FixtureBatchEvent
{
    /**
     * @param FixtureEntity[] $entities
     */
    public function __construct(
        public array $entities = [],
    ) {
    }
}

class FixtureEvent
{
    public function __construct(
        public FixtureEntity $entity,
        public string $organizationCode = 'org',
    ) {
    }
}

class FixtureChildEvent extends FixtureEvent
{
}

/**
 * 测试探针：带魔术方法，被实例化时记录事件，用于证明名单外的类不会被实例化。
 */
class FixtureProbe
{
    /** @var string[] */
    public static array $events = [];

    public function __destruct()
    {
        self::$events[] = 'destruct';
    }

    public function __wakeup(): void
    {
        self::$events[] = 'wakeup';
    }
}

/**
 * 属性没有类型声明的事件：其中装的对象无法从类型声明收集，应被拒绝（失败即安全）。
 */
class FixtureUntypedEvent
{
    public $payload;
}
