<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Quansitech\Cmf\Area\Models\Area;
use Quansitech\Cmf\Area\Models\AreaChange;
use Quansitech\Cmf\Area\Models\AreaMigrationJournal;
use Quansitech\Cmf\Area\Models\AreaReference;
use RuntimeException;

/**
 * 迁移执行器：薄壳迁移文件的唯一一份执行/幂等/回滚逻辑（全项目共用）。
 *
 * apply() 在业务项目 migrate 时运行：
 *  1. 执行 areas 结构操作（insert / retire / rename / reparent / archive）；
 *  2. 读本项目引用登记表，按每列 merge_strategy 应用 mappings（延迟绑定）——
 *     mappings 是拍平的 {from, to} 对清单，payload 数组顺序即 §2.5 拓扑执行序，
 *     执行器不自行排序、不再有类型分支（merge 部分旁落/split 深浅层等判定
 *     在生成期已由 unit_mapping 消化，不可判定的对根本不在 mappings 里）；
 *  3. manual 项输出待人工清单；keep 列命中旧 id 的行输出信息性报告；
 *  4. records 写入 cmf_area_changes 并标 applied_at。
 *
 * A 级精确回滚（docs/area-precise-rollback-and-changes-v3.md §3）：
 *  apply 的每个写动作之前先把 before-image 落 cmf_area_migration_journal
 *  （结构行整行、业务行单值），revert 按日志 id DESC 逆序回放——
 *  业务行回放带守卫条件（WHERE pk=? AND col=to_value），升级后被业务改写的行
 *  跳过并进报告，不误伤、不漏判。payload 无日志标记的是旧格式迁移文件，
 *  revert 走 legacyRevert()（旧式值扫描）并输出警告。
 *
 * 跨环境确定性：不分析业务数据分布来决定行为，同一 payload 在任何环境
 * 执行的结构操作一致；业务表操作只取决于本项目引用登记表的显式声明。
 */
class MigrationExecutor
{
    /** 业务行日志分块大小（同事务分块，内存可控） */
    protected const BIZ_CHUNK = 1000;

    /** 回放期的主键探测缓存（"table.column" => 主键列|null），避免逐行重复探测 */
    protected array $pkColumnCache = [];

    /**
     * @param  array<string, mixed>  $payload
     * @return array{affected: array<string, int>, manual: list<array<string, mixed>>, info: list<string>}
     */
    public function apply(array $payload): array
    {
        $this->assertTablesExist();
        $this->assertJournalTableExists();

        // 数据版本守卫：payload 冻结的 from_version 与库内当前数据版本不一致时跳过——
        // 典型场景是全新安装：新基线已包含本次变更，重放历史 update 会让
        // archive（废止复用）等操作误删有效行。空标记（未接入守卫的老项目）放行。
        $fromVersion = $payload['from_version'] ?? null;
        $dataVersion = app(AreaDataVersion::class);
        if (is_string($fromVersion) && $fromVersion !== '' && ! $dataVersion->shouldApply($fromVersion)) {
            Log::info("区划升级 {$payload['version']} 跳过：库内数据版本（".$dataVersion->current()."）与迁移基线（{$fromVersion}）不一致，当前基线已包含本次变更");

            return ['affected' => [], 'manual' => [], 'info' => []];
        }

        return DB::transaction(function () use ($payload, $dataVersion): array {
            $version = (string) $payload['version'];

            $this->applyAreaOps($version, $payload['areas'] ?? []);

            [$affected, $info] = $this->applyMappings($version, $payload['mappings'] ?? []);

            $manual = $this->collectManualItems($payload);

            $this->writeRecords($payload, $affected);

            $this->report($payload, $manual, $info);

            $dataVersion->mark($version);

            return ['affected' => $affected, 'manual' => $manual, 'info' => $info];
        });
    }

    /**
     * 回滚入口：新格式 payload（生成器写入 journal=true 标记）走行级日志精确回放；
     * 旧格式 payload 走 legacyRevert()（旧式值扫描）并输出能力边界警告。
     *
     * @param  array<string, mixed>  $payload
     * @return array{stats: array<string, int>, skipped: list<array<string, mixed>>} 回放统计与跳过清单
     */
    public function revert(array $payload): array
    {
        $this->assertTablesExist();

        if (($payload['journal'] ?? false) === true) {
            return $this->revertByJournal($payload);
        }

        return $this->legacyRevert($payload);
    }

    /**
     * 执行 cmf_areas 结构操作（无条件应用，所有项目结果一致）。
     * 每个 op 执行前先把目标行现场写入 journal（不存在则 before=null）——
     * 链式复用多触点天然正确：同一行被多次改写则按序各记一条，逆序回放后回到原始行。
     *
     * @param  list<array<string, mixed>>  $ops
     */
    protected function applyAreaOps(string $version, array $ops): void
    {
        foreach ($ops as $op) {
            $opName = (string) ($op['op'] ?? '');
            if (! in_array($opName, ['insert', 'retire', 'rename', 'reparent', 'archive'], true)) {
                throw new RuntimeException('未知结构操作：'.$opName);
            }

            $this->journalAreaRow($version, (int) $op['id']);
            if ($opName === 'archive') {
                // 归档行（90{id}）一并记录：正常首次执行为 before=null（revert 删除），
                // 回放天然等价于"删归档行 + 恢复旧行"，无需特殊反向分支
                $this->journalAreaRow($version, (int) $op['archive_id']);
            }

            switch ($opName) {
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

    /**
     * 业务映射：读本项目引用登记表，按 payload 数组顺序逐对执行（§2.5 拓扑序
     * 由生成器写入 payload，执行器不自行排序）。每个 UPDATE 前先按 where(col, from)
     * 分块捕获命中行主键写入行级日志（同事务分块，内存可控；命中 0 行——幂等
     * 重跑——零日志），再执行改写。无单主键的登记列退回 biz_scan 日志（回滚时
     * 按旧式值扫描处理）并在报告中警告。
     *
     * @param  list<array<string, mixed>>  $mappings
     * @return array{array<string, int>, list<string>}
     */
    protected function applyMappings(string $version, array $mappings): array
    {
        $affected = [];
        $info = [];
        $refs = AreaReference::query()->get();

        foreach ($mappings as $mapping) {
            $from = (int) $mapping['from'];
            $to = (int) $mapping['to'];
            $isArchive = (bool) ($mapping['archive'] ?? false);

            foreach ($refs as $ref) {
                // archive 映射对 keep 列也执行（归档不改变显示语义）；
                // 其余映射仅 remap 列自动执行，keep 列输出信息性报告
                if (! $isArchive && ! $ref->isRemap()) {
                    $info = array_merge($info, $this->keepColumnReport($ref, [[$from, $to]]));

                    continue;
                }

                $key = "{$ref->table_name}.{$ref->column_name}";
                $pkColumn = $this->resolvePkColumn($ref);

                if ($pkColumn === null) {
                    // 无单主键：不支持行级日志，留 biz_scan 标记供回滚退化为值扫描
                    $count = DB::table($ref->table_name)
                        ->where($ref->column_name, $from)
                        ->update([$ref->column_name => $to]);
                    if ($count > 0) {
                        $this->journal($version, AreaMigrationJournal::KIND_BIZ_SCAN, $ref->table_name, $ref->column_name, null, $from, $to);
                        $affected[$key] = ($affected[$key] ?? 0) + $count;
                        $info[] = "[警告] {$key} 未探测到单一主键，{$count} 行改写未记录行级日志，回滚时该列按旧式值扫描处理（可在登记时声明 pk_column）";
                    }

                    continue;
                }

                // 行级日志：先捕获命中行（where 条件即幂等保证，命中 0 行零日志）
                DB::table($ref->table_name)
                    ->select($pkColumn)
                    ->where($ref->column_name, $from)
                    ->orderBy($pkColumn)
                    ->chunk(self::BIZ_CHUNK, function ($rows) use ($version, $ref, $pkColumn, $from, $to): void {
                        $journalRows = [];
                        foreach ($rows as $row) {
                            $journalRows[] = $this->journalRow(
                                $version, AreaMigrationJournal::KIND_BIZ,
                                $ref->table_name, $ref->column_name,
                                (string) $row->{$pkColumn}, $from, $to,
                            );
                        }
                        DB::table((new AreaMigrationJournal)->getTable())->insert($journalRows);
                    });

                $count = DB::table($ref->table_name)
                    ->where($ref->column_name, $from)
                    ->update([$ref->column_name => $to]);

                if ($count > 0) {
                    $affected[$key] = ($affected[$key] ?? 0) + $count;
                }
            }
        }

        return [$affected, $info];
    }

    /**
     * A 级精确回滚：按 journal id DESC 逆序回放该 version 的全部写入。
     *  - area 行：before=null → 删除该行；否则整行恢复（原样写回时间戳）；
     *  - biz 行：守卫条件回放（UPDATE ... WHERE pk=? AND col=to_value），
     *    当前值已被业务改写过的行跳过并记入跳过清单；
     *  - biz_scan 行：无单主键列的值扫描回退（登记表未声明主键的存量列）。
     * 回放完成后履历 applied_at 置空（记录保留供审计），并删除该 version 的日志。
     *
     * @param  array<string, mixed>  $payload
     * @return array{stats: array<string, int>, skipped: list<array<string, mixed>>}
     */
    protected function revertByJournal(array $payload): array
    {
        $this->assertJournalTableExists();
        $version = (string) $payload['version'];

        return DB::transaction(function () use ($version): array {
            $hasJournal = AreaMigrationJournal::query()->where('version', $version)->exists();

            if (! $hasJournal) {
                // 新格式 payload 无日志：若变更履历显示已应用，说明日志被清理或已回滚——
                // 拒绝回滚并明确报错（不退回盲扫，盲扫正是要消灭的东西）；
                // 未应用过（或空变更）则按空回放处理（幂等 no-op）
                $applied = AreaChange::query()
                    ->where('version', $version)
                    ->whereNotNull('applied_at')
                    ->exists();
                if ($applied) {
                    throw new RuntimeException(
                        "版本 {$version} 已应用，但回滚日志（cmf_area_migration_journal）不存在——"
                        .'拒绝回滚（日志可能已被 area:cleanup-journal 清理，或此前已回滚）。'
                        .'请从数据库备份（PITR）恢复，或人工处理后自行将 cmf_area_changes.applied_at 置空。'
                    );
                }
            }

            $stats = ['area_restored' => 0, 'area_deleted' => 0, 'biz_restored' => 0, 'biz_scanned' => 0];
            $skipped = [];

            AreaMigrationJournal::query()
                ->where('version', $version)
                ->orderByDesc('id')
                ->chunk(self::BIZ_CHUNK, function ($entries) use (&$stats, &$skipped): void {
                    foreach ($entries as $entry) {
                        $this->replayJournalEntry($entry, $stats, $skipped);
                    }
                });

            // 变更履历标记未应用（保留记录供审计）
            AreaChange::query()->where('version', $version)->update(['applied_at' => null]);

            // 使命完成：删除该 version 的日志行（重新 migrate 会重新捕获）
            AreaMigrationJournal::query()->where('version', $version)->delete();

            $this->revertReport($version, $stats, $skipped);

            return ['stats' => $stats, 'skipped' => $skipped];
        });
    }

    /**
     * 回放单条日志（在 revert 事务内被 id DESC 逐条调用）。
     *
     * @param  array<string, int>  $stats
     * @param  list<array<string, mixed>>  $skipped
     */
    protected function replayJournalEntry(AreaMigrationJournal $entry, array &$stats, array &$skipped): void
    {
        if ($entry->kind === AreaMigrationJournal::KIND_AREA) {
            if ($entry->before === null) {
                // 该行由 apply 新建 → 删除
                DB::table('cmf_areas')->where('id', $entry->pk)->delete();
                $stats['area_deleted']++;
            } else {
                // 整行恢复（原样写回时间戳）；行在（如 retire/rename）或不在（archive/复用覆盖）均可
                /** @var array<string, mixed> $row */
                $row = $entry->before;
                DB::table('cmf_areas')->updateOrInsert(['id' => (int) ($row['id'] ?? $entry->pk)], $row);
                $stats['area_restored']++;
            }

            return;
        }

        if ($entry->kind === AreaMigrationJournal::KIND_BIZ_SCAN) {
            // 无单主键列：退化为旧式值扫描（该列在 apply 报告中已警告）
            $stats['biz_scanned'] += DB::table($entry->table_name)
                ->where($entry->column_name, $entry->to_value)
                ->update([$entry->column_name => $entry->before]);

            return;
        }

        // biz 行级：守卫条件回放——只还原至今仍呈现迁移写入结果的行，
        // 升级后被业务改写的行跳过并记入跳过清单（不覆盖业务新数据）
        $pkColumn = $this->cachedPkColumnOf($entry->table_name, $entry->column_name);

        $affected = $pkColumn === null ? 0 : DB::table($entry->table_name)
            ->where($pkColumn, $entry->pk)
            ->where($entry->column_name, $entry->to_value)
            ->update([$entry->column_name => $entry->before]);

        if ($affected > 0) {
            $stats['biz_restored'] += $affected;

            return;
        }

        $current = $pkColumn === null
            ? null
            : DB::table($entry->table_name)->where($pkColumn, $entry->pk)->value($entry->column_name);

        $skipped[] = [
            'table' => $entry->table_name,
            'column' => $entry->column_name,
            'pk' => $entry->pk,
            'expected' => $entry->to_value,
            'current' => $current,
            'restore' => $entry->before,
        ];
    }

    /**
     * 旧格式 payload（无日志标记）的回滚：现行值扫描逻辑原样保留，并输出能力边界
     * 警告（rename/reparent/链式复用不在自动回滚范围）。apply 若已由新执行器写过
     * 日志，此处一并清理（盲扫已执行，日志现场不再可用）。
     *
     * @param  array<string, mixed>  $payload
     * @return array{stats: array<string, int>, skipped: list<array<string, mixed>>}
     */
    protected function legacyRevert(array $payload): array
    {
        DB::transaction(function () use ($payload): void {
            // 业务数据先反向：每对把 to 改回 from（严格按 apply 执行序的逆序）
            /** @var array<string, mixed> $mapping */
            foreach (array_reverse($payload['mappings'] ?? []) as $mapping) {
                $from = (int) $mapping['from'];
                $to = (int) $mapping['to'];
                $isArchive = (bool) ($mapping['archive'] ?? false);

                // apply 时 archive 对所有列执行、普通对仅 remap 列执行，回滚严格镜像
                $refs = $isArchive
                    ? AreaReference::query()->get()
                    : $this->remapColumns();

                foreach ($refs as $ref) {
                    DB::table($ref->table_name)
                        ->where($ref->column_name, $to)
                        ->update([$ref->column_name => $from]);
                }
            }

            // 结构操作反向（逆序）
            /** @var array<string, mixed> $op */
            foreach (array_reverse($payload['areas'] ?? []) as $op) {
                match ($op['op']) {
                    'insert' => Area::query()->where('id', $op['id'])->delete(),
                    'retire' => Area::query()->where('id', $op['id'])->update(['status' => 1, 'successor_id' => null]),
                    'rename' => null, // 旧格式无旧名称快照则不反转名称；records 里留有旧值可供人工核对
                    'reparent' => null, // 旧格式 pid 旧值未冻结在 payload，回滚由 changes.json 重新生成反向迁移处理
                    'archive' => $this->revertArchive((int) $op['id'], (int) $op['archive_id']),
                    default => throw new RuntimeException('未知结构操作：'.$op['op']),
                };
            }

            // 变更履历标记未应用（保留记录供审计）
            AreaChange::query()
                ->where('version', $payload['version'])
                ->update(['applied_at' => null]);

            if (DB::getSchemaBuilder()->hasTable((new AreaMigrationJournal)->getTable())) {
                AreaMigrationJournal::query()->where('version', (string) $payload['version'])->delete();
            }
        });

        $message = "区划升级 {$payload['version']} 回滚使用了旧格式盲扫逻辑（payload 无日志标记）："
            .'rename/reparent/链式复用不在自动回滚范围，请人工核对 cmf_area_changes；'
            .'重新生成迁移文件（生成物可再生）可获得精确回滚能力。';
        Log::warning($message);

        return ['stats' => [], 'skipped' => []];
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
     * 待人工清单：完全由变更的不可判定性驱动（unit_mapping 不成立的单位级对、
     * 析出新设浅层值、code_reuse、无承继 abolish），对所有注册列生效。
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
     * v2 主键：(version, kind, side, old_id, new_id)——edge 记录的
     * old_id/new_id 列存 from/to；node 记录按 side 存一侧。
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
                    'kind' => $record['kind'],
                    'side' => $record['side'] ?? null,
                    'old_id' => $record['old_id'],
                    'new_id' => $record['new_id'],
                ],
                [
                    'change_type' => $record['change_type'],
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
     * 待人工清单与 keep 信息性报告输出到迁移日志（Log + 报告文件）；
     * 末尾附回滚日志清理提示（观察窗口确认无回滚需求后执行 area:cleanup-journal）。
     *
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $manual
     * @param  list<string>  $info
     */
    protected function report(array $payload, array $manual, array $info): void
    {
        $version = (string) $payload['version'];
        $lines = ["区划升级 {$version} 执行报告："];

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

        $journalCount = AreaMigrationJournal::query()->where('version', $version)->count();
        if ($journalCount > 0) {
            $lines[] = "[提示] 回滚现场已写入 cmf_area_migration_journal（本版本 {$journalCount} 行）；"
                ."观察窗口确认无需回滚后执行：php artisan area:cleanup-journal {$version}";
        }

        $report = implode("\n", $lines);

        Log::info($report);

        // 同步落一份报告文件，便于迁移后逐条处理
        $path = storage_path('logs/area-migration-'.str_replace('.', '_', $version).'.log');
        file_put_contents($path, $report."\n");
    }

    /**
     * 回滚报告：回放统计 + 守卫跳过清单（与 apply 的 report 对齐，Log + 报告文件）。
     *
     * @param  array<string, int>  $stats
     * @param  list<array<string, mixed>>  $skipped
     */
    protected function revertReport(string $version, array $stats, array $skipped): void
    {
        $lines = ["区划升级 {$version} 回滚报告："];
        $lines[] = '回放完成：'
            ."结构恢复 {$stats['area_restored']} 行、结构删除 {$stats['area_deleted']} 行、"
            ."业务还原 {$stats['biz_restored']} 行、值扫描回退 {$stats['biz_scanned']} 行、"
            .'守卫跳过 '.count($skipped).' 行';

        foreach ($skipped as $item) {
            $lines[] = "[跳过] {$item['table']}.{$item['column']} pk={$item['pk']}："
                ."升级后当前值已变化（迁移写入 {$item['expected']}，现值 ".var_export($item['current'], true).'），'
                ."保留业务新数据，未还原为 {$item['restore']}";
        }

        $report = implode("\n", $lines);

        Log::info($report);

        $path = storage_path('logs/area-migration-'.str_replace('.', '_', $version).'-rollback.log');
        file_put_contents($path, $report."\n");
    }

    /**
     * 捕获 cmf_areas 目标行的 before-image（整行含时间戳；不存在则 before=null）。
     */
    protected function journalAreaRow(string $version, int $id): void
    {
        $row = DB::table('cmf_areas')->where('id', $id)->first();

        $this->journal($version, AreaMigrationJournal::KIND_AREA, 'cmf_areas', null, (string) $id, $row === null ? null : (array) $row, null);
    }

    /**
     * 写一条回滚日志。
     *
     * @param  array<string, mixed>|int|null  $before
     */
    protected function journal(string $version, string $kind, string $table, ?string $column, ?string $pk, array|int|null $before, ?int $toValue): void
    {
        DB::table((new AreaMigrationJournal)->getTable())->insert(
            $this->journalRow($version, $kind, $table, $column, $pk, $before, $toValue)
        );
    }

    /**
     * 组装一行日志（before 以 json 存储；biz 的标量旧值即 from——where 条件保证当前值==from）。
     *
     * @param  array<string, mixed>|int|null  $before
     * @return array<string, mixed>
     */
    protected function journalRow(string $version, string $kind, string $table, ?string $column, ?string $pk, array|int|null $before, ?int $toValue): array
    {
        return [
            'version' => $version,
            'kind' => $kind,
            'table_name' => $table,
            'column_name' => $column,
            'pk' => $pk,
            'before' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            'to_value' => $toValue,
            'created_at' => now(),
        ];
    }

    /**
     * 回放期的登记列主键探测（带实例级缓存）：同一 (table, column) 在一次回放中
     * 会被逐行命中数千次，登记表查询与索引 introspection 只应做一次。
     */
    protected function cachedPkColumnOf(string $table, string $column): ?string
    {
        $key = $table.'.'.$column;

        if (! array_key_exists($key, $this->pkColumnCache)) {
            $this->pkColumnCache[$key] = $this->primaryKeyOf($table, $this->declaredPkColumnOf($table, $column));
        }

        return $this->pkColumnCache[$key];
    }

    /**
     * 登记列的行级日志主键：登记声明优先，否则探测单主键，最后默认 id；
     * 三者皆无返回 null（该列退回 biz_scan 值扫描）。
     */
    protected function resolvePkColumn(AreaReference $ref): ?string
    {
        return $this->primaryKeyOf($ref->table_name, $ref->pk_column ?: null);
    }

    /**
     * 读登记表里该列的主键声明（回滚时登记表可能已有时间漂移，探测不到按默认规则兜底）。
     */
    protected function declaredPkColumnOf(string $table, string $column): ?string
    {
        $declared = AreaReference::query()
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->value('pk_column');

        return $declared === null ? null : (string) $declared;
    }

    /**
     * 探测表的单主键列：声明 > 单列主键索引 > id 列兜底；都没有返回 null。
     */
    protected function primaryKeyOf(string $table, ?string $declared): ?string
    {
        if ($declared !== null && $declared !== '') {
            return $declared;
        }

        try {
            foreach (DB::getSchemaBuilder()->getIndexes($table) as $index) {
                if (($index['primary'] ?? false) && count($index['columns'] ?? []) === 1) {
                    return (string) $index['columns'][0];
                }
            }
        } catch (\Throwable) {
            // 驱动不支持索引探测时走 id 兜底
        }

        return DB::getSchemaBuilder()->hasColumn($table, 'id') ? 'id' : null;
    }

    /**
     * remap 列清单（旧格式回滚用）。
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

    protected function assertJournalTableExists(): void
    {
        $table = (new AreaMigrationJournal)->getTable();
        if (! DB::getSchemaBuilder()->hasTable($table)) {
            throw new RuntimeException("数据表 {$table} 不存在，请先执行模块基础迁移（行级回滚日志）");
        }
    }
}
