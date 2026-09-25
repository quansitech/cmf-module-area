<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Console\Commands;

use Illuminate\Console\Command;
use Quansitech\Cmf\Area\Services\DiffRunner;
use Quansitech\Cmf\Area\Services\UpstreamService;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * area:check-upstream — 查询上游是否有新版本（升级方案 §13.6 语义）：
 *  - tag 与当前 data_version 相等（已对齐）→ 免下载直接报"已对齐最新"；
 *  - 不等（含部分对齐的自有版本号）→ 自动 download + diff，报告真实事实数，
 *    该预检同时就是"基线 vs 上游最新的待办清单"。
 */
#[AsCommand(name: 'area:check-upstream', description: '查询上游 AreaCity 数据是否有新版本')]
class CheckUpstreamCommand extends Command
{
    protected $signature = 'area:check-upstream
        {--no-diff : 只做 tag 比较，不自动下载 diff}';

    public function handle(UpstreamService $upstream, DiffRunner $runner): int
    {
        $current = (string) config('cmf-area.data_version');

        $latest = $upstream->latestTag();
        if ($latest === null) {
            $this->components->error('查询上游 Release 失败（网络或 GitHub API 不可达）');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('当前基线版本', $current);
        $this->components->twoColumnDetail('上游最新版本', $latest);

        $upstreamBase = config('cmf-area.upstream_base');
        if (is_string($upstreamBase) && $upstreamBase !== '' && $upstreamBase !== $latest) {
            $this->components->twoColumnDetail('最近对比的上游版本', $upstreamBase);
        }

        if ($latest === $current) {
            $this->components->info('数据已对齐上游最新版本，无需升级。');

            return self::SUCCESS;
        }

        if ($this->option('no-diff')) {
            $this->components->warn("上游有新版本：{$latest}（当前 {$current}，部分对齐模式下版本号不可直接比较）");

            return self::SUCCESS;
        }

        // tag 不等 ≠ 有真实差异（自有版本号模型）：download + diff 显示真实事实数
        try {
            $csv = $upstream->download($latest);
            $payload = $runner->runToFile($csv, dirname($csv).'/diff.json', null, $current, $latest);
        } catch (\Throwable $e) {
            $this->components->error("下载/diff 失败：{$e->getMessage()}");

            return self::FAILURE;
        }

        $summary = $payload['summary'] ?? [];
        $total = (int) ($summary['added'] ?? 0) + (int) ($summary['removed'] ?? 0)
            + (int) ($summary['renamed'] ?? 0) + (int) ($summary['parent_changed'] ?? 0);

        if ($total === 0) {
            $this->components->info("基线与上游 {$latest} 内容一致（0 条事实差异），可直接对齐版本号。");

            return self::SUCCESS;
        }

        $this->components->warn("基线 vs 上游 {$latest}：共 {$total} 条待处理事实");
        $this->components->twoColumnDetail('新增 added', (string) ($summary['added'] ?? 0));
        $this->components->twoColumnDetail('消失 removed', (string) ($summary['removed'] ?? 0));
        $this->components->twoColumnDetail('更名 renamed', (string) ($summary['renamed'] ?? 0));
        $this->components->twoColumnDetail('换父级 parent_changed', (string) ($summary['parent_changed'] ?? 0));
        $this->components->twoColumnDetail('疑似代码重用', (string) ($summary['code_reuse_suspected'] ?? 0));
        $this->components->bulletList([
            '在升级管理界面创建批次处理，或按 area/skill/SKILL.md 的 CLI 流水线判读',
        ]);

        return self::SUCCESS;
    }
}
