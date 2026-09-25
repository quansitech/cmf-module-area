<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 登记表补充主键列声明：行级回滚日志按主键寻址（见 docs/area-precise-rollback-and-changes-v3.md §4）。
     * null = 执行期探测（单主键表自动识别，默认 id）；无单主键的表可在此显式声明。
     */
    public function up(): void
    {
        Schema::table('cmf_area_references', function (Blueprint $table): void {
            $table->string('pk_column')->nullable()->after('column_name')->comment('业务表主键列（行级回滚日志寻址用，默认 id）');
        });
    }

    public function down(): void
    {
        Schema::table('cmf_area_references', function (Blueprint $table): void {
            $table->dropColumn('pk_column');
        });
    }
};
