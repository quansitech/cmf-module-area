<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Exceptions;

use RuntimeException;

/**
 * 业务字段未在模型的 areaReferences() 中声明地区引用时抛出。
 * 异常信息携带可直接粘贴的声明代码模板（见开发方案 §6.3）。
 */
class AreaReferenceNotRegisteredException extends RuntimeException
{
    /**
     * @param  class-string  $modelClass
     */
    public static function forField(string $modelClass, string $table, string $column): static
    {
        $short = class_basename($modelClass);

        return new static(
            "[{$table}.{$column}] 未声明地区引用。\n"
            ."请在 {$short} 模型中添加：\n\n"
            ."    public static function areaReferences(): array\n"
            ."    {\n"
            ."        return [\n"
            ."            '{$column}' => ['onMerge' => 'keep'],\n"
            ."        ];\n"
            ."    }\n\n"
            ."onMerge 可选值：keep（历史事实，默认）/ remap（当前状态）"
        );
    }
}
