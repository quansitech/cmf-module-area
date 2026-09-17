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
        $panel->resources([
            config('cmf-area.resource'),
        ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
