<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Tests\Fixtures\Biz\Models;

use Illuminate\Database\Eloquent\Model;
use Quansitech\Cmf\Area\Casts\AreaIdCast;

/**
 * 未声明地区引用的模型：用于强制校验测试。
 */
class UndeclaredOrder extends Model
{
    protected $table = 'orders';

    /** @var list<string> */
    protected $fillable = ['title', 'region_id'];

    protected $casts = [
        'region_id' => AreaIdCast::class,
    ];
}
