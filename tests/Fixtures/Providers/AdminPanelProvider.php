<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Tests\Fixtures\Providers;

use Filament\Panel;
use Filament\PanelProvider;
use Quansitech\Cmf\Area\Services\UpstreamService;
use Quansitech\Cmf\Area\Tests\Fixtures\SandboxResetCommand;
use Quansitech\Cmf\Core\Cmf;

/**
 * 测试面板：自动挂载所有 CMF 插件（含 CmfAreaPlugin）。
 *
 * 升级流程预览沙盒（env CMF_AREA_UPGRADE_SANDBOX=true 时启用）：
 * - data_dir / config_file / work_dir 重定向到骨架 storage/app/upgrade-sandbox，
 *   定稿落盘全部进沙盒，不污染包内真实数据与 config；
 * - 首次访问自动播种基线：拉上游 Releases 取「最新版本的上一个版本」的 csv
 *   （需网络与 7z；失败回退 tests/Fixtures/data/old_cmf_areas.csv），并把沙盒
 *   配置副本的 data_version/upstream_base 同步为该 tag；重置用 area:sandbox-reset；
 * - 运行时 data_version/upstream_base 每次启动从沙盒配置副本回读，保证与定稿
 *   写回后的版本一致；
 * - CMF_AREA_UPGRADE_STUB_UPSTREAM=true 时打桩上游为 tests/Fixtures/data/new_cmf_areas.csv
 *   （也可填 csv 文件路径，相对包根），无需网络与 7z 即可走 fixture 流程。
 */
class AdminPanelProvider extends PanelProvider
{
    public function register(): void
    {
        parent::register();

        if (! filter_var(env('CMF_AREA_UPGRADE_SANDBOX', false), FILTER_VALIDATE_BOOL)) {
            return;
        }

        $root = storage_path('app/upgrade-sandbox');
        $dataDir = $root.'/data';
        $configFile = $root.'/cmf-area.php';

        if (! is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }
        if (! is_file($configFile)) {
            copy(dirname(__DIR__, 3).'/config/cmf-area.php', $configFile);
        }

        config()->set('cmf-area.upgrade.data_dir', $dataDir);
        config()->set('cmf-area.upgrade.config_file', $configFile);
        config()->set('cmf-area.upgrade.work_dir', $root.'/work');

        $stub = env('CMF_AREA_UPGRADE_STUB_UPSTREAM', false);
        if ($stub) {
            $csv = filter_var($stub, FILTER_VALIDATE_BOOL) ? __DIR__.'/../data/new_cmf_areas.csv' : (string) $stub;
            if (! str_starts_with($csv, '/')) {
                $csv = dirname(__DIR__, 3).'/'.$csv;
            }

            $this->app->instance(UpstreamService::class, new class($csv) extends UpstreamService
            {
                public function __construct(private readonly string $stubCsv) {}

                public function latestTag(): ?string
                {
                    return '2099.990101.990101';
                }

                public function versions(): array
                {
                    return ['2099.990101.990101', '2026.260101.260101', '2025.251231.260403'];
                }

                public function download(string $version, ?string $output = null, ?string $workDir = null): string
                {
                    return $this->stubCsv;
                }
            });
        }

        if ($this->app->runningInConsole()) {
            $this->commands([SandboxResetCommand::class]);
        }
    }

    /**
     * 播种与版本回读必须在 boot（所有 provider 已注册、包配置已 merge）后进行，
     * 否则 register 阶段读不到 config('cmf-area.*')。
     */
    public function boot(): void
    {
        if (! filter_var(env('CMF_AREA_UPGRADE_SANDBOX', false), FILTER_VALIDATE_BOOL)) {
            return;
        }

        $root = storage_path('app/upgrade-sandbox');
        $dataDir = $root.'/data';
        $configFile = $root.'/cmf-area.php';

        if (! is_file($dataDir.'/ok_data_level4.csv')) {
            $this->seedBaseline($root, $dataDir, $configFile);
        }

        // 运行时版本以沙盒配置副本为准（含定稿写回后的版本）
        $sandboxConfig = require $configFile;
        config()->set('cmf-area.data_version', (string) $sandboxConfig['data_version']);
        config()->set('cmf-area.upstream_base', (string) $sandboxConfig['upstream_base']);
    }

    /**
     * 首次播种基线：上游最新版本的上一个 Release csv + 配置副本版本同步。
     * 需网络与 7z；任何失败回退 fixture 旧数据（版本保持包内默认值）。
     */
    private function seedBaseline(string $root, string $dataDir, string $configFile): void
    {
        try {
            $upstream = new UpstreamService();
            $tags = $upstream->versions();
            if (count($tags) < 2) {
                throw new \RuntimeException('上游可用版本不足 2 个');
            }

            $baseline = $tags[1];
            $csv = $upstream->download($baseline, $root.'/seed_ok_data_level4.csv', $root.'/seed');
            if (! copy($csv, $dataDir.'/ok_data_level4.csv')) {
                throw new \RuntimeException('基线 csv 复制失败');
            }

            $content = (string) file_get_contents($configFile);
            $content = preg_replace("/'data_version' => '[^']*'/", "'data_version' => '{$baseline}'", $content, 1);
            $content = preg_replace("/'upstream_base' => '[^']*'/", "'upstream_base' => '{$baseline}'", $content, 1);
            file_put_contents($configFile, (string) $content);
        } catch (\Throwable) {
            // 离线/上游不可用：回退 fixture 旧基线，版本保持包内默认（可配合上游 stub 演练）
            if (! is_file($dataDir.'/ok_data_level4.csv')) {
                copy(__DIR__.'/../data/old_cmf_areas.csv', $dataDir.'/ok_data_level4.csv');
            }
        }
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->plugins(Cmf::pluginInstances());
    }
}
