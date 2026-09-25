<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 区划模块元数据表（当前仅承载 data_version——当前库内区划数据的版本标记）。
 *
 * 文件名日期故意早于 000004 seed 迁移：保证全新安装时本表先于数据导入存在，
 * seed 导入完成后才能写入版本标记（见 seed 迁移与 AreaDataVersion）。
 *
 * 为什么不用 migrations 表承载：全新安装时所有迁移一次性入库，
 * migrations 表反映不出 seed 实际导入的是哪一版数据。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cmf_area_meta', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('value');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cmf_area_meta');
    }
};
