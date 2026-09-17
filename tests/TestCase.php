<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Quansitech\Cmf\Area\AreaServiceProvider;
use Quansitech\Cmf\Area\Tests\Fixtures\Providers\AdminPanelProvider;
use Quansitech\Cmf\Core\CmfCoreServiceProvider;

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
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }
}
