<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cmf_area_changes', function (Blueprint $table): void {
            $table->id();
            $table->string('version')->index()->comment('所属上游版本');
            // add新设 / split_from析出 / merge_into合并并入 / rename更名 /
            // abolish撤销（无承继）/ parent_change隶属变更 / code_change代码变更 / code_reuse代码重用
            $table->string('change_type', 20);
            $table->unsignedBigInteger('old_id')->nullable()->comment('变更前地区 ID（rename 时与 new_id 相同）');
            $table->unsignedBigInteger('new_id')->nullable()->comment('变更后地区 ID');
            $table->string('old_name')->nullable();
            $table->string('new_name')->nullable();
            // child_id_map 下级新旧 id 对照、renames、full_transfer、归档映射、各业务表受影响行数
            $table->jsonb('detail')->nullable();
            $table->string('evidence_url')->nullable()->comment('AI 判定的信息源链接');
            $table->string('evidence_title')->nullable()->comment('信息源标题');
            $table->text('ai_summary')->nullable()->comment('AI 判定理由摘要');
            $table->timestamp('applied_at')->nullable()->comment('迁移执行时间（null=未应用）');
            $table->timestamps();

            $table->index(['version', 'change_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cmf_area_changes');
    }
};
