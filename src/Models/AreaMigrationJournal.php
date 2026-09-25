<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 迁移回滚日志（执行期行级现场）：apply 时每行写入前捕获 before-image，
 * revert 按 id DESC 逆序回放（见 docs/area-precise-rollback-and-changes-v3.md §3）。
 *
 * @property int $id 天然即 apply 执行序
 * @property string $version 迁移版本号
 * @property string $kind area=cmf_areas 整行 / biz=业务列单值 / biz_scan=无单主键列的值扫描回退
 * @property string $table_name biz 用；area 恒为 cmf_areas
 * @property string|null $column_name biz 用；area 为 null
 * @property string|null $pk 行主键值（biz_scan 为 null）
 * @property array<string, mixed>|int|string|null $before 改写前的值（biz=标量旧值；area=整行含时间戳；null=该行由 apply 新建）
 * @property int|null $to_value biz 用：改写后的值（revert 守卫条件）
 * @property Carbon|null $created_at
 */
class AreaMigrationJournal extends Model
{
    public const KIND_AREA = 'area';

    public const KIND_BIZ = 'biz';

    public const KIND_BIZ_SCAN = 'biz_scan';

    protected $table = 'cmf_area_migration_journal';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'version', 'kind', 'table_name', 'column_name', 'pk', 'before', 'to_value', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'to_value' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
