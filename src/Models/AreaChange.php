<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 区划变更记录：每次版本升级的逐条变更档案，
 * 由迁移在业务项目执行时写入，支撑迁移执行、回滚与事后审计。
 *
 * v2（changes.json 语义规范化）：记录分 node / edge 两类（kind），
 * node 记录带版本侧（side=old/new）；edge 记录的 old_id/new_id 列存
 * from/to。kind/side 为 null 的是 v1 存量行（按 v1 语义展示）。
 *
 * @property int $id
 * @property string $version 所属上游版本
 * @property string|null $kind v2 记录类别：node / edge（null = v1 存量）
 * @property string|null $side node 记录的版本侧：old / new（edge 与 v1 存量为 null）
 * @property string $change_type
 * @property int|null $old_id
 * @property int|null $new_id
 * @property string|null $old_name
 * @property string|null $new_name
 * @property array<string, mixed>|null $detail
 * @property string|null $evidence_url
 * @property string|null $evidence_title
 * @property string|null $ai_summary
 * @property Carbon|null $applied_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AreaChange extends Model
{
    public const KIND_NODE = 'node';

    public const KIND_EDGE = 'edge';

    public const TYPE_ADD = 'add';

    public const TYPE_SPLIT_FROM = 'split_from';

    public const TYPE_MERGE_INTO = 'merge_into';

    public const TYPE_RENAME = 'rename';

    public const TYPE_ABOLISH = 'abolish';

    public const TYPE_PARENT_CHANGE = 'parent_change';

    public const TYPE_CODE_CHANGE = 'code_change';

    public const TYPE_CODE_REUSE = 'code_reuse';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_ADD,
        self::TYPE_SPLIT_FROM,
        self::TYPE_MERGE_INTO,
        self::TYPE_RENAME,
        self::TYPE_ABOLISH,
        self::TYPE_PARENT_CHANGE,
        self::TYPE_CODE_CHANGE,
        self::TYPE_CODE_REUSE,
    ];

    protected $table = 'cmf_area_changes';

    /** @var list<string> */
    protected $fillable = [
        'version', 'kind', 'side', 'change_type', 'old_id', 'new_id', 'old_name', 'new_name',
        'detail', 'evidence_url', 'evidence_title', 'ai_summary', 'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'old_id' => 'integer',
            'new_id' => 'integer',
            'detail' => 'array',
            'applied_at' => 'datetime',
        ];
    }
}
