<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Console\Commands;

use Illuminate\Console\Command;
use Quansitech\Cmf\Area\Services\DiffService;
use Quansitech\Cmf\Area\Services\ImportService;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * area:diff {new_csv} — 新旧 csv 对比产出 diff.json（纯事实清单，按省分组）。
 * 不做变更类型推断（交给 AI 判读）；代码重用检测是硬规则，命中即阻断。
 */
#[AsCommand(name: 'area:diff', description: '对比新旧 csv 生成 diff.json')]
class DiffCommand extends Command
{
    protected $signature = 'area:diff
        {new_csv : 新版 ok_data_level4.csv 路径}
        {--old= : 旧基线 csv 路径（默认模块内置 database/data/ok_data_level4.csv）}
        {--output= : diff.json 输出路径（默认与 new_csv 同目录 diff.json）}
        {--to-version= : 目标上游版本号（写入 diff.json，默认从文件名推断）}';

    public function handle(ImportService $import, DiffService $diffService): int
    {
        /** @var string $newCsv */
        $newCsv = $this->argument('new_csv');
        /** @var string $oldCsv */
        $oldCsv = $this->option('old') ?: dirname(__DIR__, 3).'/database/data/ok_data_level4.csv';

        $oldMap = $import->loadCsvAsMap($oldCsv);
        $newMap = $import->loadCsvAsMap($newCsv);

        // 代码重用检测底表：csv 基线之外，还要叠加上历次迁移 retire 的历史废止行。
        // 开发侧通常未建库，此时仅用旧基线 csv 内不存在的 id 不做检测（数据库可用时才叠加）。
        $retiredMap = $this->retiredMapIfAvailable($diffService);

        $diff = $diffService->diff($oldMap, $newMap, $retiredMap);

        $fromVersion = (string) config('cmf-area.data_version');
        $toVersion = (string) ($this->option('to-version') ?: $this->guessVersion($newCsv));

        $payload = $diffService->toJsonStructure($diff, $oldMap, $newMap, $fromVersion, $toVersion);

        /** @var string $output */
        $output = $this->option('output') ?: dirname($newCsv).'/diff.json';
        file_put_contents($output, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->components->twoColumnDetail('新增 added', (string) count($diff['added']));
        $this->components->twoColumnDetail('消失 removed', (string) count($diff['removed']));
        $this->components->twoColumnDetail('更名 renamed', (string) count($diff['renamed']));
        $this->components->twoColumnDetail('换父级 parent_changed', (string) count($diff['parent_changed']));
        $this->components->twoColumnDetail('疑似代码重用', (string) count($diff['code_reuse_suspected']));
        $this->components->info("diff.json 已生成：{$output}");

        if ($diff['code_reuse_suspected'] !== []) {
            $this->components->error('检测到疑似代码重用（新增 id 命中历史废止行），流水线阻断：请按 SKILL.md 走人工确认与归档迁移专项。');

            return self::FAILURE;
        }

        $this->components->bulletList(['下一步：按 area/skill/SKILL.md 对每条 diff 联网取证判读，产出 changes.json']);

        return self::SUCCESS;
    }

    /**
     * 数据库可用时取历史废止行；开发侧未建库/未连接时返回 null（跳过检测）。
     *
     * @return array<int, mixed>|null
     */
    protected function retiredMapIfAvailable(DiffService $diffService): ?array
    {
        try {
            return $diffService->retiredAreasFromDb();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function guessVersion(string $path): string
    {
        if (preg_match('/(\d{4}\.\d{6}\.\d{6})/', basename($path), $m)) {
            return $m[1];
        }

        return 'unknown';
    }
}
