<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\AsyncEvent\Kernel\Security;

use RuntimeException;

/**
 * 异步事件记录未通过安全校验：listener 未注册、事件类不匹配，或 args 中出现事件定义以外的类。
 * 属于数据问题，重试不会改变结果，执行器直接将记录标记为失败。
 */
class AsyncEventPayloadException extends RuntimeException
{
}
