<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace AsyncEventForeignFixture;

/**
 * 测试夹具：位于事件类型树之外的顶层命名空间、没有入口方法的类；兜底规则只放行类型树所在命名空间，应被拒绝。
 */
class ForeignInertItem
{
    public string $name = 'foreign';
}
