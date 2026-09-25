<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Services;

use Quansitech\Cmf\Area\Models\Area;

/**
 * 新旧 csv 对比 → diff.json（纯算法、确定性，不做变更类型推断）。
 *
 * 判定规则（见开发方案 §8）：
 *  - 新版有、旧版无 → added
 *  - 旧版有、新版无 → removed
 *  - id 相同、ext_name 不同 → renamed
 *  - id 相同、pid 不同 → parent_changed
 *  - added 的 id 命中历史 status=0 废止行 → code_reuse_suspected（硬规则，阻断转人工）
 */
class DiffService
{
    /**
     * @param  array<int, array<string, mixed>>  $oldMap  旧基线（id => row）
     * @param  array<int, array<string, mixed>>  $newMap  新版（id => row）
     * @param  array<int, mixed>|null  $retiredMap  历史废止行（cmf_areas status=0，代码重用检测用）
     * @return array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, renamed: list<array<string, mixed>>, parent_changed: list<array<string, mixed>>, code_reuse_suspected: list<array<string, mixed>>}
     */
    public function diff(array $oldMap, array $newMap, ?array $retiredMap = null): array
    {
        $added = [];
        $removed = [];
        $renamed = [];
        $parentChanged = [];
        $codeReuseSuspected = [];

        foreach ($newMap as $id => $row) {
            if (! isset($oldMap[$id])) {
                $added[] = $this->rowOf($row);

                // 代码重用检测：命中历史废止行一律上报（宁可误报不可放过）
                if ($retiredMap !== null && isset($retiredMap[$id])) {
                    $codeReuseSuspected[] = $this->rowOf($row) + [
                        'previous_ext_name' => $this->retiredName($retiredMap[$id]),
                    ];
                }

                continue;
            }

            $old = $oldMap[$id];

            if (($old['ext_name'] ?? null) !== ($row['ext_name'] ?? null)) {
                $renamed[] = [
                    'id' => $id,
                    'pid' => $row['pid'],
                    'deep' => $row['deep'],
                    'old_ext_name' => $old['ext_name'],
                    'new_ext_name' => $row['ext_name'],
                    'old_name' => $old['name'],
                    'new_name' => $row['name'],
                ];
            }

            if ((int) $old['pid'] !== (int) $row['pid']) {
                $parentChanged[] = [
                    'id' => $id,
                    'ext_name' => $row['ext_name'],
                    'old_pid' => (int) $old['pid'],
                    'new_pid' => (int) $row['pid'],
                ];
            }
        }

        foreach ($oldMap as $id => $row) {
            if (! isset($newMap[$id])) {
                $removed[] = $this->rowOf($row);
            }
        }

        return [
            'added' => $this->sortById($added),
            'removed' => $this->sortById($removed),
            'renamed' => $this->sortById($renamed),
            'parent_changed' => $this->sortById($parentChanged),
            'code_reuse_suspected' => $this->sortById($codeReuseSuspected),
        ];
    }

    /**
     * 从数据库加载历史废止行（status=0）作为代码重用检测底表。
     *
     * @return array<int, Area>
     */
    public function retiredAreasFromDb(): array
    {
        return Area::query()->where('status', 0)->get()->keyBy('id')->all();
    }

    /**
     * diff 结果按省（顶级 pid 链）分组，产出 diff.json 结构。
     *
     * @param  array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, renamed: list<array<string, mixed>>, parent_changed: list<array<string, mixed>>, code_reuse_suspected: list<array<string, mixed>>}  $diff
     * @param  array<int, array<string, mixed>>  $oldMap
     * @param  array<int, array<string, mixed>>  $newMap
     * @return array<string, mixed>
     */
    public function toJsonStructure(array $diff, array $oldMap, array $newMap, string $fromVersion, string $toVersion): array
    {
        $provinces = [];

        $group = function (array $rows) use (&$provinces, $oldMap, $newMap): void {
            foreach ($rows as $row) {
                $province = $this->provinceOf((int) $row['id'], $newMap) ?? $this->provinceOf((int) $row['id'], $oldMap);
                $provinces[$province] = true;
            }
        };

        foreach ($diff as $rows) {
            $group($rows);
        }

        $byProvince = [];
        foreach (array_keys($provinces) as $province) {
            $byProvince[$province] = [
                'added' => array_values(array_filter($diff['added'], fn (array $r): bool => $this->inProvince($r, $province, $newMap, $oldMap))),
                'removed' => array_values(array_filter($diff['removed'], fn (array $r): bool => $this->inProvince($r, $province, $oldMap, $newMap))),
                'renamed' => array_values(array_filter($diff['renamed'], fn (array $r): bool => $this->inProvince($r, $province, $newMap, $oldMap))),
                'parent_changed' => array_values(array_filter($diff['parent_changed'], fn (array $r): bool => $this->inProvince($r, $province, $newMap, $oldMap))),
                'code_reuse_suspected' => array_values(array_filter($diff['code_reuse_suspected'], fn (array $r): bool => $this->inProvince($r, $province, $newMap, $oldMap))),
            ];
        }

        ksort($byProvince);

        return [
            'from_version' => $fromVersion,
            'to_version' => $toVersion,
            'summary' => array_map('count', $diff),
            'blocked' => $diff['code_reuse_suspected'] !== [],
            'provinces' => $byProvince,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, array<string, mixed>>  $primary
     * @param  array<int, array<string, mixed>>  $fallback
     */
    protected function inProvince(array $row, string $province, array $primary, array $fallback): bool
    {
        $p = $this->provinceOf((int) $row['id'], $primary) ?? $this->provinceOf((int) $row['id'], $fallback);

        return $p === $province;
    }

    /**
     * 沿 pid 链向上找省级名称（deep=0）。
     *
     * @param  array<int, array<string, mixed>>  $map
     */
    public function provinceOf(int $id, array $map): ?string
    {
        $guard = 0;
        $node = $map[$id] ?? null;

        while ($node !== null && $guard++ < 6) {
            if ((int) $node['deep'] === 0) {
                return (string) $node['ext_name'];
            }
            $node = $map[(int) $node['pid']] ?? null;
        }

        return null;
    }

    /**
     * id 是否属于某地区（沿 pid 链向上，单 map 内判定，含 id == regionId 自身）。
     *
     * @param  array<int, array<string, mixed>>  $map
     */
    public function inSubtree(int $id, int $regionId, array $map): bool
    {
        $guard = 0;
        $current = $id;

        while ($guard++ < 8) {
            if ($current === $regionId) {
                return true;
            }
            $node = $map[$current] ?? null;
            if ($node === null) {
                return false;
            }
            $parent = (int) $node['pid'];
            if ($parent === 0 || $parent === $current) {
                return false;
            }
            $current = $parent;
        }

        return false;
    }

    /**
     * id 是否属于选中地区集合（旧版/新版任一 map 的子树命中即算——
     * 跨边界变更时事实可能只在一侧可见，升级方案 §8.1）。
     *
     * @param  list<int>  $regionIds
     * @param  array<int, array<string, mixed>>  $oldMap
     * @param  array<int, array<string, mixed>>  $newMap
     */
    public function isInRegions(int $id, array $regionIds, array $oldMap, array $newMap): bool
    {
        foreach ($regionIds as $regionId) {
            if ($this->inSubtree($id, (int) $regionId, $oldMap) || $this->inSubtree($id, (int) $regionId, $newMap)) {
                return true;
            }
        }

        return false;
    }

    /**
     * diff 结果按选中地区子树过滤（各地区分组与 summary 同步收缩）。
     *
     * @param  array<string, mixed>  $diffJson  toJsonStructure 产出的 diff.json 结构
     * @param  list<int>  $regionIds
     * @param  array<int, array<string, mixed>>  $oldMap
     * @param  array<int, array<string, mixed>>  $newMap
     * @return array<string, mixed>
     */
    public function scopeDiffToRegions(array $diffJson, array $regionIds, array $oldMap, array $newMap): array
    {
        $scoped = $diffJson;
        $scoped['provinces'] = [];
        $summary = ['added' => 0, 'removed' => 0, 'renamed' => 0, 'parent_changed' => 0, 'code_reuse_suspected' => 0];

        foreach (($diffJson['provinces'] ?? []) as $province => $groups) {
            $scopedGroups = [];
            $hasFact = false;
            foreach (['added', 'removed', 'renamed', 'parent_changed', 'code_reuse_suspected'] as $kind) {
                $rows = array_values(array_filter(
                    $groups[$kind] ?? [],
                    fn (array $row): bool => $this->isInRegions((int) $row['id'], $regionIds, $oldMap, $newMap),
                ));
                $scopedGroups[$kind] = $rows;
                $summary[$kind] += count($rows);
                $hasFact = $hasFact || $rows !== [];
            }
            if ($hasFact) {
                $scoped['provinces'][$province] = $scopedGroups;
            }
        }

        $scoped['summary'] = $summary;
        $scoped['blocked'] = $summary['code_reuse_suspected'] > 0;

        return $scoped;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function rowOf(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'pid' => (int) $row['pid'],
            'deep' => (int) $row['deep'],
            'name' => $row['name'],
            'ext_name' => $row['ext_name'],
        ];
    }

    protected function retiredName(mixed $area): string
    {
        return $area instanceof Area ? $area->ext_name : (string) ($area['ext_name'] ?? '');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function sortById(array $rows): array
    {
        usort($rows, fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return $rows;
    }
}
