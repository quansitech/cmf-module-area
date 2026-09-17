<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Tests\Fixtures\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * 测试用户：可直接登录 Filament 面板。
 */
class TestUser extends Authenticatable implements FilamentUser
{
    protected $table = 'users';

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'password'];

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
