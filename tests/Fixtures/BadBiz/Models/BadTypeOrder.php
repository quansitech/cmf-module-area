<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Tests\Fixtures\BadBiz\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 引用列类型为 varchar 的模型：sync 时应被拒绝登记（PG 类型严格校验）。
 */
class BadTypeOrder extends Model
{
    protected $table = 'bad_orders';

    /** @var list<string> */
    protected $fillable = ['title', 'region_id'];

    public static function areaReferences(): array
    {
        return [
            'region_id' => ['onMerge' => 'keep'],
        ];
    }
}
