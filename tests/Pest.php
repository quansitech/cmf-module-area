<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Quansitech\Cmf\Area\Tests\Fixtures\Models\TestUser;
use Quansitech\Cmf\Area\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');
uses(TestCase::class, RefreshDatabase::class)->in('Unit');

/**
 * 创建测试用户并登录（默认授予全部 Gate 权限）。
 */
function actingAsTestUser(bool $grantAll = true): TestUser
{
    $user = TestUser::query()->create([
        'name' => '测试用户',
        'email' => 'tester@example.com',
        'password' => 'password',
    ]);

    Illuminate\Support\Facades\Auth::login($user);

    if ($grantAll) {
        grantAllPermissions();
    }

    return $user;
}

/**
 * 放行全部权限（等价宿主的 super_admin Gate::before）。
 */
function grantAllPermissions(): void
{
    Illuminate\Support\Facades\Gate::before(fn (): bool => true);
}

/**
 * 只迁移模块的 3 张建表迁移（跳过 4 万行的全量数据 seed，加速测试）。
 */
function migrateAreaSchema(): void
{
    foreach (glob(__DIR__.'/../database/migrations/2026_09_15_00000[123]_*.php') ?: [] as $file) {
        Illuminate\Support\Facades\Artisan::call('migrate', ['--path' => $file, '--realpath' => true]);
    }
}

/**
 * 快速写入地区行。
 */
function createArea(array $attributes): Quansitech\Cmf\Area\Models\Area
{
    return Quansitech\Cmf\Area\Models\Area::query()->create([
        'pid' => 0,
        'deep' => 0,
        'name' => '测试',
        'pinyin_prefix' => 'c',
        'pinyin' => 'ce shi',
        'ext_id' => $attributes['id'] * 1000000,
        'ext_name' => '测试地区',
        'status' => 1,
        ...$attributes,
    ]);
}
