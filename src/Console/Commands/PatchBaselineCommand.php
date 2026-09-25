<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Console\Commands;

use Illuminate\Console\Command;
use Quansitech\Cmf\Area\Services\BaselinePatcher;
use Quansitech\Cmf\Area\Services\DiffService;
use Quansitech\Cmf\Area\Services\ImportService;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * area:patch-baseline — 把审定 changes.json 的变更打进基线 csv（升级方案 §7）。
 * 与 MigrationGenerator 构造 areas ops 同源（BaselinePatcher 消费其 payload）。
 * 落盘前跑自证校验：补丁后基线 vs 上游新版 csv 在 --scope 范围内的 diff 必须为 0
 * （非零说明基线与迁移对不上，阻断）；未给 --scope 时按全量校验。
 */
#[AsCommand(name: 'area:patch-baseline', description: 'changes.json 审定变更补丁进基线 csv')]
class PatchBaselineCommand extends Command
{
    protected $signature = 'area:patch-baseline
        {changes : 审定的 changes.json 路径（v3，须先通过 area:check-changes）}
        {--old= : 旧基线 csv（默认模块内置 database/data/ok_data_level4.csv）}
        {--new= : 上游新版 csv（insert 字段来源 + 自证校验对比对象，必填）}
        {--output= : 补丁后基线输出路径（默认覆盖 --old 指定文件）}
        {--scope= : 选中地区 id（逗号分隔）；自证校验只要求该范围 diff 为 0}';

    public function handle(BaselinePatcher $patcher, ImportService $import, DiffService $diffService): int
    {
        /** @var string $changesPath */
        $changesPath = $this->argument('changes');
        $changes = json_decode((string) file_get_contents($changesPath), true);
        if (! is_array($changes) || (int) ($changes['schema_version'] ?? 0) !== 3) {
            $this->components->error('changes.json 不是 v3 格式，请先通过 area:check-changes 校验');

            return self::FAILURE;
        }

        /** @var string $oldCsv */
        $oldCsv = $this->option('old') ?: dirname(__DIR__, 3).'/database/data/ok_data_level4.csv';
        /** @var string|null $newCsv */
        $newCsv = $this->option('new');
        if ($newCsv === null || ! is_file($newCsv)) {
            $this->components->error('必须用 --new 指定上游新版 csv（insert 字段来源 + 自证校验对比对象）');

            return self::FAILURE;
        }

        $scope = null;
        /** @var string|null $scopeOption */
        $scopeOption = $this->option('scope');
        if (is_string($scopeOption) && trim($scopeOption) !== '') {
            $scope = array_values(array_map('intval', array_filter(explode(',', $scopeOption))));
        }

        $oldMap = $import->loadCsvAsMap($oldCsv);
        $newMap = $import->loadCsvAsMap($newCsv);

        try {
            $patched = $patcher->patch($oldMap, $changes, $newMap);
        } catch (\Throwable $e) {
            $this->components->error("基线补丁失败：{$e->getMessage()}");

            return self::FAILURE;
        }

        // 自证校验：补丁后基线 vs 上游 csv 在 scope 内的 diff 必须为 0
        $check = $diffService->diff($patched, $newMap);
        $residual = [];
        foreach (['added', 'removed', 'renamed', 'parent_changed'] as $kind) {
            foreach ($check[$kind] as $row) {
                if ($scope !== null && ! $diffService->isInRegions((int) $row['id'], $scope, $patched, $newMap)) {
                    continue;
                }
                $residual[] = "{$kind} #{$row['id']}";
            }
        }

        if ($residual !== []) {
            $this->components->error('自证校验失败：补丁后基线与上游 csv 在范围内仍有 '.count($residual).' 条差异（基线与迁移对不上，已阻断）：');
            $this->components->bulletList(array_slice($residual, 0, 50));

            return self::FAILURE;
        }

        /** @var string $output */
        $output = $this->option('output') ?: $oldCsv;
        $patcher->writeCsv($patched, $output);

        $this->components->info("基线补丁已落盘：{$output}（".count($patched).' 行）');
        $this->components->info('自证校验通过：补丁后基线 vs 上游 csv 在'.($scope !== null ? '选中范围' : '全量').'内 diff 为 0。');

        return self::SUCCESS;
    }
}
