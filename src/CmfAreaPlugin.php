<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area;

use Filament\Contracts\Plugin;
use Filament\Panel;

class CmfAreaPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'cmf-area';
    }

    public function register(Panel $panel): void
    {
        // 升级管理界面是包的维护者工具：页面始终注册（路由存在），
        // 可见性由 AreaUpgradePage::canAccess 在运行期按 config 开关拦截（升级方案 §12），
        // 业务项目后台默认 403 + 不出现在导航。
        $panel->resources(array_filter([
            config('cmf-area.resource'),
        ]));
        $panel->pages([
            Filament\Pages\AreaUpgradePage::class,
        ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
