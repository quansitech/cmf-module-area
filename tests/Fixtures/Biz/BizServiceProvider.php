<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Tests\Fixtures\Biz;

use Illuminate\Support\ServiceProvider;

/**
 * 模拟 vendor 业务扩展的 ServiceProvider：sync 以它为锚点反推包根目录并扫描模型。
 */
class BizServiceProvider extends ServiceProvider
{
    //
}
