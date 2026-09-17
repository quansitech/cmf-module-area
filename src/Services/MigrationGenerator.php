<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Services;

use Quansitech\Cmf\Area\Models\AreaChange;
use RuntimeException;

/**
 * changes.json → 迁移文件（确定性翻译器，见开发方案 §10）。
 *
 * 生成的是"薄壳迁移文件"：文件里只有冻结的 payload 数据（变更事实与映射、
 * 版本号），执行逻辑全部在模块内置的 MigrationExecutor。业务表名延迟绑定，
 * 执行期才从引用登记表读取。
 */
class MigrationGenerator
{
    /**
     * changes.json → payload（展开为结构操作与映射指令并冻结）。
     *
     * @param  array<string, mixed>  $changes  已通过 check-changes 校验的 changes.json
     * @param  array<int, array<string, mixed>>  $oldMap  旧基线 csv
     * @param  array<int, array<string, mixed>>  $newMap  新版 csv
     * @return array<string, mixed>
     */
    public function payload(array $changes, array $oldMap, array $newMap): array
    {
        $areas = [];
        $mappings = [];
        $manual = [];
        $records = [];

        /** @var array<string, mixed> $change */
        foreach ($changes['changes'] as $change) {
            $type = (string) $change['change_type'];
            $oldId = isset($change['old_id']) ? (int) $change['old_id'] : null;
            $newId = isset($change['new_id']) ? (int) $change['new_id'] : null;
            $detail = $change['detail'] ?? [];
            $childIdMap = $detail['child_id_map'] ?? [];
            $fullTransfer = (bool) ($detail['full_transfer'] ?? true);

            switch ($type) {
                case AreaChange::TYPE_RENAME:
                    // id 不变，仅更新名称
                    $areas[] = [
                        'op' => 'rename',
                        'id' => $newId,
                        'name' => $newMap[$newId]['name'] ?? $change['new_name'],
                        'ext_name' => $newMap[$newId]['ext_name'] ?? $change['new_name'],
                        'pinyin_prefix' => $newMap[$newId]['pinyin_prefix'] ?? '',
                        'pinyin' => $newMap[$newId]['pinyin'] ?? '',
                    ];
                    break;

                case AreaChange::TYPE_ADD:
                    $areas[] = $this->insertOp($newId, $newMap);
                    break;

                case AreaChange::TYPE_SPLIT_FROM:
                    // 新单位整族插入（含其下级）；业务侧仅 child_id_map 命中的行自动改写，
                    // 等于旧单位本身的浅层值进人工清单
                    foreach ($this->familyOf($newId, $newMap) as $id) {
                        $areas[] = $this->insertOp($id, $newMap);
                    }
                    $mappings[] = [
                        'type' => $type,
                        'old' => $oldId,
                        'new' => $newId,
                        'full_transfer' => false,
                        'child_id_map' => $this->normalizeIdMap($childIdMap),
                    ];
                    $manual[] = [
                        'type' => $type,
                        'reason' => 'split_shallow_value',
                        'old_id' => $oldId,
                        'new_id' => $newId,
                        'hint' => '析出新设：值等于被析出旧单位本身的行，数据层面无法判定归属，需人工确认',
                    ];
                    break;

                case AreaChange::TYPE_MERGE_INTO:
                    $areas[] = ['op' => 'retire', 'id' => $oldId, 'successor_id' => $newId];
                    foreach ($this->mergedOutChildren($oldId, $childIdMap) as $retiredChildId) {
                        $areas[] = ['op' => 'retire', 'id' => (int) $retiredChildId, 'successor_id' => $newId];
                    }

                    $mappings[] = [
                        'type' => $type,
                        'old' => $oldId,
                        'new' => $newId,
                        'full_transfer' => $fullTransfer,
                        'child_id_map' => $this->normalizeIdMap($childIdMap),
                    ];

                    if (! $fullTransfer) {
                        $manual[] = [
                            'type' => $type,
                            'reason' => 'partial_transfer',
                            'old_id' => $oldId,
                            'new_id' => $newId,
                            'hint' => '部分疆域旁落第三方，只存上级 id 的行分不清是否在被划走的下级里，一律人工处理',
                        ];
                    }
                    break;

                case AreaChange::TYPE_ABOLISH:
                    $areas[] = ['op' => 'retire', 'id' => $oldId, 'successor_id' => null];
                    $manual[] = [
                        'type' => $type,
                        'reason' => 'abolish_no_successor',
                        'old_id' => $oldId,
                        'new_id' => null,
                        'hint' => '撤销且无承继：不自动改业务数据，进人工清单',
                    ];
                    break;

                case AreaChange::TYPE_PARENT_CHANGE:
                    $areas[] = ['op' => 'reparent', 'id' => $newId ?? $oldId, 'pid' => (int) ($newMap[$newId ?? $oldId]['pid'] ?? 0)];
                    break;

                case AreaChange::TYPE_CODE_CHANGE:
                    // 旧码整族退休，新码整族插入；remap 列批量 UPDATE
                    foreach ($this->familyOf($newId, $newMap) as $id) {
                        $areas[] = $this->insertOp($id, $newMap);
                    }
                    $areas[] = ['op' => 'retire', 'id' => $oldId, 'successor_id' => $newId];
                    foreach ($this->mergedOutChildren($oldId, $childIdMap) as $retiredChildId) {
                        $areas[] = ['op' => 'retire', 'id' => (int) $retiredChildId, 'successor_id' => $newId];
                    }

                    $mappings[] = [
                        'type' => $type,
                        'old' => $oldId,
                        'new' => $newId,
                        'full_transfer' => $fullTransfer,
                        'child_id_map' => $this->normalizeIdMap($childIdMap),
                    ];
                    break;

                case AreaChange::TYPE_CODE_REUSE:
                    // 归档迁移专项：旧行主键迁至归档 id 段（90{原id}），ext_name 保留，
                    // keep 策略的业务引用一并指向归档 id（显示结果不变，语义不断链）
                    $archiveId = $this->archiveIdOf($oldId);
                    $areas[] = ['op' => 'archive', 'id' => $oldId, 'archive_id' => $archiveId];
                    $areas[] = $this->insertOp($newId, $newMap);
                    $mappings[] = [
                        'type' => $type,
                        'old' => $oldId,
                        'new' => $archiveId,
                        'full_transfer' => true,
                        'child_id_map' => [],
                        'archive' => true,
                    ];
                    $manual[] = [
                        'type' => $type,
                        'reason' => 'code_reuse',
                        'old_id' => $oldId,
                        'new_id' => $newId,
                        'hint' => "代码重用：旧单位已归档至 {$archiveId}，新单位启用官方代码；remap 列语义需人工复核",
                    ];
                    break;

                default:
                    throw new RuntimeException("未知 change_type：{$type}");
            }

            $records[] = [
                'change_type' => $type,
                'old_id' => $oldId,
                'new_id' => $newId,
                'old_name' => $change['old_name'] ?? ($oldId !== null ? ($oldMap[$oldId]['name'] ?? null) : null),
                'new_name' => $change['new_name'] ?? ($newId !== null ? ($newMap[$newId]['name'] ?? null) : null),
                'detail' => $detail,
                'evidence_url' => $change['evidence'][0]['url'] ?? null,
                'evidence_title' => $change['evidence'][0]['title'] ?? null,
                'ai_summary' => $detail['summary'] ?? null,
            ];
        }

        return [
            'version' => (string) $changes['version'],
            'areas' => $areas,
            'mappings' => $mappings,
            'manual' => $manual,
            'records' => $records,
        ];
    }

    /**
     * payload → 薄壳迁移文件内容。
     *
     * @param  array<string, mixed>  $payload
     */
    public function renderMigration(array $payload): string
    {
        $export = var_export($payload, true);

        return <<<PHP
        <?php

        declare(strict_types=1);

        use Illuminate\Database\Migrations\Migration;
        use Quansitech\Cmf\Area\Services\MigrationExecutor;

        /**
         * 区划数据升级：{$payload['version']}。
         * 本文件由 area:generate-migration 依据经 PR 审查的 changes.json 生成，
         * 仅含冻结的变更数据（不含任何业务表名）；执行逻辑在 MigrationExecutor，
         * 业务表映射在执行期按本项目 cmf_area_references 登记延迟绑定。
         */
        return new class extends Migration
        {
            private array \$payload = {$export};

            public function up(): void
            {
                app(MigrationExecutor::class)->apply(\$this->payload);
            }

            public function down(): void
            {
                app(MigrationExecutor::class)->revert(\$this->payload);
            }
        };

        PHP;
    }

    /**
     * 迁移文件名：{date}_area_update_{version}.php（版本号中的 . 转 _）。
     */
    public function migrationFileName(string $version, ?string $date = null): string
    {
        $date ??= date('Y_m_d');

        return $date.'_area_update_'.str_replace('.', '_', $version).'.php';
    }

    /**
     * 归档 id：90{原id}（见开发方案 §10.3）。
     */
    public function archiveIdOf(int $id): int
    {
        return (int) ('90'.$id);
    }

    /**
     * @param  array<int, array<string, mixed>>  $map
     * @return array<string, mixed>
     */
    protected function insertOp(?int $id, array $map): array
    {
        if ($id === null || ! isset($map[$id])) {
            throw new RuntimeException("insert 操作缺少新版 csv 行：id=".var_export($id, true));
        }

        return [
            'op' => 'insert',
            'id' => $id,
            'pid' => (int) $map[$id]['pid'],
            'deep' => (int) $map[$id]['deep'],
            'name' => $map[$id]['name'],
            'pinyin_prefix' => $map[$id]['pinyin_prefix'],
            'pinyin' => $map[$id]['pinyin'],
            'ext_id' => (int) $map[$id]['ext_id'],
            'ext_name' => $map[$id]['ext_name'],
        ];
    }

    /**
     * 某 id 及其全部下级（新版 csv 内递归收集，含自身）。
     *
     * @param  array<int, array<string, mixed>>  $map
     * @return list<int>
     */
    protected function familyOf(?int $id, array $map): array
    {
        if ($id === null) {
            return [];
        }

        $childrenOf = [];
        foreach ($map as $row) {
            $childrenOf[(int) $row['pid']][] = (int) $row['id'];
        }

        $result = [$id];
        $queue = [$id];

        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($childrenOf[$current] ?? [] as $child) {
                $result[] = $child;
                $queue[] = $child;
            }
        }

        return $result;
    }

    /**
     * merge/code_change 时被并入单位的下级里，不在 child_id_map key 侧的
     * 视为随主体一并退休的下级（payload 里 retire）。
     *
     * @param  array<string, mixed>|array<int, mixed>  $childIdMap
     * @return list<int>
     */
    protected function mergedOutChildren(?int $oldId, array $childIdMap): array
    {
        // child_id_map 的 key 侧就是旧单位下级，value 侧是新单位下级；
        // 未出现在 key 侧的旧下级由 AI 在 detail 说明（如已同期撤并），
        // 不在此自动退休，避免误伤。
        return [];
    }

    /**
     * child_id_map 的键值统一规整为 int => int。
     *
     * @param  array<string|int, string|int>  $map
     * @return array<int, int>
     */
    protected function normalizeIdMap(array $map): array
    {
        $normalized = [];
        foreach ($map as $old => $new) {
            $normalized[(int) $old] = (int) $new;
        }

        return $normalized;
    }
}
