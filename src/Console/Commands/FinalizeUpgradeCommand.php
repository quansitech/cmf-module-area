<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Console\Commands;

use Illuminate\Console\Command;
use Quansitech\Cmf\Area\Services\UpgradeFinalizeService;
use Quansitech\Cmf\Area\Services\UpgradeWorkspace;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * area:finalize-upgrade — 一键定稿的 CLI 等价物（升级方案 §6.5），
 * 与 Web 定稿 tab 走同一个 UpgradeFinalizeService。
 */
#[AsCommand(name: 'area:finalize-upgrade', description: '一键定稿（合并→校验→补丁基线→定版本号→生成迁移）')]
class FinalizeUpgradeCommand extends Command
{
    protected $signature = 'area:finalize-upgrade';

    public function handle(UpgradeWorkspace $workspace, UpgradeFinalizeService $finalize): int
    {
        if (! $workspace->exists()) {
            $this->components->error('升级工作区不存在（先在升级界面发起升级）');

            return self::FAILURE;
        }

        try {
            [, $result] = $finalize->finalize($workspace->state());
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("定稿完成：新版本号 {$result['assigned_version']}".($result['aligned'] ? '（已自动对齐上游）' : '（部分地区发版，自有版本号）'));
        $this->components->info('PR 清单（人工接管 git）：');
        $this->components->bulletList($result['files']);

        return self::SUCCESS;
    }
}
