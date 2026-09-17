<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * area:check-upstream — 查询上游是否有新版本（不做自动轮询，人工按需触发）。
 */
#[AsCommand(name: 'area:check-upstream', description: '查询上游 AreaCity 数据是否有新版本')]
class CheckUpstreamCommand extends Command
{
    protected $signature = 'area:check-upstream';

    public function handle(): int
    {
        $current = (string) config('cmf-area.data_version');
        $repo = (string) config('cmf-area.upstream_repo');

        $response = Http::timeout(15)->get("https://api.github.com/repos/{$repo}/releases/latest");

        if (! $response->successful()) {
            $this->components->error("查询上游 Release 失败：HTTP {$response->status()}");

            return self::FAILURE;
        }

        $latest = (string) ($response->json('tag_name') ?? '');

        $this->components->twoColumnDetail('当前基线版本', $current);
        $this->components->twoColumnDetail('上游最新版本', $latest);

        if ($latest === $current) {
            $this->components->info('数据已是最新，无需升级。');

            return self::SUCCESS;
        }

        $this->components->warn("上游有新版本：{$latest}（当前 {$current}）");
        $this->components->bulletList([
            "下载新版数据：php artisan area:download {$latest}",
            '随后按 area/skill/SKILL.md 的升级流水线，由 AI agent 完成 diff → 判读 → 校验 → 生成迁移',
        ]);

        return self::SUCCESS;
    }
}
