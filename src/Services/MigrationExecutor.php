<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Quansitech\Cmf\Area\Models\Area;
use Quansitech\Cmf\Area\Models\AreaChange;
use Quansitech\Cmf\Area\Models\AreaReference;
use RuntimeException;

/**
 * 迁移执行器：薄壳迁移文件的唯一一份执行/幂等/回滚逻辑（全项目共用）。
 *
 * apply() 在业务项目 migrate 时运行：
 *  1. 执行 areas 结构操作（insert / retire / rename / reparent / archive）；
 *  2. 读本项目引用登记表，按每列 merge_strategy 应用 mappings（延迟绑定）；
 *  3. manual 项输出待人工清单；keep 列命中旧 id 的行输出信息性报告；
 *  4. records 写入 cmf_area_changes 并标 applied_at。
 *
 * 跨环境确定性：不分析业务数据分布来决定行为，同一 payload 在任何环境
 * 执行的结构操作一致；业务表操作只取决于本项目引用登记表的显式声明。
 */
class MigrationExecutor
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{affected: array<string, int>, manual: list<array<string, mixed>>, info: list<string>}
     */
    public function apply(array $payload): array
    {
        $this->assertTablesExist();

        return DB::transaction(function () use ($payload): array {
            $this->applyAreaOps($payload['areas'] ?? []);

            [$affected, $info] = $this->applyMappings($payload['mappings'] ?? []);

            $manual = $this->collectManualItems($payload);

            $this->writeRecords($payload, $affected);

            $this->report($payload, $manual, $info);

            return ['affected' => $affected, 'manual' => $manual, 'info' => $info];
        });
    }

    /**
     * 基于内嵌映射数据反向执行（merge_into 反向 = new→old）。
     * 结构操作可逆回滚；业务数据按各列 merge_strategy 反向改写（keep 列本未动，无需回滚）。
     *
     * @param  array<string, mixed>  $payload
     */
    public function revert(array $payload): void
    {
        $this->assertTablesExist();

        DB::transaction(function () use ($payload): void {
            // 业务数据先反向：remap 列把 new 改回 old（archive 映射同样反向）
            /** @var array<string, mixed> $mapping */
            foreach ($payload['mappings'] ?? [] as $mapping) {
                $old = (int) $mapping['old'];
                $new = (int) $mapping['new'];
                $isArchive = (bool) ($mapping['archive'] ?? false);
                $fullTransfer = (bool) ($mapping['full_transfer'] ?? true);

                // 与 apply 相同的自动执行门槛：部分疆域旁落不自动处理；
                // split 只回滚 child_id_map 命中的深层值（浅层值本未自动执行）
                if (! $isArchive && $mapping['type'] === AreaChange::TYPE_MERGE_INTO && ! $fullTransfer) {
                    continue;
                }

                foreach ($this->remapColumns() as $ref) {
                    $pairs = [];
                    if ($isArchive || $mapping['type'] !== AreaChange::TYPE_SPLIT_FROM) {
                        $pairs[] = [$new, $old];
                    }
                    /** @var array<int|string, int|string> $childMap */
                    $childMap = $mapping['child_id_map'] ?? [];
                    foreach ($childMap as $oldChild => $newChild) {
                        $pairs[] = [(int) $newChild, (int) $oldChild];
                    }

                    foreach ($pairs as [$from, $to]) {
                        DB::table($ref->table_name)
                            ->where($ref->column_name, $from)
                            ->update([$ref->column_name => $to]);
                    }
                }
            }

            // 结构操作反向（逆序）
            /** @var array<string, mixed> $op */
            foreach (array_reverse($payload['areas'] ?? []) as $op) {
                match ($op['op']) {
                    'insert' => Area::query()->where('id', $op['id'])->delete(),
                    'retire' => Area::query()->where('id', $op['id'])->update(['status' => 1, 'successor_id' => null]),
                    'rename' => null, // 无旧名称快照则不反转名称；records 里留有 old_name 可供人工核对
                    'reparent' => null, // pid 旧值未冻结在 payload，回滚由 changes.json 重新生成反向迁移处理
                    'archive' => $this->revertArchive((int) $op['id'], (int) $op['archive_id']),
                    default => throw new RuntimeException('未知结构操作：'.$op['op']),
                };
            }

            // 变更履历标记未应用（保留记录供审计）
            AreaChange::query()
                ->where('version', $payload['version'])
                ->update(['applied_at' => null]);
        });
    }

    /**
     * 执行 cmf_areas 结构操作（无条件应用，所有项目结果一致）。
     *
     * @param  list<array<string, mixed>>  $ops
     */
    protected function applyAreaOps(array $ops): void
    {
        foreach ($ops as $op) {
            switch ($op['op']) {
                case 'insert':
                    Area::query()->updateOrCreate(
                        ['id' => $op['id']],
                        [
                            'pid' => $op['pid'],
                            'deep' => $op['deep'],
                            'name' => $op['name'],
                            'pinyin_prefix' => $op['pinyin_prefix'] ?? '',
                            'pinyin' => $op['pinyin'] ?? '',
                            'ext_id' => $op['ext_id'],
                            'ext_name' => $op['ext_name'],
                            'status' => 1,
                            'successor_id' => null,
                        ],
                    );
                    break;

                case 'retire':
                    Area::query()->where('id', $op['id'])->update([
                        'status' => 0,
                        'successor_id' => $op['successor_id'],
                    ]);
                    break;

                case 'rename':
                    Area::query()->where('id', $op['id'])->update([
                        'name' => $op['name'],
                        'ext_name' => $op['ext_name'],
                        'pinyin_prefix' => $op['pinyin_prefix'] ?? '',
                        'pinyin' => $op['pinyin'] ?? '',
                    ]);
                    break;

                case 'reparent':
                    Area::query()->where('id', $op['id'])->update(['pid' => $op['pid']]);
                    break;

                case 'archive':
                    $this->applyArchive((int) $op['id'], (int) $op['archive_id']);
                    break;

                default:
                    throw new RuntimeException('未知结构操作：'.$op['op']);
            }
        }
    }

    /**
     * 代码重用归档：旧行主键迁至归档 id 段（90{原id}），ext_name 保留不变。
     * 显示结果不变，语义不断链；新单位正常使用官方代码。
     */
    protected function applyArchive(int $id, int $archiveId): void
    {
        /** @var Area|null $old */
        $old = Area::query()->find($id);
        if ($old === null) {
            return;
        }

        // 幂等：归档行已存在则跳过重建
        if (! Area::query()->whereKey($archiveId)->exists()) {
            $archive = $old->replicate();
            $archive->id = $archiveId;
            $archive->status = 0;
            $archive->successor_id = $id; // 归档行指向新单位，便于追溯
            $archive->save();
        }

        $old->delete();
    }

    protected function revertArchive(int $id, int $archiveId): void
    {
        /** @var Area|null $archive */
        $archive = Area::query()->find($archiveId);
        if ($archive === null) {
            return;
        }

        if (! Area::query()->whereKey($id)->exists()) {
            $restored = $archive->replicate();
            $restored->id = $id;
            $restored->successor_id = null;
            $restored->save();
        }

        $archive->delete();
    }

    /**
     * 业务映射：读本项目引用登记表，按每列 merge_strategy 应用（延迟绑定）。
     * 每个 UPDATE 前先校验当前值是 old_id（where 条件即幂等保证）。
     *
     * @param  list<array<string, mixed>>  $mappings
     * @return array{array<string, int>, list<string>}
     */
    protected function applyMappings(array $mappings): array
    {
        $affected = [];
        $info = [];

        foreach ($mappings as $mapping) {
            $old = (int) $mapping['old'];
            $new = (int) $mapping['new'];
            $type = (string) $mapping['type'];
            $isArchive = (bool) ($mapping['archive'] ?? false);
            $fullTransfer = (bool) ($mapping['full_transfer'] ?? true);

            // 部分疆域旁落：一律进人工清单，任何列不自动执行
            if (! $isArchive && $type === AreaChange::TYPE_MERGE_INTO && ! $fullTransfer) {
                continue;
            }

            // split：浅层值（old→new 本身）不可判定，进人工清单；
            // 深层值命中 child_id_map 的行可判定归属，自动改写
            $pairs = [];
            if ($isArchive || $type !== AreaChange::TYPE_SPLIT_FROM) {
                $pairs[] = [$old, $new];
            }
            /** @var array<int|string, int|string> $childMap */
            $childMap = $mapping['child_id_map'] ?? [];
            foreach ($childMap as $oldChild => $newChild) {
                $pairs[] = [(int) $oldChild, (int) $newChild];
            }

            foreach (AreaReference::query()->get() as $ref) {
                // archive 映射对 keep 列也执行（归档不改变显示语义）；
                // 其余映射仅 remap 列自动执行，keep 列输出信息性报告
                if (! $isArchive && ! $ref->isRemap()) {
                    $info = array_merge($info, $this->keepColumnReport($ref, $pairs));

                    continue;
                }

                foreach ($pairs as [$from, $to]) {
                    $count = DB::table($ref->table_name)
                        ->where($ref->column_name, $from)
                        ->update([$ref->column_name => $to]);

                    if ($count > 0) {
                        $key = "{$ref->table_name}.{$ref->column_name}";
                        $affected[$key] = ($affected[$key] ?? 0) + $count;
                    }
                }
            }
        }

        return [$affected, $info];
    }

    /**
     * keep 列命中旧 id 的行：输出信息性报告（不动作）。
     *
     * @param  list<array{int, int}>  $pairs
     * @return list<string>
     */
    protected function keepColumnReport(AreaReference $ref, array $pairs): array
    {
        $messages = [];

        foreach ($pairs as [$from, $to]) {
            $count = DB::table($ref->table_name)->where($ref->column_name, $from)->count();
            if ($count > 0) {
                $messages[] = "[keep] {$ref->table_name}.{$ref->column_name} 有 {$count} 行仍引用旧 id {$from}"
                    ."（建议新 id {$to}）；keep 策略不自动改写，业务方可按需自行改库或写脚本批处理";
            }
        }

        return $messages;
    }

    /**
     * 待人工清单：完全由变更的不可判定性驱动（split 浅层值、merge 部分疆域
     * 旁落、code_reuse、无承继 abolish），对所有注册列生效。
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    protected function collectManualItems(array $payload): array
    {
        $items = [];

        /** @var array<string, mixed> $item */
        foreach ($payload['manual'] ?? [] as $item) {
            $columns = [];

            foreach (AreaReference::query()->get() as $ref) {
                $count = isset($item['old_id'])
                    ? DB::table($ref->table_name)->where($ref->column_name, $item['old_id'])->count()
                    : 0;

                if ($count > 0) {
                    $columns[] = "{$ref->table_name}.{$ref->column_name}（{$count} 行）";
                }
            }

            $items[] = $item + ['affected_columns' => $columns];
        }

        return $items;
    }

    /**
     * 变更档案写入 cmf_area_changes 并标 applied_at；受影响行数并入 detail。
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, int>  $affected
     */
    protected function writeRecords(array $payload, array $affected): void
    {
        /** @var array<string, mixed> $record */
        foreach ($payload['records'] ?? [] as $record) {
            $detail = $record['detail'] ?? [];
            $detail['affected_rows'] = $affected;

            AreaChange::query()->updateOrCreate(
                [
                    'version' => $payload['version'],
                    'change_type' => $record['change_type'],
                    'old_id' => $record['old_id'],
                    'new_id' => $record['new_id'],
                ],
                [
                    'old_name' => $record['old_name'],
                    'new_name' => $record['new_name'],
                    'detail' => $detail,
                    'evidence_url' => $record['evidence_url'],
                    'evidence_title' => $record['evidence_title'],
                    'ai_summary' => $record['ai_summary'],
                    'applied_at' => now(),
                ],
            );
        }
    }

    /**
     * 待人工清单与 keep 信息性报告输出到迁移日志（Log + 报告文件）。
     *
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $manual
     * @param  list<string>  $info
     */
    protected function report(array $payload, array $manual, array $info): void
    {
        $lines = ["区划升级 {$payload['version']} 执行报告："];

        foreach ($manual as $item) {
            $columns = implode('、', $item['affected_columns'] ?? []) ?: '（本项目无命中数据）';
            $lines[] = "[待人工] {$item['type']}/{$item['reason']}：旧 {$item['old_id']} → 新 ".($item['new_id'] ?? '无')
                ."；{$item['hint']}；命中列：{$columns}";
        }

        foreach ($info as $message) {
            $lines[] = "[信息] {$message}";
        }

        if (count($lines) === 1) {
            $lines[] = '无待人工事项。';
        }

        $report = implode("\n", $lines);

        Log::info($report);

        // 同步落一份报告文件，便于迁移后逐条处理
        $path = storage_path('logs/area-migration-'.str_replace('.', '_', (string) $payload['version']).'.log');
        file_put_contents($path, $report."\n");
    }

    /**
     * remap 列清单（回滚用）。
     *
     * @return \Illuminate\Support\Collection<int, AreaReference>
     */
    protected function remapColumns(): \Illuminate\Support\Collection
    {
        return AreaReference::query()->where('merge_strategy', AreaReference::STRATEGY_REMAP)->get();
    }

    protected function assertTablesExist(): void
    {
        foreach (['cmf_areas', AreaReference::tableName(), 'cmf_area_changes'] as $table) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                throw new RuntimeException("数据表 {$table} 不存在，请先执行模块基础迁移");
            }
        }
    }
}
