<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * area:download {version} — 下载上游 Release 的 ok_data_level3-4.csv.7z，
 * 解压取 ok_data_level4.csv 到临时目录（升级 skill 第 ① 步）。
 * 只下载不覆盖基线；基线替换发生在 PR 合并时（SKILL.md SOP 第 5 步）。
 */
#[AsCommand(name: 'area:download', description: '下载上游指定版本的 ok_data_level4.csv')]
class DownloadCommand extends Command
{
    protected $signature = 'area:download
        {version : 上游 Release tag（如 2026.xxxxx.xxxxxx）}
        {--output= : csv 输出路径（默认 storage/app/cmf-area/ok_data_level4_{version}.csv）}';

    public function handle(): int
    {
        /** @var string $version */
        $version = $this->argument('version');
        $repo = (string) config('cmf-area.upstream_repo');

        $workDir = storage_path('app/cmf-area');
        if (! is_dir($workDir) && ! mkdir($workDir, 0755, true)) {
            throw new RuntimeException("无法创建目录：{$workDir}");
        }

        $archive = $workDir."/ok_data_level3-4-{$version}.csv.7z";
        $url = "https://github.com/{$repo}/releases/download/{$version}/ok_data_level3-4.csv.7z";

        $this->components->task("下载 {$url}", function () use ($url, $archive): bool {
            $response = Http::timeout(120)->withOptions(['stream' => true])->get($url);
            if (! $response->successful()) {
                return false;
            }
            file_put_contents($archive, $response->body());

            return true;
        });

        if (! is_file($archive) || filesize($archive) === 0) {
            $this->components->error('下载失败或文件为空（确认版本号与网络可达性）');

            return self::FAILURE;
        }

        // 解压取 ok_data_level4.csv
        $output = $this->option('output') ?: $workDir."/ok_data_level4_{$version}.csv";
        $this->extractCsv($archive, (string) $output);

        $this->components->info("已下载并解压：{$output}");
        $this->components->bulletList([
            "下一步：php artisan area:diff {$output}",
        ]);

        return self::SUCCESS;
    }

    protected function extractCsv(string $archive, string $output): void
    {
        $sevenZip = trim((string) shell_exec('which 7z 2>/dev/null'));
        if ($sevenZip === '') {
            throw new RuntimeException('未安装 7z（p7zip-full），无法解压上游数据包');
        }

        $dir = dirname($archive);
        $cmd = sprintf('%s e %s -o%s -y ok_data_level4.csv 2>&1', escapeshellarg($sevenZip), escapeshellarg($archive), escapeshellarg($dir));
        exec($cmd, $out, $code);

        $extracted = $dir.'/ok_data_level4.csv';
        if ($code !== 0 || ! is_file($extracted)) {
            throw new RuntimeException('7z 解压失败：'.implode("\n", $out));
        }

        rename($extracted, $output);
    }
}
