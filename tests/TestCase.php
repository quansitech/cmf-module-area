<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Quansitech\Cmf\Area\AreaServiceProvider;
use Quansitech\Cmf\Area\Tests\Fixtures\Providers\AdminPanelProvider;
use Quansitech\Cmf\Core\CmfCoreServiceProvider;

use function Orchestra\Testbench\load_migration_paths;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            \BladeUI\Icons\BladeIconsServiceProvider::class,
            \BladeUI\Heroicons\BladeHeroiconsServiceProvider::class,
            \RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider::class,
            // 注意顺序：filament/support 会 bind() 覆盖 Livewire 的 DataStore，
            // Livewire 后注册可让 registerMechanisms 的 instance() 绑定优先生效
            \Filament\Support\SupportServiceProvider::class,
            \Filament\Actions\ActionsServiceProvider::class,
            \Filament\Schemas\SchemasServiceProvider::class,
            \Filament\Forms\FormsServiceProvider::class,
            \Filament\Infolists\InfolistsServiceProvider::class,
            \Filament\Tables\TablesServiceProvider::class,
            \Filament\Notifications\NotificationsServiceProvider::class,
            \Filament\Widgets\WidgetsServiceProvider::class,
            \Filament\FilamentServiceProvider::class,
            \Livewire\LivewireServiceProvider::class,
            CmfCoreServiceProvider::class,
            AdminPanelProvider::class,
            \Quansitech\Cmf\Area\Tests\Fixtures\Biz\BizServiceProvider::class,
            AreaServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', Fixtures\Models\TestUser::class);
        $app['config']->set('cmf-area.middleware', ['web']);

        // 并行（paratest）时按 worker 隔离 storage：多个进程同时跑迁移执行器
        // 会向 storage/logs 下同版本号的报告文件覆盖写，隔离后互不干扰。
        $token = getenv('TEST_TOKEN');
        if (is_string($token) && $token !== '') {
            $storage = $app->storagePath().'/parallel-'.$token;
            foreach (['logs', 'framework/views', 'framework/cache', 'framework/sessions'] as $dir) {
                is_dir("{$storage}/{$dir}") || mkdir("{$storage}/{$dir}", 0755, true);
            }
            $app->useStoragePath($storage);
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        // 只注册迁移路径，迁移本身交给 RefreshDatabase 的 migrate:fresh 统一执行；
        // 不走 Testbench 的 loadMigrationsFrom——迁移状态保留后它会为每个用例
        // 额外拉起 MigrateProcessor 并重置 Artisan，白白增加每用例开销。
        load_migration_paths($this->app, [__DIR__.'/Fixtures/migrations']);
    }

    /**
     * Testbench 默认在内存 sqlite 下于每个用例 tearDown 时重置 RefreshDatabaseState，
     * 导致每个用例都重新执行 migrate:fresh —— database/migrations/updates/ 积累
     * 升级迁移后，成本会被放大为「迁移文件数 × 用例数」。
     *
     * 这里保留迁移状态与内存 PDO（Laravel 原生 RefreshDatabase 的内存库行为）：
     * 每个进程只 migrate:fresh 一次，各用例仍靠事务回滚隔离，互不影响。
     */
    protected function tearDownInteractsWithMigrations(): void
    {
        // 故意留空：不重置 RefreshDatabaseState。
    }
}
