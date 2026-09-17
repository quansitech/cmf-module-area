<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Quansitech\Cmf\Area\Exceptions\AreaReferenceNotRegisteredException;
use Quansitech\Cmf\Area\Services\ReferenceCollector;

/**
 * 模型写入路径的注册强制校验（第 2 层，覆盖 API/任务/导入等非表单路径）。
 *
 * 用法：
 *     protected $casts = ['region_id' => \Quansitech\Cmf\Area\Casts\AreaIdCast::class];
 *
 * 赋值/保存时校验该字段已在模型的 areaReferences() 中声明，未声明抛异常。
 */
class AreaIdCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return filled($value) ? (int) $value : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if (filled($value) && ! app(ReferenceCollector::class)->isDeclared($model::class, $key)) {
            throw AreaReferenceNotRegisteredException::forField($model::class, $model->getTable(), $key);
        }

        return filled($value) ? (int) $value : null;
    }
}
