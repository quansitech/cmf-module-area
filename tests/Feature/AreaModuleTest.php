<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Quansitech\Cmf\Area\Filament\Resources\Area\Pages\ListAreas;
use Quansitech\Cmf\Area\Filament\Resources\Area\Pages\ViewArea;
use Quansitech\Cmf\Area\Filament\Resources\Area\RelationManagers\ChildrenRelationManager;

it('区划列表页默认只展示省级，可搜索/筛选下级', function (): void {
    actingAsTestUser();
    createArea(['id' => 11, 'pid' => 0, 'deep' => 0, 'name' => '北京', 'ext_name' => '北京市', 'ext_id' => 110000000000]);
    createArea(['id' => 1101, 'pid' => 11, 'deep' => 1, 'name' => '北京', 'ext_name' => '北京市', 'ext_id' => 110100000000]);

    $beijing = \Quansitech\Cmf\Area\Models\Area::query()->find(11);
    $city = \Quansitech\Cmf\Area\Models\Area::query()->find(1101);

    Livewire::test(ListAreas::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$beijing])
        ->assertCanNotSeeTableRecords([$city]);

    Livewire::test(ListAreas::class)
        ->filterTable('deep', 1)
        ->assertCanSeeTableRecords([$city])
        ->assertCanNotSeeTableRecords([$beijing]);
});

it('区划查看页展示下级地区（树形下钻）', function (): void {
    actingAsTestUser();
    createArea(['id' => 11, 'pid' => 0, 'deep' => 0, 'name' => '北京', 'ext_name' => '北京市', 'ext_id' => 110000000000]);
    createArea(['id' => 1101, 'pid' => 11, 'deep' => 1, 'name' => '北京', 'ext_name' => '北京市', 'ext_id' => 110100000000]);

    Livewire::test(ViewArea::class, ['record' => '11'])->assertOk();

    Livewire::test(ChildrenRelationManager::class, [
        'ownerRecord' => \Quansitech\Cmf\Area\Models\Area::query()->find(11),
        'pageClass' => ViewArea::class,
    ])->assertCanSeeTableRecords([\Quansitech\Cmf\Area\Models\Area::query()->find(1101)]);
});

it('AreaPicker 数据端点：返回下级且过滤已撤销', function (): void {
    actingAsTestUser();
    createArea(['id' => 11, 'pid' => 0, 'deep' => 0, 'name' => '北京', 'ext_name' => '北京市', 'ext_id' => 110000000000]);
    createArea(['id' => 1101, 'pid' => 11, 'deep' => 1, 'name' => '北京', 'ext_name' => '北京市', 'ext_id' => 110100000000]);
    createArea(['id' => 1102, 'pid' => 11, 'deep' => 1, 'name' => '旧区', 'ext_name' => '旧区', 'ext_id' => 110200000000, 'status' => 0]);

    $this->getJson(route('cmf-area.children'))->assertOk()->assertJsonCount(1);
    $this->getJson(route('cmf-area.children', ['id' => 11]))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['id' => 1101]);

    $this->getJson(route('cmf-area.path', ['id' => 1101]))
        ->assertOk()
        ->assertJsonCount(2);
});

it('area:check-upstream 报告上游版本（--no-diff 廉价预检）', function (): void {
    Http::fake([
        'api.github.com/*' => Http::response(['tag_name' => '2999.999999.999999'], 200),
    ]);

    $this->artisan('area:check-upstream', ['--no-diff' => true])
        ->expectsOutputToContain('2999.999999.999999')
        ->assertSuccessful();
});

it('area:check-upstream tag 相等时免下载报已对齐（升级方案 §13.6）', function (): void {
    Http::fake([
        'api.github.com/*' => Http::response(['tag_name' => config('cmf-area.data_version')], 200),
    ]);

    $this->artisan('area:check-upstream')
        ->expectsOutputToContain('已对齐')
        ->assertSuccessful();

    // tag 相等 → 不触发任何下载请求
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'releases/download'));
});
