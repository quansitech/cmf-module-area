<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Services;

/**
 * changes.json（v3：node + flat edge）的机器兜底校验服务（纯确定性）。
 * 由 CheckChangesCommand（CLI）与升级批次 Web 界面共用：
 * 命令是薄壳，本服务返回结构化错误清单（可直接喂回 AI 重试）。
 *
 * 分两层：schema 层 + 逻辑层。逻辑层是图级检查（不变量见 docs/changes-json-semantics.md §2.2）：
 *  I1 唯一键与退化边；I2 边端点真实；I3 跨层显式声明；I4 例外挂点与 id 空间；
 *  I5 diff 事实按侧认领（支持 scoped 模式：只要求选中地区的事实 100% 认领）；
 *  I7 id 复用显式化（含复用链禁环）。
 *
 * 版本契约（升级方案 §13.5）：diff.json from_version = area 当前数据版本、
 * to_version = 上游 tag（仅溯源）；changes.json version = 新 area 版本
 * （对齐上游时 = to_version，部分地区发版时 = {from_version 基链}+N）。
 */
class ChangesValidator
{
    /** @var list<string> */
    protected array $errors = [];

    public function __construct(protected readonly DiffService $diffService) {}

    /**
     * 全量校验（schema + 逻辑层）。
     *
     * @param  array<string, mixed>  $changes  changes.json 解析结果
     * @param  array<string, mixed>|null  $diff  diff.json 解析结果（覆盖率/版本校验用，可空）
     * @param  array<int, array<string, mixed>>  $oldMap  旧基线 csv
     * @param  array<int, array<string, mixed>>  $newMap  新版 csv
     * @param  list<int>|null  $scopeRegionIds  选中地区 id 清单（null = 全量模式；
     *         给定后 I5 覆盖率只要求这些地区子树内的事实被认领）
     * @param  bool  $skipCoverage  跳过 I5 覆盖率检查（编辑/手工录入保存时的实时校验：
     *         覆盖率是批次级门禁，单条落库不该被无关未认领事实阻断）
     * @return list<string> 错误清单（空数组 = 全绿）
     */
    public function validate(array $changes, ?array $diff, array $oldMap, array $newMap, ?array $scopeRegionIds = null, bool $skipCoverage = false): array
    {
        $this->errors = [];

        $this->checkSchema($changes);
        $this->checkLogic($changes, $diff, $oldMap, $newMap, $scopeRegionIds, $skipCoverage);

        return $this->errors;
    }

    /**
     * schema 层：v3 顶层结构（schema_version/version/from_version/evidence 证据池）、
     * node/edge 判别、字段白名单、类型与枚举、证据引用存在性、node 证据自证。
     *
     * @param  array<string, mixed>  $changes
     */
    protected function checkSchema(array $changes): void
    {
        if (($changes['schema_version'] ?? null) !== 3) {
            $this->errors[] = 'schema_version 缺失或不为 3（v3 格式：side 由 state 派生、change_type 纯派生禁手写、'
                .'attributes 为字段名清单、evidence 为文件级证据池引用；v2 格式已废弃）';
        }

        if (! isset($changes['version']) || ! is_string($changes['version'])) {
            $this->errors[] = 'version 缺失或不是字符串';
        }

        if (isset($changes['from_version']) && ! is_string($changes['from_version'])) {
            $this->errors[] = 'from_version 必须是字符串';
        }

        $poolKeys = $this->checkEvidencePool($changes);

        if (! isset($changes['changes']) || ! is_array($changes['changes']) || $changes['changes'] === []) {
            $this->errors[] = 'changes 缺失、不是数组或为空';

            return;
        }

        foreach ($changes['changes'] as $i => $item) {
            $label = "changes[{$i}]";
            if (! is_array($item)) {
                $this->errors[] = "{$label} 不是对象";

                continue;
            }

            $kind = $item['kind'] ?? null;
            if (! in_array($kind, ['node', 'edge'], true)) {
                $this->errors[] = "{$label}.kind 非法：".var_export($kind, true).'，枚举：node/edge';

                continue;
            }

            // v3 封闭对象：side（state 派生）与 change_type（纯派生）不在可写契约内
            $allowed = $kind === 'node'
                ? ['kind', 'id', 'name', 'state', 'attributes', 'id_reuse', 'exceptions', 'summary', 'evidence', 'confidence']
                : ['kind', 'from_id', 'to_id', 'cross_level_reason', 'summary', 'evidence', 'confidence'];
            foreach (array_keys($item) as $key) {
                if (! in_array($key, $allowed, true)) {
                    $this->errors[] = "{$label}.{$key} 字段未定义（v3 node/edge 均为封闭对象；side 由 state 派生、change_type 纯派生，均不可手写）";
                }
            }

            if ($kind === 'node') {
                $this->checkNodeSchema($item, $label);
            } else {
                $this->checkEdgeSchema($item, $label);
            }

            $this->checkEvidenceRefs($item, $label, $poolKeys, required: $kind === 'node');

            if (isset($item['confidence']) && ! in_array($item['confidence'], ['high', 'low'], true)) {
                $this->errors[] = "{$label}.confidence 仅可取 high / low";
            }
        }
    }

    /**
     * 逻辑层：与 diff.json、新旧两版 csv 交叉核对（图级检查 I1–I5、I7）。
     *
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>|null  $diff
     * @param  array<int, array<string, mixed>>  $oldMap
     * @param  array<int, array<string, mixed>>  $newMap
     * @param  list<int>|null  $scopeRegionIds
     */
    protected function checkLogic(array $changes, ?array $diff, array $oldMap, array $newMap, ?array $scopeRegionIds, bool $skipCoverage = false): void
    {
        /** @var list<array<string, mixed>> $items */
        $items = array_values(array_filter($changes['changes'] ?? [], 'is_array'));
        $graph = new ChangesGraph($items, $oldMap, $newMap, is_array($changes['evidence'] ?? null) ? $changes['evidence'] : []);

        // I1 不依赖 csv，始终执行
        $this->checkUniqueness($graph);

        if ($oldMap !== [] && $newMap !== []) {
            $this->checkEdgeEndpoints($graph);
            $this->checkEdgeLevel($graph);
            $this->checkExceptionsScope($graph);
            $this->checkDerivedConsistency($graph);
            $this->checkEdgeEvidenceCoverage($graph);
            $this->checkIdReuseExplicit($graph);
        }

        if (is_array($diff)) {
            if (! $skipCoverage) {
                $this->checkCoverageBySide($graph, $diff, $scopeRegionIds, $oldMap, $newMap);
            }
            $this->checkVersion($changes, $diff);
        }
    }

    /**
     * I1：node 唯一键 (side, id)（side 由 state 派生）；edge 唯一键 (from_id, to_id)；
     * edge 不允许 from==to 退化边。
     */
    protected function checkUniqueness(ChangesGraph $graph): void
    {
        $seenNodes = [];
        foreach ($graph->nodes() as $node) {
            $key = $node['side'].':'.$node['id'];
            if (isset($seenNodes[$key])) {
                $this->errors[] = "changes[{$node['_index']}]（node）与 changes[{$seenNodes[$key]}] 重复：同一 (side={$node['side']}, id={$node['id']}) 只允许一条 node——请把两条的 summary/evidence/exceptions 等信息合并为一条后删除多余者";
            }
            $seenNodes[$key] = $node['_index'];
        }

        $seenEdges = [];
        foreach ($graph->edges() as $edge) {
            if ($edge['from'] === $edge['to']) {
                $this->errors[] = "changes[{$edge['_index']}]（edge）退化边：from_id == to_id（{$edge['from']}）；同 id 的属性变化请用 continued node 表达";
            }
            $key = $edge['from'].'→'.$edge['to'];
            if (isset($seenEdges[$key])) {
                $this->errors[] = "changes[{$edge['_index']}]（edge）与 changes[{$seenEdges[$key]}] 重复：同一 (from_id={$edge['from']}, to_id={$edge['to']}) 只允许一条 edge";
            }
            $seenEdges[$key] = $edge['_index'];
        }
    }

    /**
     * I2：edge 方向与端点真实（from ∈ 旧版 csv、to ∈ 新版 csv）；node 的 id 必须
     * 存在于本侧 csv（side 由 state 派生；continued 需两版均在，见派生一致性检查）。
     */
    protected function checkEdgeEndpoints(ChangesGraph $graph): void
    {
        foreach ($graph->edges() as $edge) {
            if (! isset($graph->oldMap[$edge['from']])) {
                $this->errors[] = "changes[{$edge['_index']}]（edge）from_id {$edge['from']} 不存在于旧基线 csv（影子端点）";
            }
            if (! isset($graph->newMap[$edge['to']])) {
                $this->errors[] = "changes[{$edge['_index']}]（edge）to_id {$edge['to']} 不存在于新版 csv（影子端点）";
            }
        }

        foreach ($graph->nodes() as $node) {
            $map = $node['side'] === ChangesGraph::SIDE_OLD ? $graph->oldMap : $graph->newMap;
            $sideName = $node['side'] === ChangesGraph::SIDE_OLD ? '旧基线' : '新版';
            if (! isset($map[$node['id']])) {
                $this->errors[] = "changes[{$node['_index']}]（node）id {$node['id']} 不存在于{$sideName} csv";
            }
        }
    }

    /**
     * I3：edge 两端层级建议相同；跨层必须显式写 cross_level_reason。
     */
    protected function checkEdgeLevel(ChangesGraph $graph): void
    {
        foreach ($graph->edges() as $edge) {
            $fromRow = $graph->oldMap[$edge['from']] ?? null;
            $toRow = $graph->newMap[$edge['to']] ?? null;
            if ($fromRow === null || $toRow === null) {
                continue; // 端点缺失由 I2 报告
            }
            if ((int) $fromRow['deep'] !== (int) $toRow['deep'] && ($edge['cross_level_reason'] ?? null) === null) {
                $this->errors[] = "changes[{$edge['_index']}]（edge）跨层对应 {$edge['from']}（deep={$fromRow['deep']}）→ {$edge['to']}（deep={$toRow['deep']}）必须显式写 cross_level_reason";
            }
        }
    }

    /**
     * I4：例外只挂 node——exceptions[].id 必须 ⊆ 本节点同侧子树，且该 id 只在同侧
     * 存在（old 节点：新版无此 id；new 节点：旧版无此 id）。
     */
    protected function checkExceptionsScope(ChangesGraph $graph): void
    {
        foreach ($graph->nodes() as $node) {
            if ($node['exceptions'] === []) {
                continue;
            }

            $side = $node['side'];
            $descendants = array_flip($graph->descendantsOf($node['id'], $side));
            $oppositeMap = $side === ChangesGraph::SIDE_OLD ? $graph->newMap : $graph->oldMap;
            $sideName = $side === ChangesGraph::SIDE_OLD ? '旧版' : '新版';
            $oppositeName = $side === ChangesGraph::SIDE_OLD ? '新版' : '旧版';

            foreach ($node['exceptions'] as $exId) {
                if (! isset($descendants[$exId])) {
                    $this->errors[] = "changes[{$node['_index']}]（node）exceptions 的 id {$exId} 不是本节点 {$node['id']} 的{$sideName}下级：例外只能挂本节点同侧子树";
                }
                if (isset($oppositeMap[$exId])) {
                    $this->errors[] = "changes[{$node['_index']}]（node）exceptions 的 id {$exId} 在{$oppositeName}仍存在：例外只收留'只在同侧存在'的下级（两版均在的 id 请用边或 continued node 表达）";
                }
            }
        }
    }

    /**
     * I5：按侧覆盖——diff 的每个 id 事实必须被同侧的 node / edge 端点 / exceptions
     * 认领（旧侧事实不得由新侧字段认领）。scoped 模式下只要求选中地区子树内的事实。
     *
     * @param  array<string, mixed>  $diff
     * @param  list<int>|null  $scopeRegionIds
     * @param  array<int, array<string, mixed>>  $oldMap
     * @param  array<int, array<string, mixed>>  $newMap
     */
    protected function checkCoverageBySide(ChangesGraph $graph, array $diff, ?array $scopeRegionIds, array $oldMap, array $newMap): void
    {
        foreach ($this->uncoveredFacts($graph, $diff, $scopeRegionIds, $oldMap, $newMap) as $fact) {
            if ($fact['need_old']) {
                $this->errors[] = "覆盖率：diff 事实 {$fact['label']} 未被旧侧认领（retired/continued node / edge 的 from_id / retired node 的 exceptions）";
            }
            if ($fact['need_new']) {
                $this->errors[] = "覆盖率：diff 事实 {$fact['label']} 未被新侧认领（appeared node / edge 的 to_id / appeared node 的 exceptions / continued node 派生对侧）";
            }
        }
    }

    /**
     * 计算 changes 图对 diff 事实的认领情况（旧侧/新侧已认领 id 集）。
     * 校验器与批次覆盖率仪表盘共用同一份认领规则。
     *
     * @return array{0: array<int, true>, 1: array<int, true>} [旧侧认领, 新侧认领]
     */
    public function claimedIds(ChangesGraph $graph): array
    {
        $oldClaimed = [];
        $newClaimed = [];

        foreach ($graph->nodes() as $node) {
            if ($node['side'] === ChangesGraph::SIDE_OLD) {
                $oldClaimed[$node['id']] = true;
                if ($node['state'] === ChangesGraph::STATE_CONTINUED) {
                    $newClaimed[$node['id']] = true; // 单侧书写 + 派生对侧
                }
            } else {
                $newClaimed[$node['id']] = true;
            }
            foreach ($node['exceptions'] as $exId) {
                if ($node['side'] === ChangesGraph::SIDE_OLD) {
                    $oldClaimed[$exId] = true;
                } else {
                    $newClaimed[$exId] = true;
                }
            }
        }
        foreach ($graph->edges() as $edge) {
            $oldClaimed[$edge['from']] = true;
            $newClaimed[$edge['to']] = true;
        }

        return [$oldClaimed, $newClaimed];
    }

    /**
     * diff 事实清单（含按侧认领要求）。
     *
     * @param  array<string, mixed>  $diff
     * @return array<int, array{label: string, need_old: bool, need_new: bool, province: string, kind: string}>
     */
    public function diffFacts(array $diff): array
    {
        $facts = [];
        foreach (($diff['provinces'] ?? []) as $province => $groups) {
            foreach (['added', 'removed', 'renamed', 'parent_changed'] as $kind) {
                foreach ($groups[$kind] ?? [] as $row) {
                    $id = (int) $row['id'];
                    $facts[$id] = [
                        'label' => "{$province}/{$kind} #{$id}",
                        'need_old' => in_array($kind, ['removed', 'renamed', 'parent_changed'], true),
                        'need_new' => in_array($kind, ['added', 'renamed', 'parent_changed'], true),
                        'province' => (string) $province,
                        'kind' => $kind,
                    ];
                }
            }
        }

        return $facts;
    }

    /**
     * 未被认领的 diff 事实（scoped 模式只统计选中地区子树内的事实）。
     *
     * @param  array<string, mixed>  $diff
     * @param  list<int>|null  $scopeRegionIds
     * @param  array<int, array<string, mixed>>  $oldMap
     * @param  array<int, array<string, mixed>>  $newMap
     * @return list<array{id: int, label: string, need_old: bool, need_new: bool, province: string, kind: string}>
     */
    public function uncoveredFacts(ChangesGraph $graph, array $diff, ?array $scopeRegionIds, array $oldMap, array $newMap): array
    {
        [$oldClaimed, $newClaimed] = $this->claimedIds($graph);

        $uncovered = [];
        foreach ($this->diffFacts($diff) as $id => $fact) {
            if ($scopeRegionIds !== null && ! $this->diffService->isInRegions($id, $scopeRegionIds, $oldMap, $newMap)) {
                continue;
            }
            $lackOld = $fact['need_old'] && ! isset($oldClaimed[$id]);
            $lackNew = $fact['need_new'] && ! isset($newClaimed[$id]);
            if ($lackOld || $lackNew) {
                $uncovered[] = [
                    'id' => $id,
                    'label' => $fact['label'],
                    'need_old' => $lackOld,
                    'need_new' => $lackNew,
                    'province' => $fact['province'],
                    'kind' => $fact['kind'],
                ];
            }
        }

        return $uncovered;
    }

    /**
     * 派生一致性（v3 收缩后仅剩状态与属性声明的 csv 交叉核对）：
     * continued 须两版均在且声明的字段确实发生变化；retired/appeared 与 id_reuse
     * 声明互洽。change_type 为纯派生，不再有手写一致性核对（I6 消失）。
     */
    protected function checkDerivedConsistency(ChangesGraph $graph): void
    {
        foreach ($graph->nodes() as $node) {
            $id = $node['id'];
            $inOld = isset($graph->oldMap[$id]);
            $inNew = isset($graph->newMap[$id]);
            $label = "changes[{$node['_index']}]";

            if ($node['state'] === ChangesGraph::STATE_CONTINUED) {
                if (! $inOld || ! $inNew) {
                    $this->errors[] = "{$label}（continued node）id {$id} 须两版均在（旧侧：".($inOld ? '在' : '不在').'，新侧：'.($inNew ? '在' : '不在').'），否则应为 retired / appeared';
                }
                if ($node['attributes'] === []) {
                    $this->errors[] = "{$label}（continued node）必须声明 attributes 字段名清单（如 [\"ext_name\"]），无属性变化的单位无需书写 node";
                }
                foreach ($node['attributes'] as $field) {
                    if (! in_array($field, ChangesGraph::ATTRIBUTE_KEYS, true)) {
                        continue; // 字段白名单由 schema 层报告
                    }
                    $csvOld = $inOld ? ($graph->oldMap[$id][$field] ?? null) : null;
                    $csvNew = $inNew ? ($graph->newMap[$id][$field] ?? null) : null;
                    if ($inOld && $inNew && (string) $csvOld === (string) $csvNew) {
                        $this->errors[] = "{$label}（continued node）声明的字段 {$field} 在两版 csv 间无变化（均为 ".var_export($csvOld, true).'）；attributes 只声明发生变化的字段';
                    }
                }
            }

            if ($node['state'] === ChangesGraph::STATE_RETIRED && $node['side'] === ChangesGraph::SIDE_OLD && $inNew && ! $graph->isReuseCovered($id)) {
                $this->errors[] = "{$label}（retired node）id {$id} 在新版仍存在：如同 id 换单位（链式/废止复用），appeared node 必须显式声明 id_reuse: true（I7）；否则该单位应为 continued";
            }

            if ($node['state'] === ChangesGraph::STATE_APPEARED && $node['side'] === ChangesGraph::SIDE_NEW && $inOld && ! $node['id_reuse']) {
                $this->errors[] = "{$label}（appeared node）id {$id} 在旧版已存在：同 id 换单位必须显式声明 id_reuse: true 并在 summary 写明复用对应关系（I7）";
            }

            if ($node['id_reuse']) {
                if ($node['side'] !== ChangesGraph::SIDE_NEW) {
                    $this->errors[] = "{$label}：id_reuse 只允许标在 appeared node 上（复用的语义是'新单位启用旧 id'）";
                }
                if (! $inOld) {
                    $this->errors[] = "{$label}：声明了 id_reuse 但 id {$id} 在旧版不存在，无复用事实";
                }
                if (($node['summary'] ?? null) === null || trim((string) $node['summary']) === '') {
                    $this->errors[] = "{$label}：id_reuse=true 时 summary 必须写明复用对应关系（哪个旧单位腾出了该 id、旧单位去向）";
                }
            }
        }
    }

    /**
     * 证据自证（v3）：node 的证据引用必填（schema 层已查）；单位级边必须自带
     * evidence，下级边未写 evidence 时继承其单位级边（构造期已解析）——
     * 解析后仍无证据的边（单位级边 / 无单位级边的独立边）必须自带证据。
     */
    protected function checkEdgeEvidenceCoverage(ChangesGraph $graph): void
    {
        foreach ($graph->edges() as $edge) {
            if ($edge['evidence'] === []) {
                $this->errors[] = "changes[{$edge['_index']}]（edge）必须自证：未写 evidence 且无可继承的单位级边"
                    .'（单位级边必须自带证据池引用；下级边可省略以继承单位级边的证据与摘要）';
            }
        }
    }

    /**
     * I7（扩触发）：id 复用显式化——
     *  (a) 同 id 既是某边 from 又是某边 to（链式复用，如三沙 460302）；
     *  (b) 同 id 挂 retired node 且挂 appeared node、无 continued 关联（废止复用）；
     *  (a)(b) 要求复用 id 被某 appeared node 的 id_reuse=true 覆盖（自身或新版祖先）；
     *  (c) 复用约束成环（A→B、B→A 互换）⇒ 拓扑排序失败，报错转人工（§2.5 禁环）。
     */
    protected function checkIdReuseExplicit(ChangesGraph $graph): void
    {
        foreach ($graph->reusedIds() as $id => $_) {
            if (! $graph->isReuseCovered($id)) {
                $this->errors[] = "id 复用未显式声明（I7）：{$id} 被旧侧消费（边 from / retired node）又被新侧产出（边 to / appeared node），且不是 continued 关联——请在对应 appeared node（或其新版祖先 node）上声明 id_reuse: true 并在 summary 写明复用对应关系";
            }

            // 显式的 appeared node 必须自己声明（不能靠祖先覆盖）
            $newNode = $graph->node(ChangesGraph::SIDE_NEW, $id);
            if ($newNode !== null && $newNode['state'] === ChangesGraph::STATE_APPEARED && ! $newNode['id_reuse']) {
                $this->errors[] = "id 复用未显式声明（I7）：appeared node {$id} 对应同 id 的旧侧消费，该 node 自身必须声明 id_reuse: true";
            }
        }

        // (c) 禁环：复用约束成环 ⇒ 转人工
        $pairs = [];
        foreach ($graph->edges() as $edge) {
            $pairs[] = ['from' => $edge['from'], 'to' => $edge['to']];
        }
        foreach ($graph->nodes() as $node) {
            // 废止复用的归档对（X → 90X）同样参与复用约束
            if ($node['state'] === ChangesGraph::STATE_APPEARED && $node['id_reuse']
                && isset($graph->oldMap[$node['id']]) && $graph->edgesFrom($node['id']) === []) {
                $pairs[] = ['from' => $node['id'], 'to' => ChangesGraph::archiveIdOf($node['id'])];
            }
        }
        if ($graph->topoSortPairs($pairs) === null) {
            $this->errors[] = 'id 复用约束成环（如 A→B、B→A 互换）：映射执行序拓扑排序失败，按 §2.5 禁环规则转人工处理';
        }
    }

    /**
     * 版本契约（升级方案 §13.5）：
     *  - from_version 双侧均提供时必须一致（都 = area 当前数据版本）；
     *  - version = 新 area 版本：对齐上游时 = diff.to_version；部分地区发版时 =
     *    from_version 基链的递增（{基链}+N，如 2025.251231.260403 → 2025.251231.260403+1）。
     *
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $diff
     */
    protected function checkVersion(array $changes, array $diff): void
    {
        $version = $changes['version'] ?? null;
        $fromVersion = $changes['from_version'] ?? null;

        if (! empty($diff['from_version']) && ! empty($fromVersion)
            && $fromVersion !== $diff['from_version']) {
            $this->errors[] = "版本不一致：changes.json 的 from_version（{$fromVersion}）≠ diff.json 的 from_version（{$diff['from_version']}）";
        }

        if (! is_string($version)) {
            return;
        }

        $candidates = [];
        if (isset($diff['to_version']) && is_string($diff['to_version'])) {
            $candidates[] = $diff['to_version']; // 对齐上游
        }
        $base = ! empty($diff['from_version']) && is_string($diff['from_version'])
            ? $diff['from_version']
            : (is_string($fromVersion) ? $fromVersion : null);
        if ($base !== null) {
            $candidates[] = self::incrementVersion($base); // 部分地区发版
        }

        if ($candidates !== [] && ! in_array($version, $candidates, true)) {
            $this->errors[] = '版本不一致：changes.json 的 version（'.$version.'）既非上游版本（'
                .($diff['to_version'] ?? '未知').'）也非当前基线递增版本（'
                .($base !== null ? self::incrementVersion($base) : '未知').'）（升级方案 §3.3 版本号规则）';
        }
    }

    /**
     * 部分地区发版的版本号递增：{基链}+N（已有 +N 则 N+1）。
     */
    public static function incrementVersion(string $version): string
    {
        if (preg_match('/^(.*)\+(\d+)$/', $version, $m)) {
            return $m[1].'+'.((int) $m[2] + 1);
        }

        return $version.'+1';
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, true> 证据池引用 id 集合
     */
    protected function checkEvidencePool(array $changes): array
    {
        $pool = $changes['evidence'] ?? [];
        if (! is_array($pool)) {
            $this->errors[] = 'evidence 证据池必须是对象（引用 id => {title, url}）';

            return [];
        }

        $keys = [];
        foreach ($pool as $key => $entry) {
            if (! is_array($entry)
                || ! is_string($entry['title'] ?? null) || trim((string) ($entry['title'] ?? '')) === ''
                || ! is_string($entry['url'] ?? null) || trim((string) ($entry['url'] ?? '')) === '') {
                $this->errors[] = "evidence 证据池条目 {$key} 必须是 {title: 非空字符串, url: 非空字符串}";

                continue;
            }
            $keys[(string) $key] = true;
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    protected function checkNodeSchema(array $item, string $label): void
    {
        if (! isset($item['id']) || ! is_int($item['id'])) {
            $this->errors[] = "{$label}.id 缺失或不是 int";
        }

        if (! in_array($item['state'] ?? null, [ChangesGraph::STATE_APPEARED, ChangesGraph::STATE_RETIRED, ChangesGraph::STATE_CONTINUED], true)) {
            $this->errors[] = "{$label}.state 非法：".var_export($item['state'] ?? null, true).'，枚举：appeared/retired/continued（side 由 state 派生：retired→old、appeared→new、continued 单侧书写）';
        }

        if (isset($item['name']) && ! is_string($item['name'])) {
            $this->errors[] = "{$label}.name 必须是字符串";
        }

        if (isset($item['id_reuse']) && ! is_bool($item['id_reuse'])) {
            $this->errors[] = "{$label}.id_reuse 必须是 bool";
        }

        if (isset($item['attributes'])) {
            if (! is_array($item['attributes']) || ! array_is_list($item['attributes'])) {
                $this->errors[] = "{$label}.attributes 必须是字段名清单（如 [\"name\", \"pid\"]），值由机器从两版 csv 取";
            } else {
                foreach ($item['attributes'] as $j => $field) {
                    if (! is_string($field) || ! in_array($field, ChangesGraph::ATTRIBUTE_KEYS, true)) {
                        $this->errors[] = "{$label}.attributes[{$j}] 未定义：".var_export($field, true).'（仅支持 '.implode('/', ChangesGraph::ATTRIBUTE_KEYS).'）';
                    }
                }
            }
        }

        if (isset($item['exceptions'])) {
            if (! is_array($item['exceptions'])) {
                $this->errors[] = "{$label}.exceptions 必须是数组";
            } else {
                foreach ($item['exceptions'] as $j => $e) {
                    if (! is_array($e) || ! is_int($e['id'] ?? null) || ! is_string($e['reason'] ?? null) || trim((string) ($e['reason'] ?? '')) === '') {
                        $this->errors[] = "{$label}.exceptions[{$j}] 必须是 {id: int, reason: 非空字符串}";
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    protected function checkEdgeSchema(array $item, string $label): void
    {
        foreach (['from_id', 'to_id'] as $field) {
            if (! isset($item[$field]) || ! is_int($item[$field])) {
                $this->errors[] = "{$label}.{$field} 缺失或不是 int";
            }
        }

        if (isset($item['cross_level_reason']) && (! is_string($item['cross_level_reason']) || trim($item['cross_level_reason']) === '')) {
            $this->errors[] = "{$label}.cross_level_reason 必须是非空字符串";
        }
    }

    /**
     * 证据引用校验：引用 id 必须存在于顶层证据池；node 必须自证（必填），
     * edge 可省略（下级边继承单位级边，逻辑层兜底独立边）。
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, true>  $poolKeys
     */
    protected function checkEvidenceRefs(array $item, string $label, array $poolKeys, bool $required): void
    {
        $evidence = $item['evidence'] ?? null;

        if ($evidence === null) {
            if ($required) {
                $this->errors[] = "{$label}.evidence 至少需要引用一条证据池条目（node 必须自证；禁止编造，查不到标 confidence: low）";
            }

            return;
        }

        if (! is_array($evidence) || $evidence === [] || ! array_is_list($evidence)) {
            $this->errors[] = "{$label}.evidence 必须是证据池引用 id 的非空数组";

            return;
        }

        foreach ($evidence as $j => $ref) {
            if (! is_string($ref) || $ref === '') {
                $this->errors[] = "{$label}.evidence[{$j}] 必须是证据池引用 id（字符串）";
            } elseif (! isset($poolKeys[$ref])) {
                $this->errors[] = "{$label}.evidence 引用的证据池条目 {$ref} 不存在（顶层 evidence 池未定义该 id）";
            }
        }
    }
}
