<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace HyperfTest\Cases\Security;

use DateTimeImmutable;
use Dtyq\AsyncEvent\Kernel\Security\AsyncEventArgsUnserializer;
use Dtyq\AsyncEvent\Kernel\Security\AsyncEventPayloadException;
use Dtyq\AsyncEvent\Kernel\Security\EventPayloadClassResolver;
use HyperfTest\Cases\AbstractTestCase;
use HyperfTest\Cases\Security\Fixture\FixtureBatchEvent;
use HyperfTest\Cases\Security\Fixture\FixtureChildEvent;
use HyperfTest\Cases\Security\Fixture\FixtureEntity;
use HyperfTest\Cases\Security\Fixture\FixtureEvent;
use HyperfTest\Cases\Security\Fixture\FixtureLocalFile;
use HyperfTest\Cases\Security\Fixture\FixtureMeta;
use HyperfTest\Cases\Security\Fixture\FixtureProbe;
use HyperfTest\Cases\Security\Fixture\FixtureStatus;
use HyperfTest\Cases\Security\Fixture\FixtureUntypedEvent;

require_once __DIR__ . '/Fixture/PayloadFixtures.php';

/**
 * 验证受限反序列化：合法事件正常还原，名单外的类不会被实例化，事件类必须与监听器声明匹配。
 * @internal
 * @coversNothing
 */
class AsyncEventArgsUnserializerTest extends AbstractTestCase
{
    protected function setUp(): void
    {
        EventPayloadClassResolver::clear();
        FixtureProbe::$events = [];
    }

    public function testRestoresLegitimateEventWithNestedTypes(): void
    {
        $entity = new FixtureEntity();
        $entity->file = new FixtureLocalFile();
        $entity->file->meta = new FixtureMeta();
        $entity->createdAt = new DateTimeImmutable('2026-01-01');
        $entity->parent = $entity;
        $entity->either = new FixtureMeta();
        $event = new FixtureEvent($entity);

        $restored = AsyncEventArgsUnserializer::unserialize(serialize($event), FixtureEvent::class, [FixtureEvent::class]);

        $this->assertInstanceOf(FixtureEvent::class, $restored);
        $this->assertInstanceOf(FixtureLocalFile::class, $restored->entity->file);
        $this->assertSame(FixtureStatus::Active, $restored->entity->status);
        $this->assertSame($restored->entity, $restored->entity->parent);
    }

    public function testRestoresObjectsInsideDocblockTypedArrays(): void
    {
        $entity = new FixtureEntity();
        $entity->metas = [new FixtureMeta(), new FixtureMeta()];
        $entity->dates = ['a' => new FixtureMeta()];
        $event = new FixtureBatchEvent([$entity]);

        $restored = AsyncEventArgsUnserializer::unserialize(serialize($event), FixtureBatchEvent::class, [FixtureBatchEvent::class]);

        $this->assertInstanceOf(FixtureEntity::class, $restored->entities[0]);
        $this->assertInstanceOf(FixtureMeta::class, $restored->entities[0]->metas[1]);
        $this->assertInstanceOf(FixtureMeta::class, $restored->entities[0]->dates['a']);
    }

    public function testRejectsProbeInsideDocblockTypedArray(): void
    {
        $entity = new FixtureEntity();
        $entity->metas = [new FixtureProbe()];
        $payload = serialize(new FixtureEvent($entity));
        unset($entity);
        FixtureProbe::$events = [];
        try {
            AsyncEventArgsUnserializer::unserialize($payload, FixtureEvent::class, [FixtureEvent::class]);
            $this->fail('expected rejection');
        } catch (AsyncEventPayloadException) {
        }
        $this->assertSame([], FixtureProbe::$events);
    }

    public function testAcceptsSubclassOfListenedEventInSameNamespace(): void
    {
        $event = new FixtureChildEvent(new FixtureEntity());
        $restored = AsyncEventArgsUnserializer::unserialize(serialize($event), FixtureChildEvent::class, [FixtureEvent::class]);
        $this->assertInstanceOf(FixtureChildEvent::class, $restored);
    }

    public function testRejectsProbeAsTopLevelWithoutInstantiation(): void
    {
        $payload = serialize(new FixtureProbe());
        FixtureProbe::$events = [];
        try {
            AsyncEventArgsUnserializer::unserialize($payload, FixtureEvent::class, [FixtureEvent::class]);
            $this->fail('expected rejection');
        } catch (AsyncEventPayloadException) {
        }
        $this->assertSame([], FixtureProbe::$events);
    }

    public function testRejectsProbeNestedInUntypedPropertyWithoutInstantiation(): void
    {
        $event = new FixtureUntypedEvent();
        $event->payload = new FixtureProbe();
        $payload = serialize($event);
        unset($event);
        FixtureProbe::$events = [];
        try {
            AsyncEventArgsUnserializer::unserialize($payload, FixtureUntypedEvent::class, [FixtureUntypedEvent::class]);
            $this->fail('expected rejection');
        } catch (AsyncEventPayloadException) {
        }
        $this->assertSame([], FixtureProbe::$events);
    }

    public function testRejectsEventNotListenedByListener(): void
    {
        $this->expectException(AsyncEventPayloadException::class);
        AsyncEventArgsUnserializer::unserialize(serialize(new FixtureEvent(new FixtureEntity())), FixtureEvent::class, [FixtureMeta::class]);
    }

    public function testRejectsMismatchBetweenRecordEventAndPayload(): void
    {
        $this->expectException(AsyncEventPayloadException::class);
        AsyncEventArgsUnserializer::unserialize(serialize(new FixtureMeta()), FixtureEvent::class, [FixtureEvent::class]);
    }

    public function testRejectsNonObjectPayload(): void
    {
        $this->expectException(AsyncEventPayloadException::class);
        AsyncEventArgsUnserializer::unserialize(serialize(['a' => 1]), FixtureEvent::class, [FixtureEvent::class]);
    }

    public function testRejectsBrokenPayload(): void
    {
        $this->expectException(AsyncEventPayloadException::class);
        AsyncEventArgsUnserializer::unserialize('not-serialized', FixtureEvent::class, [FixtureEvent::class]);
    }
}
