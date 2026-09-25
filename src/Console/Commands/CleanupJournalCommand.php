<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Console\Commands;

use Illuminate\Console\Command;
use Quansitech\Cmf\Area\Models\AreaMigrationJournal;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * area:cleanup-journal — 观察窗口确认无回滚需求后，清理指定版本的回滚日志
 * （cmf_area_migration_journal）。apply 报告末尾会输出本命令的清理提示；
 * 回滚成功后该版本日志即自动删除，无需手工清理。
 */
#[AsCommand(name: 'area:cleanup-journal', description: '清理指定版本的迁移回滚日志（确认无回滚需求后执行）')]
class CleanupJournalCommand extends Command
{
    protected $signature = 'area:cleanup-journal
        {version : 迁移版本号（如 2025.251231.260403）}';

    public function handle(): int
    {
        $version = (string) $this->argument('version');

        $count = AreaMigrationJournal::query()->where('version', $version)->count();
        if ($count === 0) {
            $this->components->info("版本 {$version} 无回滚日志（可能已回滚或已清理）。");

            return self::SUCCESS;
        }

        AreaMigrationJournal::query()->where('version', $version)->delete();

        $this->components->warn("已清理版本 {$version} 的回滚日志 {$count} 行——清理后该版本不可再自动回滚（仅可从数据库备份恢复）。");

        return self::SUCCESS;
    }
}
