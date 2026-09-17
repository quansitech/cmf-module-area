<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cmf_area_references', function (Blueprint $table): void {
            $table->id();
            $table->string('table_name')->comment('业务表名');
            $table->string('column_name')->comment('地区引用字段名');
            // keep 历史事实型（绝不动业务数据）/ remap 当前状态型（安全场景自动映射新 id）
            $table->string('merge_strategy')->default('keep')->comment('keep / remap');
            $table->string('snapshot_column')->nullable()->comment('名称快照列（如 receiver_area_name）');
            $table->string('description')->default('')->comment('用途说明');
            $table->timestamps();

            $table->unique(['table_name', 'column_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cmf_area_references');
    }
};
