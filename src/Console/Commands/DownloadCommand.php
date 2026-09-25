<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Console\Commands;

use Illuminate\Console\Command;
use Quansitech\Cmf\Area\Services\UpstreamService;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * area:download {version} — 下载上游 Release 的 ok_data_level3-4.csv.7z，
 * 解压取 ok_data_level4.csv（升级 skill 第 ① 步）。下载逻辑在 UpstreamService，
 * 与升级批次 Web 界面共用，本命令只是薄壳。
 */
#[AsCommand(name: 'area:download', description: '下载上游指定版本的 ok_data_level4.csv')]
class DownloadCommand extends Command
{
    protected $signature = 'area:download
        {version : 上游 Release tag（如 2026.xxxxx.xxxxxx）}
        {--output= : csv 输出路径（默认 storage/app/cmf-area/ok_data_level4_{version}.csv）}';

    public function handle(UpstreamService $upstream): int
    {
        /** @var string $version */
        $version = $this->argument('version');
        /** @var string|null $output */
        $output = $this->option('output');

        try {
            $path = $upstream->download($version, $output);
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("已下载并解压：{$path}");
        $this->components->bulletList([
            "下一步：php artisan area:diff {$path}",
        ]);

        return self::SUCCESS;
    }
}
