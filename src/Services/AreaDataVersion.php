<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Services;

use Illuminate\Support\Facades\DB;

/**
 * 库内区划数据的版本标记（cmf_area_meta.data_version）。
 *
 * 用途：update 迁移的执行守卫——payload 里冻结的 from_version 与库内当前
 * 数据版本不一致时跳过（典型场景：全新安装导入的基线已包含该次变更，
 * 历史 update 重放会造成 archive 类操作误删有效行）。
 *
 * 标记推进时机：seed 导入完成后写入基线版本；每个 update 迁移执行成功后
 * 推进为自己的 version。空标记视为"未接入守卫的老项目"，一律放行（兼容）。
 */
class AreaDataVersion
{
    public const TABLE = 'cmf_area_meta';

    public const KEY_DATA_VERSION = 'data_version';

    /**
     * 当前库内区划数据版本；从未写入过返回 null。
     */
    public function current(): ?string
    {
        $value = DB::table(self::TABLE)->where('key', self::KEY_DATA_VERSION)->value('value');

        return $value === null ? null : (string) $value;
    }

    /**
     * 写入/推进当前数据版本。
     */
    public function mark(string $version): void
    {
        DB::table(self::TABLE)->updateOrInsert(
            ['key' => self::KEY_DATA_VERSION],
            ['value' => $version],
        );
    }

    /**
     * 守卫判定：空标记（老项目）放行；当前版本与 from_version 一致放行；
     * 不一致（当前版本已越过本迁移的基线）跳过。
     */
    public function shouldApply(string $fromVersion): bool
    {
        $current = $this->current();

        return $current === null || $current === $fromVersion;
    }
}
