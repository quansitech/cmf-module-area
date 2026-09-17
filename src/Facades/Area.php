<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Facades;

use Illuminate\Support\Facades\Facade;
use Quansitech\Cmf\Area\Services\ReferenceCollector;

/**
 * Area Facade：兜底注册口等静态入口。
 *
 * @method static void registerReference(string $table, string $column, string $onMerge = 'keep', ?string $snapshotColumn = null, string $description = '')
 *
 * @see ReferenceCollector
 */
class Area extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cmf-area';
    }
}
