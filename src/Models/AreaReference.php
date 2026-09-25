<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 业务引用登记：记录"哪些表的哪些字段"引用了地区 ID，
 * 作为区划变更时数据迁移的影响面依据。
 *
 * @property int $id
 * @property string $table_name
 * @property string $column_name
 * @property string|null $pk_column 业务表主键列（行级回滚日志寻址用，null=执行期探测，默认 id）
 * @property string $merge_strategy keep / remap
 * @property string|null $snapshot_column 名称快照列
 * @property string $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AreaReference extends Model
{
    public const STRATEGY_KEEP = 'keep';

    public const STRATEGY_REMAP = 'remap';

    /** @var list<string> */
    public const STRATEGIES = [self::STRATEGY_KEEP, self::STRATEGY_REMAP];

    /** @var list<string> */
    protected $fillable = [
        'table_name', 'column_name', 'pk_column', 'merge_strategy', 'snapshot_column', 'description',
    ];

    public static function tableName(): string
    {
        return (string) config('cmf-area.references_table', 'cmf_area_references');
    }

    public function getTable(): string
    {
        return static::tableName();
    }

    public function isRemap(): bool
    {
        return $this->merge_strategy === self::STRATEGY_REMAP;
    }
}
