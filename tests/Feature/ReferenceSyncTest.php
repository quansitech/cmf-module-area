<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Quansitech\Cmf\Area\Models\AreaReference;
use Quansitech\Cmf\Area\Services\ReferenceCollector;
use Quansitech\Cmf\Area\Tests\Fixtures\BadBiz\Models\BadTypeOrder;
use Quansitech\Cmf\Area\Tests\Fixtures\Biz\Models\Order;
use Quansitech\Cmf\Area\Tests\Fixtures\Biz\Models\Store;

beforeEach(function (): void {
    // 引用登记表来自模块迁移，按需建表（跳过全量 seed，见 Pest.php）
    migrateAreaSchema();
});

it('sync 经 provider 反推自动发现扫描范围并落库声明', function (): void {
    $result = app(ReferenceCollector::class)->sync();

    expect($result['synced'])->toBeGreaterThanOrEqual(2);

    $order = AreaReference::query()->where('table_name', 'orders')->where('column_name', 'region_id')->first();
    expect($order)->not->toBeNull()
        ->and($order->merge_strategy)->toBe('keep')
        ->and($order->snapshot_column)->toBe('region_name');

    $store = AreaReference::query()->where('table_name', 'stores')->where('column_name', 'area_id')->first();
    expect($store)->not->toBeNull()
        ->and($store->merge_strategy)->toBe('remap');
});

it('sync 幂等：重复执行不重复落库', function (): void {
    $collector = app(ReferenceCollector::class);
    $collector->sync();
    $first = AreaReference::query()->count();

    $collector->sync();
    expect(AreaReference::query()->count())->toBe($first);
});

it('兜底注册口 registerReference 的登记与自动发现合并落库', function (): void {
    ReferenceCollector::registerReference('legacy_table', 'legacy_area_id', 'remap', null, '历史表兜底');
    // legacy_table 不存在于数据库，登记时类型校验会失败——先建表
    DB::statement('create table legacy_table (id integer primary key, legacy_area_id bigint)');

    app(ReferenceCollector::class)->sync();

    $row = AreaReference::query()->where('table_name', 'legacy_table')->where('column_name', 'legacy_area_id')->first();
    expect($row)->not->toBeNull()->and($row->merge_strategy)->toBe('remap');
});

it('引用列非整型时 sync 拒绝登记', function (): void {
    $collector = app(ReferenceCollector::class);

    expect(fn () => $collector->assertIntegerColumn('bad_orders', 'region_id'))
        ->toThrow(RuntimeException::class, '必须是整型');
});

it('bad_orders 的模型声明被类型校验拦截（注册非法包后 sync 全流程抛错）', function (): void {
    $declarations = app(ReferenceCollector::class)->declarationsOf(BadTypeOrder::class);
    expect($declarations)->toHaveKey('region_id');

    // 模拟该业务包被安装（provider 注册后即被自动发现）
    $this->app->register(\Quansitech\Cmf\Area\Tests\Fixtures\BadBiz\BadBizServiceProvider::class);

    expect(fn () => app(ReferenceCollector::class)->sync())->toThrow(RuntimeException::class, '必须是整型');
});

it('声明读取与校验：isDeclared / onMerge 非法值', function (): void {
    $collector = app(ReferenceCollector::class);

    expect($collector->isDeclared(Order::class, 'region_id'))->toBeTrue()
        ->and($collector->isDeclared(Store::class, 'region_id'))->toBeFalse();

    $bad = new class extends \Illuminate\Database\Eloquent\Model
    {
        public static function areaReferences(): array
        {
            return ['x' => ['onMerge' => 'sometimes']];
        }
    };

    expect(fn () => $collector->declarationsOf($bad::class))->toThrow(RuntimeException::class, 'onMerge 取值非法');
});
