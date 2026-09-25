<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Quansitech\Cmf\Area\Services\AreaDataVersion;
use Quansitech\Cmf\Area\Services\ImportService;

/**
 * 初始数据导入：内置上游 2025.251231.260403 版四级行政区划（省市区乡镇）。
 * 导入完成后写入数据版本标记（cmf_area_meta.data_version），供 update 迁移
 * 的 from_version 守卫判定（全新安装时历史 update 会因版本已越过而跳过）。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 测试环境跳过全量导入（4 万余行），测试用各自的 fixture csv 按需导入
        if (app()->runningUnitTests()) {
            return;
        }

        app(ImportService::class)->import(__DIR__.'/../data/ok_data_level4.csv');

        // 导入行为发生的瞬间，config 的 data_version 与 csv 必然一致（发布流程同步更新）
        app(AreaDataVersion::class)->mark((string) config('cmf-area.data_version'));
    }

    public function down(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        DB::table('cmf_areas')->truncate();

        if (Schema::hasTable(AreaDataVersion::TABLE)) {
            DB::table(AreaDataVersion::TABLE)->where('key', AreaDataVersion::KEY_DATA_VERSION)->delete();
        }
    }
};
