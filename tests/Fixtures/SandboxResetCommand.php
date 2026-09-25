<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Tests\Fixtures;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * 清空升级预览沙盒（storage/app/upgrade-sandbox）；
 * 下次访问页面时 AdminPanelProvider 自动重新播种基线
 * （默认上游最新版本的上一个 Release，失败回退 fixture 旧数据）。
 */
class SandboxResetCommand extends Command
{
    protected $signature = 'area:sandbox-reset';

    protected $description = '清空升级预览沙盒，下次访问自动重新播种基线（测试升级流程用）';

    public function handle(Filesystem $fs): int
    {
        $root = storage_path('app/upgrade-sandbox');

        if (is_dir($root)) {
            $fs->deleteDirectory($root);
        }

        $this->info("沙盒已清空：{$root}（下次访问自动重新播种）");

        return self::SUCCESS;
    }
}
