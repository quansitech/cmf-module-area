<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Quansitech\Cmf\Area\Services\ImportService;

/**
 * 初始数据导入：内置上游 2025.251231.260403 版四级行政区划（省市区乡镇）。
 * 数据版本履历由 migrations 表承载（文件名携带上游版本号，见开发方案 §3.2）。
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
    }

    public function down(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        DB::table('cmf_areas')->truncate();
    }
};
