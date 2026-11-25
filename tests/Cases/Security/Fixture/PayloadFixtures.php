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
 * 属性没有类型声明的事件：其中装的对象无法从类型声明收集，只能走兜底规则。
 */
class FixtureUntypedEvent
{
    public $payload;
}

/**
 * 测试夹具：不在任何类型声明中、也没有反序列化入口方法的值对象，模拟只声明为 array 的属性里存放的对象。
 */
class FixtureInertItem
{
    public string $name = 'item';

    /** @var array<int, mixed> */
    public array $children = [];
}

/**
 * 测试夹具：本身没有入口方法，但带类型的属性指向探针；兜底放行它时不能连带放行探针。
 */
class FixtureInertHolder
{
    public ?FixtureProbe $probe = null;
}

/**
 * 测试夹具：只定义了 __unserialize 的类，兜底规则不能放行。
 */
class FixtureUnserializeHook
{
    /** @var string[] */
    public static array $events = [];

    public string $name = 'hook';

    public function __serialize(): array
    {
        return ['name' => $this->name];
    }

    public function __unserialize(array $data): void
    {
        self::$events[] = 'unserialize';
        $this->name = (string) ($data['name'] ?? '');
    }
}
