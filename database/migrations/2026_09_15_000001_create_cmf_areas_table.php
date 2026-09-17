<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cmf_areas', function (Blueprint $table): void {
            // 上游短编号（如东莞 4419）；统一 int8 免后患（见开发方案 §3.5）
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('pid')->index()->comment('上级 ID');
            $table->tinyInteger('deep')->comment('0省 1市 2区 3镇（地区自身的层级属性）');
            $table->string('name')->comment('精简名（如"武汉"）');
            $table->string('pinyin_prefix')->default('')->comment('拼音前缀');
            $table->string('pinyin')->default('')->comment('拼音');
            // 上游 12 位长码（如 653223102000）超过 int4 上限，必须 int8
            $table->unsignedBigInteger('ext_id')->comment('数据源原始编号');
            $table->string('ext_name')->comment('完整名（如"武汉市"）');
            // 撤销不删行：历史业务数据仍以旧 id 引用，保证可回显、迁移可回滚
            $table->tinyInteger('status')->default(1)->index()->comment('1正常 0已撤销/停用');
            $table->unsignedBigInteger('successor_id')->nullable()->comment('撤销/合并后的主要承继地区 ID');
            $table->timestamps();

            $table->index(['pid', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cmf_areas');
    }
};
