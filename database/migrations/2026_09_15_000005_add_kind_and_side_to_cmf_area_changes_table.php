<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * changes.json 语义规范化（v2）：cmf_area_changes 增加 kind / side 两列
 * （见 docs/changes-json-semantics.md §4.4）。
 *
 * 存量 v1 行回填策略：两列先 nullable，按 change_type 一次性回填
 * （rename/parent_change → node/old；add → node/new；abolish → node/old；
 * merge_into/split_from/code_change/code_reuse → edge/null）；
 * 回填完成前读取侧双读（kind 为空按 v1 语义展示），回填后新写入只走 v2。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cmf_area_changes', function (Blueprint $table): void {
            $table->string('kind', 10)->nullable()->after('version')->comment('v2 记录类别：node/edge（null=v1 存量行）');
            $table->string('side', 10)->nullable()->after('kind')->comment('node 记录的版本侧：old/new（edge 与 v1 存量行为 null）');
        });

        // 存量 v1 行一次性回填
        DB::table('cmf_area_changes')->whereNull('kind')
            ->whereIn('change_type', ['rename', 'parent_change'])
            ->update(['kind' => 'node', 'side' => 'old']);
        DB::table('cmf_area_changes')->whereNull('kind')
            ->where('change_type', 'add')
            ->update(['kind' => 'node', 'side' => 'new']);
        DB::table('cmf_area_changes')->whereNull('kind')
            ->where('change_type', 'abolish')
            ->update(['kind' => 'node', 'side' => 'old']);
        DB::table('cmf_area_changes')->whereNull('kind')
            ->whereIn('change_type', ['merge_into', 'split_from', 'code_change', 'code_reuse'])
            ->update(['kind' => 'edge', 'side' => null]);
    }

    public function down(): void
    {
        Schema::table('cmf_area_changes', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'side']);
        });
    }
};
