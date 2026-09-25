<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Services;

use RuntimeException;

/**
 * 定稿动作（升级方案 §6.5，一键）：
 * 合并审定记录 → scoped 校验 → 试算补丁基线 → 全量 diff 定版本号（§3.3）
 * → 生成迁移 → 基线补丁落盘 → 自证校验 → 产出 PR 清单。
 *
 * 同一动作内三件套同源：迁移文件、补丁后基线、config 版本号（§5 规矩 3）。
 */
class UpgradeFinalizeService
{
    public function __construct(
        protected readonly UpgradeWorkspace $workspace,
        protected readonly UpgradeFlowService $flow,
        protected readonly ChangesValidator $validator,
        protected readonly MigrationGenerator $generator,
        protected readonly BaselinePatcher $patcher,
        protected readonly DiffService $diffService,
        protected readonly AreaConfigWriter $configWriter,
    ) {}

    /**
     * 一键定稿。任何一步失败抛 RuntimeException（状态原样保留，可修正后重试）。
     *
     * @param  array<string, mixed>  $state
     * @return array{0: array<string, mixed>, 1: array{assigned_version: string, aligned: bool, files: list<string>}}
     */
    public function finalize(array $state): array
    {
        if (($state['status'] ?? null) === UpgradeWorkspace::STATUS_GENERATED) {
            throw new RuntimeException('本次升级已定稿，不能重复生成');
        }

        $fromVersion = (string) $state['from_version'];
        $currentVersion = (string) config('cmf-area.data_version');
        if ($currentVersion !== $fromVersion) {
            throw new RuntimeException("基线版本已变化（当前 {$currentVersion} ≠ 本次升级的基线 {$fromVersion}），请重新发起升级");
        }

        $regionIds = $this->flow->selectedRegionIds($state);
        if ($regionIds === []) {
            throw new RuntimeException('尚未选择地区');
        }

        // ── 门禁：选中范围 100% 覆盖（审定口径，§6.5）──
        $coverage = $this->flow->coverage($state);
        if ($coverage['uncovered_final'] !== []) {
            $labels = array_map(fn (array $f): string => $f['label'], array_slice($coverage['uncovered_final'], 0, 20));

            throw new RuntimeException('生成门禁未通过：选中范围仍有 '.count($coverage['uncovered_final'])." 条事实未被审定记录认领：\n".implode("\n", $labels));
        }

        $diff = $this->flow->diff();
        [$oldMap, $newMap] = $this->flow->maps($state);

        // ── 合并审定记录 → 按选中范围过滤（§7：未选中地区一行不动；§8.1：跨边界边保留）──
        // 剔除"认领的 diff 事实全部在选中范围外"的审定记录（典型：误勾/顺路认领了
        // 完全不相干的省）。否则它们会随 payload 打进基线与迁移，污染未审定地区。
        $changes = $this->flow->buildChanges($state);
        if ($changes['changes'] === []) {
            throw new RuntimeException('没有任何审定通过的记录');
        }
        $changes['changes'] = $this->filterChangesToScope($changes['changes'], $regionIds, $oldMap, $newMap);
        if ($changes['changes'] === []) {
            throw new RuntimeException('审定记录全部落在选中范围之外，无可定稿内容');
        }

        $errors = $this->validator->validate($changes, $diff, $oldMap, $newMap, $regionIds);
        if ($errors !== []) {
            throw new RuntimeException("定稿校验未通过（".count($errors)." 条错误）：\n".implode("\n", array_slice($errors, 0, 30)));
        }

        // ── 试算补丁基线 → 全量 diff 定版本号（§3.3）──
        $payload = $this->generator->payload($changes, $oldMap, $newMap);
        $patched = $this->patcher->applyPayload($oldMap, $payload);

        $fullDiff = $this->diffService->diff($patched, $newMap);
        $aligned = $fullDiff['added'] === [] && $fullDiff['removed'] === []
            && $fullDiff['renamed'] === [] && $fullDiff['parent_changed'] === [];
        $assigned = $aligned
            ? (string) $state['target_upstream'] // 全量 diff 为零：自动回归上游语义
            : ChangesValidator::incrementVersion($fromVersion);

        if ($assigned !== $changes['version']) {
            $changes['version'] = $assigned;
            $payload = $this->generator->payload($changes, $oldMap, $newMap);
            $patched = $this->patcher->applyPayload($oldMap, $payload);
        }

        // ── 自证校验：补丁后基线 vs 上游 csv 限定选中范围 diff 必须为 0（§6.5）──
        // 判定用旧/新两版 map 而不是 patched map：撤地 id 在 patched 中已被删除，
        // 用 patched 会让"范围内 removed 事实"被误判为出范围，漏检补丁缺口
        $residual = [];
        foreach (['added', 'removed', 'renamed', 'parent_changed'] as $kind) {
            foreach ($fullDiff[$kind] as $row) {
                if ($this->diffService->isInRegions((int) $row['id'], $regionIds, $oldMap, $newMap)) {
                    $residual[] = "{$kind} #{$row['id']}";
                }
            }
        }
        if ($residual !== []) {
            throw new RuntimeException('自证校验失败：补丁后基线与上游 csv 在选中范围内仍有 '.count($residual).' 条差异（基线与迁移对不上，已阻断）：'.implode('、', array_slice($residual, 0, 20)));
        }

        // ── 落盘：迁移 + 补丁后基线 + changes 留档 + 基线快照 + config 版本号 ──
        $dataDir = $this->flow->dataDir();
        $files = $this->writeArtifacts($state, $changes, $payload, $patched, $dataDir);

        $this->configWriter->write('data_version', $assigned);
        $this->configWriter->write('upstream_base', (string) $state['target_upstream']);
        $files[] = $this->configWriter->configPath();

        $result = ['assigned_version' => $assigned, 'aligned' => $aligned, 'files' => $files];

        return [$this->workspace->saveState([
            'status' => UpgradeWorkspace::STATUS_GENERATED,
            'assigned_version' => $assigned,
            'finalize_result' => $result,
        ]), $result];
    }

    /**
     * 按选中范围过滤审定记录：保留"认领的 diff 事实与选中范围有交集"的记录。
     *
     * appeared/retired/continued node 认领 id 本身（added/removed/renamed/parent_changed），
     * edge 认领 from（旧侧）与 to（新侧），外加 node 的 exceptions 下级。全部出范围的记录剔除。
     *
     * @param  list<array<string, mixed>>  $changes  v3 记录
     * @param  list<int>  $regionIds
     * @param  array<int, array<string, mixed>>  $oldMap
     * @param  array<int, array<string, mixed>>  $newMap
     * @return list<array<string, mixed>>
     */
    protected function filterChangesToScope(array $changes, array $regionIds, array $oldMap, array $newMap): array
    {
        return array_values(array_filter($changes, function (array $item) use ($regionIds, $oldMap, $newMap): bool {
            $ids = [];
            if (($item['kind'] ?? null) === 'edge') {
                $ids[] = (int) ($item['from_id'] ?? 0);
                $ids[] = (int) ($item['to_id'] ?? 0);
            } else {
                $ids[] = (int) ($item['id'] ?? 0);
                foreach ((is_array($item['exceptions'] ?? null) ? $item['exceptions'] : []) as $ex) {
                    $ids[] = (int) ($ex['id'] ?? 0);
                }
            }

            foreach ($ids as $id) {
                if ($id !== 0 && $this->diffService->isInRegions($id, $regionIds, $oldMap, $newMap)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string, mixed>>  $patched
     * @return list<string> 落盘文件清单（git 视角 PR 清单）
     */
    protected function writeArtifacts(array $state, array $changes, array $payload, array $patched, string $dataDir): array
    {
        $assigned = (string) $changes['version'];
        $fromVersion = (string) $state['from_version'];
        $files = [];

        // 基线快照（重放链，§5 规矩 2）：from/to 两端各一份
        $snapshotsDir = $dataDir.'/baselines';
        if (! is_dir($snapshotsDir) && ! mkdir($snapshotsDir, 0755, true)) {
            throw new RuntimeException("无法创建目录：{$snapshotsDir}");
        }
        $oldCsv = $dataDir.'/ok_data_level4.csv';
        $fromSnapshot = $snapshotsDir."/baseline_{$fromVersion}.csv";
        if (! is_file($fromSnapshot)) {
            if (! is_file($oldCsv)) {
                throw new RuntimeException("旧基线 csv 不存在，无法留快照：{$oldCsv}");
            }
            copy($oldCsv, $fromSnapshot);
            $files[] = $fromSnapshot;
        }

        // 补丁后基线 → 当前基线 csv + to 快照
        $this->patcher->writeCsv($patched, $oldCsv);
        $files[] = $oldCsv;

        $toSnapshot = $snapshotsDir."/baseline_{$assigned}.csv";
        $this->patcher->writeCsv($patched, $toSnapshot);
        $files[] = $toSnapshot;

        // changes 留档
        $changesPath = $dataDir."/changes_{$assigned}.json";
        file_put_contents($changesPath, json_encode($changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $files[] = $changesPath;

        // 迁移文件
        $migrationsDir = dirname($dataDir).'/migrations/updates';
        if (! is_dir($migrationsDir) && ! mkdir($migrationsDir, 0755, true)) {
            throw new RuntimeException("无法创建目录：{$migrationsDir}");
        }
        $migrationPath = $migrationsDir.'/'.$this->generator->migrationFileName($assigned);
        file_put_contents($migrationPath, $this->generator->renderMigration($payload));
        $files[] = $migrationPath;

        return $files;
    }
}
