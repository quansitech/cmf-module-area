<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area;

use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Quansitech\Cmf\Area\Console\Commands\CheckChangesCommand;
use Quansitech\Cmf\Area\Console\Commands\CheckUpstreamCommand;
use Quansitech\Cmf\Area\Console\Commands\CleanupJournalCommand;
use Quansitech\Cmf\Area\Console\Commands\CollectCommand;
use Quansitech\Cmf\Area\Console\Commands\DiffCommand;
use Quansitech\Cmf\Area\Console\Commands\DownloadCommand;
use Quansitech\Cmf\Area\Console\Commands\FinalizeUpgradeCommand;
use Quansitech\Cmf\Area\Console\Commands\GenerateMigrationCommand;
use Quansitech\Cmf\Area\Console\Commands\PatchBaselineCommand;
use Quansitech\Cmf\Area\Console\Commands\SyncReferencesCommand;
use Quansitech\Cmf\Area\Console\Commands\VerifyBaselineCommand;
use Quansitech\Cmf\Area\Facades\Area;
use Quansitech\Cmf\Area\Services\ReferenceCollector;
use Quansitech\Cmf\Core\Cmf;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class AreaServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('cmf-area')
            ->hasConfigFile()
            ->hasViews()
            ->hasRoute('web')
            ->hasCommands([
                CheckUpstreamCommand::class,
                DownloadCommand::class,
                DiffCommand::class,
                CheckChangesCommand::class,
                GenerateMigrationCommand::class,
                PatchBaselineCommand::class,
                VerifyBaselineCommand::class,
                CollectCommand::class,
                FinalizeUpgradeCommand::class,
                SyncReferencesCommand::class,
                CleanupJournalCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        Cmf::registerPlugin(CmfAreaPlugin::class);

        // Facade 根对象：Area::registerReference() 兜底注册口
        $this->app->singleton('cmf-area', fn (): ReferenceCollector => $this->app->make(ReferenceCollector::class));
    }

    public function packageBooted(): void
    {
        $this->registerModuleAssets();
        $this->registerShieldPermissions();

        FilamentAsset::register([
            Js::make('cmf-area-picker', __DIR__.'/../resources/js/area-picker.js'),
        ], 'quansitech/cmf-module-area');
    }

    /**
     * 模块配置 / 迁移挂到 cmf 系列 tag，由 cmf:install 发布（不覆盖项目已有文件）。
     * 迁移同时 loadMigrationsFrom：发布到宿主后文件名一致，迁移器按名称去重。
     * updates/ 子目录内的升级迁移一并加载（与基线迁移同目录树）。
     *
     * 升级管理界面为文件态工作区（无数据库表），按 cmf-area.upgrade.enabled
     * 在 Page::canAccess 运行期拦截，无需迁移（升级方案 §12）。
     */
    protected function registerModuleAssets(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations/updates');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/cmf-area.php' => config_path('cmf-area.php'),
        ], 'cmf-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'cmf-area-migrations');
    }

    /**
     * 向 Shield 登记本模块的权限点；宿主已在 config/filament-shield.php
     * 配置同名条目时以宿主为准。升级管理界面为单一向导页，可见性由
     * AreaUpgradePage::canAccess 按 cmf-area.upgrade.enabled 拦截（升级方案 §12）。
     */
    protected function registerShieldPermissions(): void
    {
        $manage = config('filament-shield.resources.manage', []);
        $manage[config('cmf-area.resource')] ??= config('cmf-area.permissions');
        config()->set('filament-shield.resources.manage', $manage);
    }
}
