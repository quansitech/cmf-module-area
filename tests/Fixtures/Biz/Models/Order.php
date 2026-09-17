<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Tests\Fixtures\Biz\Models;

use Illuminate\Database\Eloquent\Model;
use Quansitech\Cmf\Area\Casts\AreaIdCast;

/**
 * 测试订单：声明了地区引用（keep + 名称快照），region_id 挂 AreaIdCast。
 *
 * @property int|null $region_id
 * @property string|null $region_name
 */
class Order extends Model
{
    protected $table = 'orders';

    /** @var list<string> */
    protected $fillable = ['title', 'region_id', 'region_name'];

    protected $casts = [
        'region_id' => AreaIdCast::class,
    ];

    public static function areaReferences(): array
    {
        return [
            'region_id' => ['onMerge' => 'keep', 'snapshotColumn' => 'region_name'],
        ];
    }
}
