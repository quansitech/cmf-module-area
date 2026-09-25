<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Console\Commands;

use Illuminate\Console\Command;
use Quansitech\Cmf\Area\Services\DiffRunner;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * area:diff {new_csv} — 新旧 csv 对比产出 diff.json（纯事实清单，按省分组）。
 * 不做变更类型推断（交给 AI 判读）；疑似代码重用按省分组上报，
 * 是否阻断由消费方按选中范围判定（升级方案 §8.2）。
 */
#[AsCommand(name: 'area:diff', description: '对比新旧 csv 生成 diff.json')]
class DiffCommand extends Command
{
    protected $signature = 'area:diff
        {new_csv : 新版 ok_data_level4.csv 路径}
        {--old= : 旧基线 csv 路径（默认模块内置 database/data/ok_data_level4.csv）}
        {--output= : diff.json 输出路径（默认与 new_csv 同目录 diff.json）}
        {--to-version= : 目标上游版本号（写入 diff.json，默认从文件名推断）}';

    public function handle(DiffRunner $runner): int
    {
        /** @var string $newCsv */
        $newCsv = $this->argument('new_csv');
        /** @var string $oldCsv */
        $oldCsv = $this->option('old') ?: dirname(__DIR__, 3).'/database/data/ok_data_level4.csv';
        /** @var string $output */
        $output = $this->option('output') ?: dirname($newCsv).'/diff.json';

        $payload = $runner->runToFile(
            $newCsv,
            $output,
            $oldCsv,
            (string) config('cmf-area.data_version'),
            ($toVersion = $this->option('to-version')) !== null ? (string) $toVersion : null,
        );

        $summary = $payload['summary'] ?? [];
        $this->components->twoColumnDetail('新增 added', (string) ($summary['added'] ?? 0));
        $this->components->twoColumnDetail('消失 removed', (string) ($summary['removed'] ?? 0));
        $this->components->twoColumnDetail('更名 renamed', (string) ($summary['renamed'] ?? 0));
        $this->components->twoColumnDetail('换父级 parent_changed', (string) ($summary['parent_changed'] ?? 0));
        $this->components->twoColumnDetail('疑似代码重用', (string) ($summary['code_reuse_suspected'] ?? 0));
        $this->components->info("diff.json 已生成：{$output}");

        if (($summary['code_reuse_suspected'] ?? 0) > 0) {
            $this->components->warn('检测到疑似代码重用（新增 id 命中历史废止行）：其所属地区被勾选处理时需走人工确认与归档迁移专项（SKILL.md）。');
        }

        $this->components->bulletList(['下一步：按 area/skill/SKILL.md 对每条 diff 联网取证判读，产出 changes.json']);

        return self::SUCCESS;
    }
}
