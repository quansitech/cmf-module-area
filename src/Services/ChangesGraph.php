<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Services;

use Quansitech\Cmf\Area\Models\AreaChange;

/**
 * changes.json v3 图模型（node + flat edge）的共享实现——
 * 校验器（CheckChangesCommand）与生成器（MigrationGenerator）共用同一份
 * 解析、id 复用检测、拓扑排序、unit_mapping 推导与 change_type 派生逻辑，
 * 避免两处漂移（见 docs/changes-json-semantics.md §2.5 职责划分）。
 *
 * v3 模型要点（docs/area-precise-rollback-and-changes-v3.md §6 五刀简化）：
 *  - node：一个单位在某一版的存续状态，身份 = (side, id)；**side 由 state 派生**
 *    （retired→old、appeared→new、continued 约定 old 单侧书写，对侧由校验器派生）；
 *  - edge：一条旧 id → 新 id 对应关系（拍平、任意层级），身份 = (from_id, to_id)；
 *  - change_type 纯派生，不再出现在可写契约（I6 手写一致性核对整体消失）；
 *  - continued 的 attributes 只声明"哪些字段变了"（字段名清单），值由机器从两版 csv 取；
 *  - evidence 提为文件级证据池，记录上只写引用 id；下级边不写 evidence 时自动继承
 *    其单位级边（topmostFromAncestor）的 evidence 与 summary。
 *  - 单位级边：from 的旧版祖先链上不存在其他边的 from；其余为下级边；
 *  - unit_mapping(X→Y) 成立 = X 真实退出（或 id 复用显式覆盖）＋单位级出边唯一
 *    ＋每个消失下级都有出边且落在 Y 新版子树内＋X 无 exceptions。
 */
class ChangesGraph
{
    public const SIDE_OLD = 'old';

    public const SIDE_NEW = 'new';

    public const STATE_APPEARED = 'appeared';

    public const STATE_RETIRED = 'retired';

    public const STATE_CONTINUED = 'continued';

    /** continued node 可声明的属性变化字段（字段名清单，值从两版 csv 派生） */
    public const ATTRIBUTE_KEYS = ['name', 'ext_name', 'pid'];

    /** @var list<array<string, mixed>> */
    protected array $nodes = [];

    /** @var list<array<string, mixed>> */
    protected array $edges = [];

    /** @var array<string, array<string, mixed>> "side:id" => node */
    protected array $nodeIndex = [];

    /** @var array<int, list<int>> from_id => [edge 下标] */
    protected array $edgeIndexByFrom = [];

    /** @var array<int, list<int>> to_id => [edge 下标] */
    protected array $edgeIndexByTo = [];

    /** @var array<int, bool>|null 单位级边缓存（edge 下标 => bool） */
    protected ?array $unitLevelCache = null;

    /** @var array<string, array{title: string, url: string}> 文件级证据池（引用 id => 条目） */
    protected array $evidencePool = [];

    /**
     * @param  list<array<string, mixed>>  $changes  changes.json 的 changes 数组（须已过 schema 层）
     * @param  array<int, array<string, mixed>>  $oldMap  旧基线 csv（id => row）
     * @param  array<int, array<string, mixed>>  $newMap  新版 csv（id => row）
     * @param  array<string, mixed>  $evidencePool  changes.json 顶层 evidence 证据池（引用 id => {title, url}）
     */
    public function __construct(
        array $changes,
        public readonly array $oldMap,
        public readonly array $newMap,
        array $evidencePool = [],
    ) {
        foreach ($evidencePool as $key => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $this->evidencePool[(string) $key] = [
                'title' => (string) ($entry['title'] ?? ''),
                'url' => (string) ($entry['url'] ?? ''),
            ];
        }

        foreach ($changes as $i => $item) {
            if (! is_array($item)) {
                continue;
            }

            if (($item['kind'] ?? null) === 'node') {
                $state = (string) ($item['state'] ?? '');
                $node = [
                    '_index' => $i,
                    // v3：side 由 state 派生（retired→old、appeared→new、continued 约定 old）
                    'side' => match ($state) {
                        self::STATE_RETIRED => self::SIDE_OLD,
                        self::STATE_APPEARED => self::SIDE_NEW,
                        default => self::SIDE_OLD,
                    },
                    'id' => (int) ($item['id'] ?? 0),
                    'state' => $state,
                    // v3：字段名清单（值用时从两版 csv 取）
                    'attributes' => array_values(array_map(
                        fn (mixed $f): string => (string) $f,
                        is_array($item['attributes'] ?? null) ? $item['attributes'] : [],
                    )),
                    'id_reuse' => (bool) ($item['id_reuse'] ?? false),
                    'exceptions' => array_values(array_map(
                        fn (mixed $e): int => (int) (is_array($e) ? ($e['id'] ?? 0) : 0),
                        is_array($item['exceptions'] ?? null) ? $item['exceptions'] : [],
                    )),
                    'summary' => isset($item['summary']) ? (string) $item['summary'] : null,
                    'evidence' => $this->parseEvidenceRefs($item),
                    'raw' => $item,
                ];
                $this->nodes[] = $node;
                $this->nodeIndex[$node['side'].':'.$node['id']] = $node;

                continue;
            }

            if (($item['kind'] ?? null) === 'edge') {
                $edge = [
                    '_index' => $i,
                    'from' => (int) ($item['from_id'] ?? 0),
                    'to' => (int) ($item['to_id'] ?? 0),
                    'cross_level_reason' => isset($item['cross_level_reason']) ? (string) $item['cross_level_reason'] : null,
                    'summary' => isset($item['summary']) ? (string) $item['summary'] : null,
                    'evidence' => $this->parseEvidenceRefs($item),
                    'raw' => $item,
                ];
                $this->edges[] = $edge;
                $edgeIdx = count($this->edges) - 1;
                $this->edges[$edgeIdx]['_e'] = $edgeIdx;
                $this->edgeIndexByFrom[$edge['from']][] = $edgeIdx;
                $this->edgeIndexByTo[$edge['to']][] = $edgeIdx;
            }
        }

        // v3：下级边不写 evidence 时自动继承其单位级边的 evidence 与 summary
        // （下级边与所属单位级边是同一行政行为的产物；单位级边必须自证）
        foreach ($this->edges as $i => $edge) {
            if ($edge['evidence'] !== [] && ($edge['summary'] ?? null) !== null) {
                continue;
            }
            $top = $this->topmostFromAncestor($edge['from']);
            if ($top === null) {
                continue; // 单位级边（或独立边）：无上级可继承，自证要求由校验器检查
            }
            $unit = $this->edges[$top];
            if ($edge['evidence'] === []) {
                $edge['evidence'] = $unit['evidence'];
            }
            if (($edge['summary'] ?? null) === null) {
                $edge['summary'] = $unit['summary'];
            }
            $this->edges[$i] = $edge;
        }
    }

    /**
     * 解析证据池引用为条目清单（生成 records 时取第一条）。
     *
     * @param  list<string>  $refs
     * @return list<array{title: string, url: string}>
     */
    public function evidenceOf(array $refs): array
    {
        $entries = [];
        foreach ($refs as $ref) {
            if (isset($this->evidencePool[$ref])) {
                $entries[] = $this->evidencePool[$ref];
            }
        }

        return $entries;
    }

    /** @return array<string, array{title: string, url: string}> */
    public function evidencePool(): array
    {
        return $this->evidencePool;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    protected function parseEvidenceRefs(array $item): array
    {
        return array_values(array_map(
            fn (mixed $r): string => (string) $r,
            is_array($item['evidence'] ?? null) ? $item['evidence'] : [],
        ));
    }

    /** @return list<array<string, mixed>> */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /** @return list<array<string, mixed>> */
    public function edges(): array
    {
        return $this->edges;
    }

    /** @return array<string, mixed>|null */
    public function node(string $side, int $id): ?array
    {
        return $this->nodeIndex[$side.':'.$id] ?? null;
    }

    /** @return list<array<string, mixed>> 以 id 为 from 的边（出边） */
    public function edgesFrom(int $id): array
    {
        return array_map(fn (int $i): array => $this->edges[$i], $this->edgeIndexByFrom[$id] ?? []);
    }

    /** @return list<array<string, mixed>> 以 id 为 to 的边（入边） */
    public function edgesTo(int $id): array
    {
        return array_map(fn (int $i): array => $this->edges[$i], $this->edgeIndexByTo[$id] ?? []);
    }

    /**
     * 某 id 在指定侧 csv 内的全部下级（递归，不含自身）。
     *
     * @param  self::SIDE_OLD|self::SIDE_NEW  $side
     * @return list<int>
     */
    public function descendantsOf(int $id, string $side): array
    {
        $map = $side === self::SIDE_OLD ? $this->oldMap : $this->newMap;

        $childrenOf = [];
        foreach ($map as $row) {
            $childrenOf[(int) $row['pid']][] = (int) $row['id'];
        }

        $result = [];
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
     * I7 复用检测：同一 id 被旧侧消费（边的 from 端点 / retired node）且被新侧产出
     * （边的 to 端点 / appeared node），且不是 continued 关联 ⇒ 返回复用 id 清单。
     *
     * @return array<int, array{consumed: bool, produced: bool}>
     */
    public function reusedIds(): array
    {
        $consumed = [];
        $produced = [];
        $continued = [];

        foreach ($this->nodes as $node) {
            if ($node['state'] === self::STATE_RETIRED && $node['side'] === self::SIDE_OLD) {
                $consumed[$node['id']] = true;
            }
            if ($node['state'] === self::STATE_APPEARED && $node['side'] === self::SIDE_NEW) {
                $produced[$node['id']] = true;
            }
            if ($node['state'] === self::STATE_CONTINUED) {
                $continued[$node['id']] = true;
            }
        }
        foreach ($this->edges as $edge) {
            $consumed[$edge['from']] = true;
            $produced[$edge['to']] = true;
        }

        $reused = [];
        foreach (array_keys($consumed) as $id) {
            if (isset($produced[$id]) && ! isset($continued[$id])) {
                $reused[$id] = ['consumed' => true, 'produced' => true];
            }
        }

        return $reused;
    }

    /**
     * id 复用是否已被显式声明覆盖：存在 node(new, A, id_reuse=true)，
     * 其中 A == id 或 A 是 id 在新版 csv 中的祖先（整族复用可在单位级一次声明，
     * 如三沙 460302 的声明覆盖其下级 460302000）。
     */
    public function isReuseCovered(int $id): bool
    {
        $current = $id;
        $guard = 0;
        while ($guard++ < 8) {
            $node = $this->node(self::SIDE_NEW, $current);
            if ($node !== null && $node['id_reuse']) {
                return true;
            }
            $parent = $this->newMap[$current]['pid'] ?? null;
            if ($parent === null || (int) $parent === 0 || (int) $parent === $current) {
                return false;
            }
            $current = (int) $parent;
        }

        return false;
    }

    /**
     * 单位级边判定：from 的旧版祖先链（不含 from 本身）上不存在任何边的 from。
     * 下级边（如"渝北 5 镇去北碚"）的祖先链上必有本单位的单位级出边。
     *
     * @param  array<string, mixed>  $edge
     */
    public function isUnitLevelEdge(array $edge): bool
    {
        $this->unitLevelCache ??= $this->classifyEdges();

        return $this->unitLevelCache[$edge['_e']] ?? true;
    }

    /**
     * unit_mapping(X→Y) 推导（旧语义 full_transfer=true）：返回不成立的原因清单，
     * 空数组 = 成立。成立条件：
     *  ⓪ X 真实退出新版（X ∉ 新版 csv），或 X 被 id 复用显式声明覆盖
     *     （否则是母体存续的析出新设，浅层值不可判定）；
     *  ① X 的单位级出边唯一，即为 X→Y；
     *  ② X 的每个消失下级（旧版有、新版无）都有出边，且 to 落在 Y 的新版子树内；
     *  ③ X 无 exceptions（有例外说明存在未交代的去向）。
     *
     * @return list<string> 未覆盖原因（人读）
     */
    public function unitMappingFailures(int $x, int $y): array
    {
        $failures = [];

        if (isset($this->newMap[$x]) && ! $this->isReuseCovered($x)) {
            $failures[] = "旧单位 {$x} 在新版仍存续（析出新设/部分划出），值等于旧单位 id 的浅层行归属不可判定";
        }

        $unitOut = array_values(array_filter(
            $this->edgesFrom($x),
            fn (array $e): bool => $this->isUnitLevelEdge($e),
        ));
        if (count($unitOut) !== 1) {
            $failures[] = '单位级出边不唯一（'.count($unitOut).' 条），无单一承继者';
        }

        $ySubtree = array_flip([$y, ...$this->descendantsOf($y, self::SIDE_NEW)]);
        foreach ($this->descendantsOf($x, self::SIDE_OLD) as $child) {
            if (isset($this->newMap[$child])) {
                continue; // 下级在新版仍在（含 id 复用仍在），不属于"消失下级"
            }
            $outs = $this->edgesFrom($child);
            if ($outs === []) {
                $failures[] = "消失下级 {$child} 无出边（去向未交代）";

                continue;
            }
            foreach ($outs as $out) {
                if (! isset($ySubtree[$out['to']])) {
                    $failures[] = "消失下级 {$child} 的去向 {$out['to']} 不在 {$y} 的新版子树内（疆域旁落）";
                }
            }
        }

        $node = $this->node(self::SIDE_OLD, $x);
        if ($node !== null && $node['exceptions'] !== []) {
            $failures[] = "旧单位 {$x} 存在 exceptions 例外下级（有未交代去向的下级）";
        }

        return $failures;
    }

    /**
     * 派生 change_type（人读标签，机器派生）——node。
     *
     * @param  array<string, mixed>  $node
     */
    public function nodeChangeType(array $node): string
    {
        if ($node['state'] === self::STATE_CONTINUED) {
            // name/ext_name 类变化优先于 pid（校验器统一约定的优先级）
            return array_intersect($node['attributes'], ['name', 'ext_name']) !== []
                ? AreaChange::TYPE_RENAME
                : AreaChange::TYPE_PARENT_CHANGE;
        }

        if ($node['state'] === self::STATE_RETIRED) {
            $unitOut = array_values(array_filter(
                $this->edgesFrom($node['id']),
                fn (array $e): bool => $this->isUnitLevelEdge($e),
            ));

            return match (true) {
                count($unitOut) === 0 => AreaChange::TYPE_ABOLISH,
                count($unitOut) >= 2 => AreaChange::TYPE_SPLIT_FROM,
                default => $this->edgeChangeType($unitOut[0]),
            };
        }

        // appeared
        $unitIn = array_values(array_filter(
            $this->edgesTo($node['id']),
            fn (array $e): bool => $this->isUnitLevelEdge($e),
        ));
        if ($unitIn !== []) {
            return $this->edgeChangeType($unitIn[0]);
        }
        if ($node['id_reuse'] && isset($this->oldMap[$node['id']])) {
            return AreaChange::TYPE_CODE_REUSE; // 废止复用：旧单位无承继，同码被新单位启用
        }

        return AreaChange::TYPE_ADD;
    }

    /**
     * 派生 change_type（人读标签，机器派生）——edge。
     *
     * @param  array<string, mixed>  $edge
     */
    public function edgeChangeType(array $edge): string
    {
        if (! $this->isUnitLevelEdge($edge)) {
            // 下级边随单位级边推导（取旧版祖先链上最顶层 from 祖先的单位级边）
            $top = $this->topmostFromAncestor($edge['from']);

            return $top !== null
                ? $this->edgeChangeType($this->edges[$top])
                : AreaChange::TYPE_CODE_CHANGE; // 不可达（下级边按定义必有 from 祖先），兜底
        }

        $from = $edge['from'];
        $to = $edge['to'];

        // from 存续（∈ 新版且非复用覆盖）⇒ 母体存续的析出新设
        if (isset($this->newMap[$from]) && ! $this->isReuseCovered($from)) {
            return AreaChange::TYPE_SPLIT_FROM;
        }

        $unitOut = array_values(array_filter(
            $this->edgesFrom($from),
            fn (array $e): bool => $this->isUnitLevelEdge($e),
        ));
        if (count($unitOut) >= 2) {
            return AreaChange::TYPE_SPLIT_FROM;
        }

        $unitIn = array_values(array_filter(
            $this->edgesTo($to),
            fn (array $e): bool => $this->isUnitLevelEdge($e),
        ));
        if (count($unitIn) >= 2) {
            return AreaChange::TYPE_MERGE_INTO; // 多源合并
        }

        // 出边唯一、入边唯一：覆盖条款成立 ⇒ 整族换码 code_change；旁落 ⇒ merge_into
        return $this->unitMappingFailures($from, $to) === []
            ? AreaChange::TYPE_CODE_CHANGE
            : AreaChange::TYPE_MERGE_INTO;
    }

    /**
     * 映射执行序拓扑排序（§2.5）：业务 remap 是 UPDATE ... SET col=to WHERE col=from，
     * 对与对之间不可交换——复用 id R 上必须满足 e_out(R) ≺ e_in(R)
     * （先腾空 R 上的旧数据，再迁入新数据）。
     *
     * @param  list<array{from: int, to: int}>  $pairs  待排序的映射对（含归档对）
     * @return list<int>|null 排序后的下标清单；约束成环（如 A→B、B→A 互换）返回 null
     */
    public function topoSortPairs(array $pairs): ?array
    {
        $fromIndex = [];
        $toIndex = [];
        foreach ($pairs as $i => $pair) {
            $fromIndex[$pair['from']][] = $i;
            $toIndex[$pair['to']][] = $i;
        }

        // 约束：同一 id 既是某对 from 又是某对 to ⇒ from 对必须先执行
        $after = []; // i => list<j>（i 必须先于 j）
        $indegree = array_fill(0, count($pairs), 0);
        foreach ($pairs as $i => $pair) {
            foreach ($toIndex[$pair['from']] ?? [] as $j) {
                if ($j === $i) {
                    continue;
                }
                $after[$i][] = $j;
                $indegree[$j]++;
            }
        }

        // Kahn；同层按下标升序，保证排序稳定（尽量保持文件书写顺序）
        $ready = [];
        foreach ($indegree as $i => $deg) {
            if ($deg === 0) {
                $ready[] = $i;
            }
        }
        sort($ready);

        $order = [];
        while ($ready !== []) {
            $i = array_shift($ready);
            $order[] = $i;
            foreach ($after[$i] ?? [] as $j) {
                if (--$indegree[$j] === 0) {
                    $ready[] = $j;
                }
            }
            sort($ready);
        }

        return count($order) === count($pairs) ? $order : null;
    }

    /**
     * 归档 id：90{原id}（废止复用归档段，见开发方案 §10.3）。
     */
    public static function archiveIdOf(int $id): int
    {
        return (int) ('90'.$id);
    }

    /**
     * 全部边按"单位级/下级"分类（祖先链判定，结果缓存）。
     *
     * @return array<int, bool> edge 下标 => 是否单位级
     */
    protected function classifyEdges(): array
    {
        $result = [];
        foreach ($this->edges as $i => $edge) {
            $result[$i] = $this->topmostFromAncestor($edge['from']) === null;
        }

        return $result;
    }

    /**
     * from 的旧版祖先链上最顶层的"是某条边 from"的祖先所在的边下标；无则 null。
     */
    protected function topmostFromAncestor(int $from): ?int
    {
        $top = null;
        $current = $from;
        $guard = 0;
        while ($guard++ < 8) {
            $parent = $this->oldMap[$current]['pid'] ?? null;
            if ($parent === null || (int) $parent === 0 || (int) $parent === $current) {
                break;
            }
            $parent = (int) $parent;
            if (isset($this->edgeIndexByFrom[$parent])) {
                $top = $this->edgeIndexByFrom[$parent][0];
            }
            $current = $parent;
        }

        return $top;
    }
}
