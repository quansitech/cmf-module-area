<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Tests\Fixtures\Biz\Models;

use Illuminate\Database\Eloquent\Model;
use Quansitech\Cmf\Area\Casts\AreaIdCast;

/**
 * 测试门店：remap 策略（当前状态型），area_id 挂 AreaIdCast。
 *
 * @property int|null $area_id
 */
class Store extends Model
{
    protected $table = 'stores';

    /** @var list<string> */
    protected $fillable = ['title', 'area_id'];

    protected $casts = [
        'area_id' => AreaIdCast::class,
    ];

    public static function areaReferences(): array
    {
        return [
            'area_id' => ['onMerge' => 'remap'],
        ];
    }
}
