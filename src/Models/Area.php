<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 行政区划：与上游 ok_data_level4.csv 字段一致，另加状态与承继字段。
 * 撤销不删行（status=0），保证历史业务数据可回显名称、迁移可回滚。
 *
 * @property int $id 上游短编号（如东莞 4419）
 * @property int $pid 上级 ID
 * @property int $deep 0省 1市 2区 3镇
 * @property string $name 精简名
 * @property string $pinyin_prefix
 * @property string $pinyin
 * @property int $ext_id 数据源原始编号（12 位长码）
 * @property string $ext_name 完整名
 * @property int $status 1正常 0已撤销/停用
 * @property int|null $successor_id 撤销/合并后的主要承继地区 ID
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Area extends Model
{
    protected $table = 'cmf_areas';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id', 'pid', 'deep', 'name', 'pinyin_prefix', 'pinyin',
        'ext_id', 'ext_name', 'status', 'successor_id',
    ];

    /** 正常（未撤销） */
    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 1);
    }

    /**
     * @return BelongsTo<Area, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'pid');
    }

    /**
     * @return HasMany<Area, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(static::class, 'pid');
    }

    /**
     * 承继地区（撤销/合并后）。
     *
     * @return BelongsTo<Area, $this>
     */
    public function successor(): BelongsTo
    {
        return $this->belongsTo(static::class, 'successor_id');
    }

    /**
     * 全路径名称（如 "湖北省 武汉市 洪山区"），向上逐级拼接 ext_name。
     *
     * @param  bool  $useExtName  true 用完整名，false 用精简名
     */
    public function fullName(bool $useExtName = true, string $separator = ' '): string
    {
        $names = [];
        $node = $this;
        $guard = 0;

        while ($node instanceof Area && $guard++ < 6) {
            array_unshift($names, $useExtName ? $node->ext_name : $node->name);
            $node = $node->pid ? static::query()->find($node->pid) : null;
        }

        return implode($separator, $names);
    }

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'pid' => 'integer',
            'deep' => 'integer',
            'ext_id' => 'integer',
            'status' => 'integer',
            'successor_id' => 'integer',
        ];
    }
}
