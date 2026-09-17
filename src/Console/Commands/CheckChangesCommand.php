<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Console\Commands;

use Illuminate\Console\Command;
use Quansitech\Cmf\Area\Models\AreaChange;
use Quansitech\Cmf\Area\Services\ImportService;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * area:check-changes — AI 产出物 changes.json 的机器兜底校验（纯确定性，
 * 不联网、不做语义判断）。分两层：schema 层 + 逻辑层（见开发方案 §9.4）。
 * 校验报告的错误清单直接反馈给 agent 作为修正输入，直至全绿。
 */
#[AsCommand(name: 'area:check-changes', description: '校验 changes.json（schema + 逻辑）')]
class CheckChangesCommand extends Command
{
    protected $signature = 'area:check-changes
        {changes : changes.json 路径}
        {--diff= : diff.json 路径（逻辑层覆盖率校验用）}
        {--old= : 旧基线 csv（默认模块内置 database/data/ok_data_level4.csv）}
        {--new= : 新版 csv（id 存在性/child_id_map 配对校验用）}';

    /** @var list<string> */
    protected array $errors = [];

    public function handle(ImportService $import): int
    {
        /** @var string $changesPath */
        $changesPath = $this->argument('changes');
        $changes = json_decode((string) file_get_contents($changesPath), true);

        if (! is_array($changes)) {
            $this->components->error('changes.json 不是合法 JSON');

            return self::FAILURE;
        }

        $this->checkSchema($changes);
        $this->checkLogic($changes, $import);

        if ($this->errors === []) {
            $this->components->info('校验通过：schema 与逻辑校验全部绿灯。');

            return self::SUCCESS;
        }

        $this->components->error(sprintf('校验未通过（%d 条错误）：', count($this->errors)));
        $this->components->bulletList($this->errors);

        return self::FAILURE;
    }

    /**
     * schema 层：字段齐全、类型正确、枚举取值、evidence 非空、
     * merge_into 必须含 full_transfer。
     *
     * @param  array<string, mixed>  $changes
     */
    protected function checkSchema(array $changes): void
    {
        if (! isset($changes['version']) || ! is_string($changes['version'])) {
            $this->errors[] = 'version 缺失或不是字符串';
        }

        if (! isset($changes['changes']) || ! is_array($changes['changes'])) {
            $this->errors[] = 'changes 缺失或不是数组';

            return;
        }

        foreach ($changes['changes'] as $i => $change) {
            $label = "changes[{$i}]";
            if (! is_array($change)) {
                $this->errors[] = "{$label} 不是对象";

                continue;
            }

            $type = $change['change_type'] ?? null;
            if (! in_array($type, AreaChange::TYPES, true)) {
                $this->errors[] = "{$label}.change_type 非法：".var_export($type, true).'，枚举：'.implode('/', AreaChange::TYPES);
            }

            foreach (['old_id', 'new_id'] as $field) {
                if (array_key_exists($field, $change) && $change[$field] !== null && ! is_int($change[$field])) {
                    $this->errors[] = "{$label}.{$field} 必须是 int 或 null";
                }
            }

            // 每条至少一条 evidence（title + url）
            $evidence = $change['evidence'] ?? null;
            if (! is_array($evidence) || $evidence === []) {
                $this->errors[] = "{$label}.evidence 至少需要一条 {title, url}（禁止编造，查不到标 confidence: low）";
            } else {
                foreach ($evidence as $j => $e) {
                    if (! is_array($e) || ! is_string($e['title'] ?? null) || ! is_string($e['url'] ?? null)) {
                        $this->errors[] = "{$label}.evidence[{$j}] 缺 title 或 url";
                    }
                }
            }

            // merge_into 必须含 full_transfer
            if ($type === AreaChange::TYPE_MERGE_INTO && ! array_key_exists('full_transfer', $change['detail'] ?? [])) {
                $this->errors[] = "{$label}（merge_into）detail 必须给出 full_transfer（旧区是否 100% 疆域并入单一承继者）";
            }

            if (isset($change['confidence']) && ! in_array($change['confidence'], ['high', 'low'], true)) {
                $this->errors[] = "{$label}.confidence 仅可取 high / low";
            }
        }
    }

    /**
     * 逻辑层：与 diff.json、新旧两版 csv 交叉核对。
     *
     * @param  array<string, mixed>  $changes
     */
    protected function checkLogic(array $changes, ImportService $import): void
    {
        /** @var string|null $diffPath */
        $diffPath = $this->option('diff');
        /** @var string $oldCsv */
        $oldCsv = $this->option('old') ?: dirname(__DIR__, 3).'/database/data/ok_data_level4.csv';
        /** @var string|null $newCsv */
        $newCsv = $this->option('new');

        $oldMap = is_file($oldCsv) ? $import->loadCsvAsMap($oldCsv) : [];
        $newMap = $newCsv !== null && is_file($newCsv) ? $import->loadCsvAsMap($newCsv) : [];

        $this->checkIdExistence($changes, $oldMap, $newMap);
        $this->checkTypeConsistency($changes, $oldMap, $newMap);

        if ($diffPath !== null && is_file($diffPath)) {
            $diff = json_decode((string) file_get_contents($diffPath), true);
            if (is_array($diff)) {
                $this->checkCoverage($changes, $diff);
                $this->checkVersion($changes, $diff);
            }
        }

        if ($oldMap !== [] && $newMap !== []) {
            $this->checkChildIdMap($changes, $oldMap, $newMap);
        }
    }

    /**
     * 覆盖率：diff.json 每个 added/removed/renamed/parent_changed 的 id 必须
     * 恰好被一条 change 认领（old_id/new_id 或 child_id_map 的键值两侧）；
     * 不允许无人认领，也不允许凭空捏造 diff 之外的 id。
     *
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $diff
     */
    protected function checkCoverage(array $changes, array $diff): void
    {
        $claimed = [];
        /** @var array<string, mixed> $change */
        foreach ($changes['changes'] ?? [] as $change) {
            foreach (['old_id', 'new_id'] as $field) {
                if (isset($change[$field])) {
                    $claimed[(int) $change[$field]] = true;
                }
            }
            /** @var array<string|int, string|int> $childMap */
            $childMap = $change['detail']['child_id_map'] ?? [];
            foreach ($childMap as $oldChild => $newChild) {
                $claimed[(int) $oldChild] = true;
                $claimed[(int) $newChild] = true;
            }
            /** @var list<array<string, mixed>> $unmatched */
            $unmatched = $change['detail']['unmatched'] ?? [];
            foreach ($unmatched as $u) {
                if (isset($u['id'])) {
                    $claimed[(int) $u['id']] = true;
                }
            }
        }

        $facts = [];
        foreach (($diff['provinces'] ?? []) as $province => $groups) {
            foreach (['added', 'removed', 'renamed', 'parent_changed'] as $kind) {
                foreach ($groups[$kind] ?? [] as $row) {
                    $facts[(int) $row['id']] = "{$province}/{$kind} #{$row['id']}";
                }
            }
        }

        foreach ($facts as $id => $label) {
            if (! isset($claimed[$id])) {
                $this->errors[] = "覆盖率：diff 事实 {$label} 未被任何 change 认领";
            }
        }
    }

    /**
     * id 存在性：old_id 必须存在于旧基线 csv；new_id 必须存在于新版 csv。
     *
     * @param  array<string, mixed>  $changes
     * @param  array<int, array<string, mixed>>  $oldMap
     * @param  array<int, array<string, mixed>>  $newMap
     */
    protected function checkIdExistence(array $changes, array $oldMap, array $newMap): void
    {
        foreach ($changes['changes'] ?? [] as $i => $change) {
            $type = $change['change_type'] ?? '';

            if (isset($change['old_id']) && $oldMap !== [] && ! isset($oldMap[(int) $change['old_id']])) {
                $this->errors[] = "changes[{$i}].old_id {$change['old_id']} 不存在于旧基线 csv";
            }

            if (isset($change['new_id']) && $newMap !== [] && ! isset($newMap[(int) $change['new_id']])
                && ! in_array($type, [AreaChange::TYPE_MERGE_INTO, AreaChange::TYPE_CODE_CHANGE], true)) {
                // merge/code_change 的 new_id 允许是被并入后仍存在的已有 id，必须在新版
                $this->errors[] = "changes[{$i}].new_id {$change['new_id']} 不存在于新版 csv";
            }
        }
    }

    /**
     * 类型与事实一致：rename 的 old_id==new_id 且仅名称变化；
     * split_from 的 new_id ∈ added；merge_into/abolish 的 old_id ∈ removed（以 csv 交叉核对）；
     * parent_change 的 id 两版均在且 pid 不同。
     *
     * @param  array<string, mixed>  $changes
     * @param  array<int, array<string, mixed>>  $oldMap
     * @param  array<int, array<string, mixed>>  $newMap
     */
    protected function checkTypeConsistency(array $changes, array $oldMap, array $newMap): void
    {
        foreach ($changes['changes'] ?? [] as $i => $change) {
            $type = $change['change_type'] ?? '';
            $oldId = isset($change['old_id']) ? (int) $change['old_id'] : null;
            $newId = isset($change['new_id']) ? (int) $change['new_id'] : null;

            switch ($type) {
                case AreaChange::TYPE_RENAME:
                    if ($oldId !== $newId) {
                        $this->errors[] = "changes[{$i}]（rename）要求 old_id == new_id";
                    }
                    if ($oldId !== null && $oldMap !== [] && $newMap !== []
                        && ($oldMap[$oldId]['ext_name'] ?? null) === ($newMap[$oldId]['ext_name'] ?? null)) {
                        $this->errors[] = "changes[{$i}]（rename）两版 ext_name 无变化，与事实不符";
                    }
                    break;

                case AreaChange::TYPE_SPLIT_FROM:
                    if ($newId !== null && $oldMap !== [] && isset($oldMap[$newId])) {
                        $this->errors[] = "changes[{$i}]（split_from）new_id {$newId} 已存在于旧基线，应为新增 id";
                    }
                    break;

                case AreaChange::TYPE_MERGE_INTO:
                case AreaChange::TYPE_ABOLISH:
                    if ($oldId !== null && $newMap !== [] && isset($newMap[$oldId])) {
                        $this->errors[] = "changes[{$i}]（{$type}）old_id {$oldId} 仍存在于新版，应为 removed id";
                    }
                    break;

                case AreaChange::TYPE_PARENT_CHANGE:
                    $id = $newId ?? $oldId;
                    if ($id !== null && $oldMap !== [] && $newMap !== []) {
                        if (! isset($oldMap[$id], $newMap[$id])) {
                            $this->errors[] = "changes[{$i}]（parent_change）id {$id} 须两版均在";
                        } elseif ((int) $oldMap[$id]['pid'] === (int) $newMap[$id]['pid']) {
                            $this->errors[] = "changes[{$i}]（parent_change）id {$id} 两版 pid 相同，与事实不符";
                        }
                    }
                    break;
            }
        }
    }

    /**
     * child_id_map 配对完整性：被 diff 触及的下级必须配对——
     * 旧版 old_id 的下级中已消失（removed）的必须出现在 key 侧，
     * 新版 new_id 的下级中新增（added）的必须出现在值侧；
     * 未配对项必须列入 detail.unmatched 并说明原因（如某镇同期被撤并），否则不通过。
     * 未触及的下级（如析出新设后仍留在原单位的镇）无需出现在 map 中。
     *
     * @param  array<string, mixed>  $changes
     * @param  array<int, array<string, mixed>>  $oldMap
     * @param  array<int, array<string, mixed>>  $newMap
     */
    protected function checkChildIdMap(array $changes, array $oldMap, array $newMap): void
    {
        foreach ($changes['changes'] ?? [] as $i => $change) {
            $type = $change['change_type'] ?? '';
            if (! in_array($type, [AreaChange::TYPE_SPLIT_FROM, AreaChange::TYPE_MERGE_INTO, AreaChange::TYPE_CODE_CHANGE], true)) {
                continue;
            }

            $oldId = isset($change['old_id']) ? (int) $change['old_id'] : null;
            $newId = isset($change['new_id']) ? (int) $change['new_id'] : null;
            /** @var array<string|int, string|int> $map */
            $map = $change['detail']['child_id_map'] ?? [];
            $mapKeys = array_map('intval', array_keys($map));
            $mapValues = array_map('intval', array_values($map));
            /** @var list<int> $unmatched */
            $unmatched = array_map(
                fn (array $u): int => (int) $u['id'],
                array_filter($change['detail']['unmatched'] ?? [], fn (mixed $u): bool => is_array($u) && isset($u['id'], $u['reason'])),
            );

            if ($newId !== null) {
                foreach ($this->childrenOf($newId, $newMap) as $childId) {
                    if (isset($oldMap[$childId])) {
                        continue; // 两版均在的下级未受变更触及，无需配对
                    }
                    if (! in_array($childId, $mapValues, true) && ! in_array($childId, $unmatched, true)) {
                        $this->errors[] = "changes[{$i}] child_id_map 配对不完整：新版 {$newId} 的新增下级 {$childId} 未出现在 map 值侧（如系同期新设/无法配对，需列入 detail.unmatched 并说明原因）";
                    }
                }
            }

            if ($oldId !== null) {
                foreach ($this->childrenOf($oldId, $oldMap) as $childId) {
                    if (isset($newMap[$childId])) {
                        continue; // 仍留在旧单位的下级无需配对
                    }
                    if (! in_array($childId, $mapKeys, true) && ! in_array($childId, $unmatched, true)) {
                        $this->errors[] = "changes[{$i}] child_id_map 配对不完整：旧版 {$oldId} 的消失下级 {$childId} 未出现在 map key 侧（如系同期撤并，需列入 detail.unmatched 并说明原因）";
                    }
                }
            }
        }
    }

    /**
     * 版本一致：changes.json 的 version 与 diff.json 的目标版本一致。
     *
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $diff
     */
    protected function checkVersion(array $changes, array $diff): void
    {
        if (isset($diff['to_version']) && ($changes['version'] ?? null) !== $diff['to_version']) {
            $this->errors[] = "版本不一致：changes.json 的 version（{$changes['version']}）≠ diff.json 的 to_version（{$diff['to_version']}）";
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $map
     * @return list<int>
     */
    protected function childrenOf(int $id, array $map): array
    {
        $children = [];
        foreach ($map as $row) {
            if ((int) $row['pid'] === $id) {
                $children[] = (int) $row['id'];
            }
        }

        return $children;
    }
}
