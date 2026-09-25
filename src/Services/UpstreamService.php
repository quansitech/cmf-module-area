<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * 上游 AreaCity 数据源的下载/查询服务（DownloadCommand / CheckUpstreamCommand
 * 与升级批次 Web 界面共用；命令只是薄壳）。
 */
class UpstreamService
{
    /**
     * 查询上游最新 Release tag；失败返回 null。
     */
    public function latestTag(): ?string
    {
        $repo = (string) config('cmf-area.upstream_repo');
        $response = Http::timeout(15)->get("https://api.github.com/repos/{$repo}/releases/latest");

        if (! $response->successful()) {
            return null;
        }

        $tag = $response->json('tag_name');

        return is_string($tag) && $tag !== '' ? $tag : null;
    }

    /**
     * 列出上游可选 Release tag（仅日期版本号，新→旧）；失败返回空数组。结果缓存 5 分钟。
     *
     * @return array<int, string>
     */
    public function versions(): array
    {
        $repo = (string) config('cmf-area.upstream_repo');

        return Cache::remember('cmf-area.upstream-versions', 300, function () use ($repo): array {
            try {
                $response = Http::timeout(15)->get("https://api.github.com/repos/{$repo}/releases?per_page=100");
            } catch (\Throwable) {
                return [];
            }

            if (! $response->successful()) {
                return [];
            }

            return collect($response->json() ?? [])
                ->pluck('tag_name')
                ->filter(fn ($tag): bool => is_string($tag) && preg_match('/^\d{4}\.\d{6}\.\d{6}$/', $tag) === 1)
                ->unique()
                ->sortDesc()
                ->values()
                ->all();
        });
    }

    /**
     * 下载上游指定版本的 ok_data_level3-4.csv.7z 并解压出 level4 csv。
     *
     * @return string 解压后的 csv 路径
     */
    public function download(string $version, ?string $output = null, ?string $workDir = null): string
    {
        $repo = (string) config('cmf-area.upstream_repo');
        $workDir ??= storage_path('app/cmf-area');
        if (! is_dir($workDir) && ! mkdir($workDir, 0755, true)) {
            throw new RuntimeException("无法创建目录：{$workDir}");
        }

        $archive = $workDir."/ok_data_level3-4-{$version}.csv.7z";
        $url = "https://github.com/{$repo}/releases/download/{$version}/ok_data_level3-4.csv.7z";

        $response = Http::timeout(120)->withOptions(['stream' => true])->get($url);
        if ($response->successful()) {
            file_put_contents($archive, $response->body());
        }

        if (! is_file($archive) || filesize($archive) === 0) {
            throw new RuntimeException("下载失败或文件为空（确认版本号与网络可达性）：{$url}");
        }

        $output ??= $workDir."/ok_data_level4_{$version}.csv";
        $this->extractCsv($archive, $output);

        return $output;
    }

    /**
     * 从 7z 包中解压 ok_data_level4.csv 到指定路径。
     */
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

        if ($extracted !== $output && ! rename($extracted, $output)) {
            throw new RuntimeException("无法移动解压产物到 {$output}");
        }
    }
}
