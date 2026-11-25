<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace HyperfTest\Cases\Security;

use Dtyq\AsyncEvent\Kernel\Security\EventPayloadClassResolver;
use HyperfTest\Cases\AbstractTestCase;
use HyperfTest\Cases\Security\Fixture\FixtureEntity;
use HyperfTest\Cases\Security\Fixture\FixtureEvent;
use HyperfTest\Cases\Security\Fixture\FixtureFileInterface;
use HyperfTest\Cases\Security\Fixture\FixtureLocalFile;
use HyperfTest\Cases\Security\Fixture\FixtureMeta;
use HyperfTest\Cases\Security\Fixture\FixtureStatus;

require_once __DIR__ . '/Fixture/PayloadFixtures.php';

/**
 * 验证类型树收集：嵌套类、联合类型、枚举、循环引用，以及接口单独记录。
 * @internal
 * @coversNothing
 */
class EventPayloadClassResolverTest extends AbstractTestCase
{
    protected function setUp(): void
    {
        EventPayloadClassResolver::clear();
    }

    public function testCollectsTypeTree(): void
    {
        $tree = EventPayloadClassResolver::resolve(FixtureEvent::class);
        $classes = array_values($tree['classes']);
        $abstracts = array_values($tree['abstracts']);

        foreach ([FixtureEvent::class, FixtureEntity::class, FixtureStatus::class, FixtureMeta::class, FixtureLocalFile::class, 'DateTimeImmutable'] as $expected) {
            $this->assertContains($expected, $classes);
        }
        $this->assertContains(FixtureFileInterface::class, $abstracts);
        $this->assertNotContains(FixtureFileInterface::class, $classes);
    }

    public function testResultIsCached(): void
    {
        $first = EventPayloadClassResolver::resolve(FixtureEvent::class);
        $second = EventPayloadClassResolver::resolve('\\' . FixtureEvent::class);
        $this->assertSame($first, $second);
    }
}
