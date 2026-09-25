<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Services;

use Quansitech\Cmf\Area\Models\AreaChange;
use RuntimeException;

/**
 * changes.json v3（node + flat edge）→ 迁移文件（确定性翻译器，
 * 见 docs/changes-json-semantics.md §4.3 与 docs/area-precise-rollback-and-changes-v3.md §6）。
 *
 * 生成的是"薄壳迁移文件"：文件里只有冻结的 payload 数据（变更事实与映射、
 * 版本号、journal 精确回滚标记），执行逻辑全部在模块内置的 MigrationExecutor。
 * 业务表名延迟绑定，执行期才从引用登记表读取。
 *
 * payload 推导规则：
 *  1. 显式 node → 结构操作（appeared = 整族 insert；retired = retire；
 *     continued + attributes = rename / reparent；废止复用 = archive + insert）；
 *  2. 边端点的派生 node 同样发射结构操作：from ∉ 新版 ⇒ retire；
 *     to ∉ 旧版（或 id 复用启用）⇒ 整族 upsert（借 updateOrCreate 幂等性）；
 *  3. edge → 映射项拍平：每条边产出一个 {from, to, unit_level} 对，
 *     单位级边仅 unit_mapping 成立时进 mappings，否则只进人工清单；
 *  4. mappings 写盘前按 §2.5 拓扑排序（payload 自证执行序），
 *     复用清单来自 ChangesGraph 的共享实现（与校验器同一份）。
 *  5. records 的 old_name/new_name/detail.attributes 全部从 csv 派生，
 *     evidence 取文件级证据池内该记录引用的第一条。
 */
class MigrationGenerator
{
    /**
     * changes.json → payload（展开为结构操作与映射指令并冻结）。
     *
     * @param  array<string, mixed>  $changes  已通过 check-changes 校验的 changes.json（v3）
     * @param  array<int, array<string, mixed>>  $oldMap  旧基线 csv
     * @param  array<int, array<string, mixed>>  $newMap  新版 csv
     * @return array<string, mixed>
     */
    public function payload(array $changes, array $oldMap, array $newMap): array
    {
        if ((int) ($changes['schema_version'] ?? 0) !== 3) {
            throw new RuntimeException('changes.json 不是 v3 格式（schema_version=3），请先按 v3 契约重写并通过 area:check-changes 校验');
        }

        $graph = new ChangesGraph($changes['changes'], $oldMap, $newMap, $changes['evidence'] ?? []);

        $continuedOps = [];
        $retireOps = [];
        $archiveOps = [];
        $insertOps = [];
        $mappings = [];
        $manual = [];

        $retiredIds = []; // retire 去重（显式 node 优先）
        $insertedIds = []; // insert 去重

        // ── 1. 显式 node → 结构操作 ──
        foreach ($graph->nodes() as $node) {
            $id = $node['id'];

            if ($node['state'] === ChangesGraph::STATE_CONTINUED) {
                // continued 的属性变化 → rename / reparent（只动 cmf_areas 结构；值从新版 csv 取）
                if (array_intersect($node['attributes'], ['name', 'ext_name']) !== []) {
                    $continuedOps[] = [
                        'op' => 'rename',
                        'id' => $id,
                        'name' => $newMap[$id]['name'] ?? null,
                        'ext_name' => $newMap[$id]['ext_name'] ?? null,
                        'pinyin_prefix' => $newMap[$id]['pinyin_prefix'] ?? '',
                        'pinyin' => $newMap[$id]['pinyin'] ?? '',
                    ];
                }
                if (in_array('pid', $node['attributes'], true)) {
                    $continuedOps[] = ['op' => 'reparent', 'id' => $id, 'pid' => (int) ($newMap[$id]['pid'] ?? 0)];
                }

                continue;
            }

            if ($node['state'] === ChangesGraph::STATE_RETIRED && $node['side'] === ChangesGraph::SIDE_OLD) {
                if ($this->isArchiveReuse($graph, $id)) {
                    continue; // 废止复用：由 appeared 侧的 archive 操作接管，不再 retire
                }
                // 单位级出边唯一时以其目标为承继者（信息性）；多分叉/无出边则为 null
                $unitOut = array_values(array_filter(
                    $graph->edgesFrom($id),
                    fn (array $e): bool => $graph->isUnitLevelEdge($e),
                ));
                $retireOps[] = [
                    'op' => 'retire',
                    'id' => $id,
                    'successor_id' => count($unitOut) === 1 ? $unitOut[0]['to'] : null,
                ];
                $retiredIds[$id] = true;

                if ($unitOut === []) {
                    // 撤销且无单位级承继（旧 abolish）：不自动改业务数据，进人工清单
                    $manual[] = [
                        'type' => AreaChange::TYPE_ABOLISH,
                        'reason' => 'abolish_no_successor',
                        'old_id' => $id,
                        'new_id' => null,
                        'hint' => '撤销且无单位级承继：不自动改业务数据，进人工清单',
                    ];
                }

                continue;
            }

            if ($node['state'] === ChangesGraph::STATE_APPEARED && $node['side'] === ChangesGraph::SIDE_NEW) {
                if ($this->isArchiveReuse($graph, $id)) {
                    // 废止复用（code_reuse）：旧行主键迁至归档 id 段（90{原id}），
                    // ext_name 保留，keep 策略的业务引用一并指向归档 id
                    $archiveId = ChangesGraph::archiveIdOf($id);
                    $archiveOps[] = ['op' => 'archive', 'id' => $id, 'archive_id' => $archiveId];
                    $mappings[] = ['from' => $id, 'to' => $archiveId, 'unit_level' => true, 'archive' => true];
                    $manual[] = [
                        'type' => AreaChange::TYPE_CODE_REUSE,
                        'reason' => 'code_reuse',
                        'old_id' => $id,
                        'new_id' => $id,
                        'hint' => "代码重用（废止复用）：旧单位已归档至 {$archiveId}，新单位启用官方代码；remap 列语义需人工复核",
                    ];
                }

                // appeared = 整族 insert（借 updateOrCreate 幂等性覆盖链式复用中被 retire 的同行）
                foreach ($this->familyOf($id, $newMap) as $familyId) {
                    if (! isset($insertedIds[$familyId])) {
                        $insertOps[] = $this->insertOp($familyId, $newMap);
                        $insertedIds[$familyId] = true;
                    }
                }
            }
        }

        // ── 2. 边端点的派生 node 发射结构操作（936 条乡镇级 code_change 无需手写 node）──
        foreach ($graph->edges() as $edge) {
            $from = $edge['from'];
            $to = $edge['to'];

            // from ∉ 新版 ⇒ retire（显式 node 已处理的跳过）
            if (! isset($newMap[$from]) && ! isset($retiredIds[$from])) {
                $retireOps[] = ['op' => 'retire', 'id' => $from, 'successor_id' => $to];
                $retiredIds[$from] = true;
            }

            // to ∉ 旧版，或 to 是 id 复用启用（旧版同码是另一个单位）⇒ 整族 upsert
            if (! isset($oldMap[$to]) || $graph->isReuseCovered($to)) {
                foreach ($this->familyOf($to, $newMap) as $familyId) {
                    if (! isset($insertedIds[$familyId])) {
                        $insertOps[] = $this->insertOp($familyId, $newMap);
                        $insertedIds[$familyId] = true;
                    }
                }
            }
        }

        // ── 3. edge → 映射项拍平；单位级边按 unit_mapping 判定执行或转人工 ──
        foreach ($graph->edges() as $edge) {
            $from = $edge['from'];
            $to = $edge['to'];

            if (! $graph->isUnitLevelEdge($edge)) {
                // 下级边一律执行（每条都是无歧义的一对一）
                $mappings[] = ['from' => $from, 'to' => $to, 'unit_level' => false];

                continue;
            }

            $failures = $graph->unitMappingFailures($from, $to);
            if ($failures === []) {
                $mappings[] = ['from' => $from, 'to' => $to, 'unit_level' => true];
            } else {
                // 不成立的单位级对只进 manual、不进 mappings（§1.4 的一刀切在模型层面不复存在）
                $isContinuingSplit = isset($newMap[$from]) && ! $graph->isReuseCovered($from);
                $unitOutCount = count(array_filter(
                    $graph->edgesFrom($from),
                    fn (array $e): bool => $graph->isUnitLevelEdge($e),
                ));
                $manual[] = [
                    'type' => $graph->edgeChangeType($edge),
                    // 浅层值不可判定的两种形态：母体存续的析出新设 / 一分为多的拆分
                    'reason' => ($isContinuingSplit || $unitOutCount >= 2) ? 'split_shallow_value' : 'partial_transfer',
                    'old_id' => $from,
                    'new_id' => $to,
                    'hint' => implode('；', $failures).'，只存上级 id 的行分不清是否在被划走的下级里，一律人工处理',
                ];
            }
        }

        // ── 4. mappings 拓扑排序（§2.5）：复用 id 上 e_out ≺ e_in，payload 自证执行序 ──
        $order = $graph->topoSortPairs($mappings);
        if ($order === null) {
            throw new RuntimeException('id 复用约束成环（如 A→B、B→A 互换）：映射执行序拓扑排序失败，按 §2.5 禁环规则转人工处理');
        }
        $mappings = array_map(fn (int $i): array => $mappings[$i], $order);

        // ── 5. records：node / edge 各一条留档，change_type 为派生标签；
        //    old_name/new_name/detail.attributes 全部从 csv 派生；evidence 取池内第一条 ──
        $records = [];
        foreach ($graph->nodes() as $node) {
            $id = $node['id'];
            $evidence = $graph->evidenceOf($node['evidence']);
            $records[] = [
                'kind' => 'node',
                'side' => $node['side'],
                'change_type' => $graph->nodeChangeType($node),
                'old_id' => $node['side'] === ChangesGraph::SIDE_OLD ? $id : null,
                'new_id' => $node['side'] === ChangesGraph::SIDE_NEW ? $id : null,
                'old_name' => $node['side'] === ChangesGraph::SIDE_OLD ? ($oldMap[$id]['name'] ?? null) : null,
                'new_name' => $node['side'] === ChangesGraph::SIDE_NEW ? ($newMap[$id]['name'] ?? null) : null,
                'detail' => array_filter([
                    'attributes' => $this->attributeDiff($node['attributes'], $oldMap[$id] ?? null, $newMap[$id] ?? null),
                    'exceptions' => $node['raw']['exceptions'] ?? null,
                    'id_reuse' => $node['id_reuse'] ?: null,
                ]),
                'evidence_url' => $evidence[0]['url'] ?? null,
                'evidence_title' => $evidence[0]['title'] ?? null,
                'ai_summary' => $node['summary'],
            ];
        }
        foreach ($graph->edges() as $edge) {
            $evidence = $graph->evidenceOf($edge['evidence']);
            $records[] = [
                'kind' => 'edge',
                'side' => null,
                'change_type' => $graph->edgeChangeType($edge),
                'old_id' => $edge['from'],
                'new_id' => $edge['to'],
                'old_name' => $oldMap[$edge['from']]['name'] ?? null,
                'new_name' => $newMap[$edge['to']]['name'] ?? null,
                'detail' => array_filter([
                    'unit_level' => $graph->isUnitLevelEdge($edge),
                    'cross_level_reason' => $edge['cross_level_reason'],
                ], fn (mixed $v): bool => $v !== null && $v !== false),
                'evidence_url' => $evidence[0]['url'] ?? null,
                'evidence_title' => $evidence[0]['title'] ?? null,
                'ai_summary' => $edge['summary'],
            ];
        }

        return [
            'version' => (string) $changes['version'],
            // 本次升级的基线版本（生成时的当前数据版本，发布流程随后才更新 config）：
            // 执行器据此守卫——库内数据版本与 from_version 不一致时跳过
            //（全新安装导入的基线已包含本次变更，重放会误伤有效行）
            'from_version' => (string) config('cmf-area.data_version'),
            // 行级回滚日志标记：执行器 revert 据此走 journal 精确回放（无标记的旧格式文件走旧式值扫描）
            'journal' => true,
            'areas' => [...$continuedOps, ...$retireOps, ...$archiveOps, ...$insertOps],
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
         * up() 的行级现场写入 cmf_area_migration_journal，down() 按日志精确逆序回放。
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
     * 迁移文件名：{date}_area_update_{version}.php（版本号中的 . 与 + 转 _，
     * + 来自部分地区发版的自有版本号，见升级方案 §3.3）。
     */
    public function migrationFileName(string $version, ?string $date = null): string
    {
        $date ??= date('Y_m_d');

        return $date.'_area_update_'.str_replace(['.', '+'], '_', $version).'.php';
    }

    /**
     * continued node 的属性变化明细：字段名清单 → 从两版 csv 派生 [旧值, 新值]。
     *
     * @param  list<string>  $fields
     * @param  array<string, mixed>|null  $oldRow
     * @param  array<string, mixed>|null  $newRow
     * @return array<string, array{mixed, mixed}>|null
     */
    protected function attributeDiff(array $fields, ?array $oldRow, ?array $newRow): ?array
    {
        if ($fields === []) {
            return null;
        }

        $diff = [];
        foreach ($fields as $field) {
            $diff[$field] = [
                $field === 'pid' ? (int) ($oldRow[$field] ?? 0) : ($oldRow[$field] ?? null),
                $field === 'pid' ? (int) ($newRow[$field] ?? 0) : ($newRow[$field] ?? null),
            ];
        }

        return $diff;
    }

    /**
     * 归档 id：90{原id}（见开发方案 §10.3）。
     *
     * @deprecated 语义已迁移至 ChangesGraph::archiveIdOf()（校验器/生成器共享）
     */
    public function archiveIdOf(int $id): int
    {
        return ChangesGraph::archiveIdOf($id);
    }

    /**
     * 废止复用判定：新侧 appeared node 声明 id_reuse、id 在旧版存在、且旧侧无出边
     * （旧单位无承继）⇒ 走 90{id} 归档路径；有出边的是链式复用，按普通边 remap。
     */
    protected function isArchiveReuse(ChangesGraph $graph, int $id): bool
    {
        $node = $graph->node(ChangesGraph::SIDE_NEW, $id);

        return $node !== null
            && $node['state'] === ChangesGraph::STATE_APPEARED
            && $node['id_reuse']
            && isset($graph->oldMap[$id])
            && $graph->edgesFrom($id) === [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $map
     * @return array<string, mixed>
     */
    protected function insertOp(?int $id, array $map): array
    {
        if ($id === null || ! isset($map[$id])) {
            throw new RuntimeException('insert 操作缺少新版 csv 行：id='.var_export($id, true));
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
}
