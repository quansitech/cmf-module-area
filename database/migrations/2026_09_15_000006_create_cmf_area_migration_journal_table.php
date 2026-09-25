<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 执行期行级日志（journal）：apply 时每行写入前先捕获 before-image，
     * revert 按 id DESC 逆序回放，实现 A 级精确回滚（见 docs/area-precise-rollback-and-changes-v3.md §3）。
     * id 天然即 apply 执行序；回滚成功后该 version 的日志即删除（使命完成）。
     */
    public function up(): void
    {
        Schema::create('cmf_area_migration_journal', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('version')->index()->comment('迁移版本号（回滚定位 / 清理用）');
            // area=cmf_areas 整行；biz=业务列单值（行级）；biz_scan=无单主键列的值扫描回退标记
            $table->string('kind', 10);
            $table->string('table_name')->comment('biz 用；area 恒为 cmf_areas');
            $table->string('column_name')->nullable()->comment('biz 用；area 为 null');
            $table->string('pk')->nullable()->comment('行主键值（biz_scan 为 null）');
            // 改写前的值：biz=标量旧值；area=整行（含时间戳）；null=该行由 apply 新建（revert 删除）
            $table->jsonb('before')->nullable();
            $table->unsignedBigInteger('to_value')->nullable()->comment('biz 用：改写后的值（revert 守卫条件）');
            $table->timestamp('created_at')->nullable();

            $table->index(['version', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cmf_area_migration_journal');
    }
};
