<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Quansitech\Cmf\Area\Models\Area;
use Quansitech\Cmf\Area\Models\AreaChange;
use Quansitech\Cmf\Area\Models\AreaReference;
use Quansitech\Cmf\Area\Services\ImportService;
use Quansitech\Cmf\Area\Services\MigrationExecutor;
use Quansitech\Cmf\Area\Services\MigrationGenerator;
use Quansitech\Cmf\Area\Tests\Fixtures\Biz\Models\Order;
use Quansitech\Cmf\Area\Tests\Fixtures\Biz\Models\Store;

beforeEach(function (): void {
    migrateAreaSchema();
    // 同步本项目的引用登记（业务表操作按登记表延迟绑定）
    app(\Quansitech\Cmf\Area\Services\ReferenceCollector::class)->sync();
    // 导入旧基线
    app(ImportService::class)->import(__DIR__.'/../Fixtures/data/old_cmf_areas.csv');
});

function splitPayload(): array
{
    $changes = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/data/changes_split.json'), true);
    $import = app(ImportService::class);

    return app(MigrationGenerator::class)->payload(
        $changes,
        $import->loadCsvAsMap(__DIR__.'/../Fixtures/data/old_cmf_areas.csv'),
        $import->loadCsvAsMap(__DIR__.'/../Fixtures/data/new_cmf_areas.csv'),
    );
}

it('析出新设（split）：remap 列命中下级边的行自动改写，浅层值进人工清单，keep 列不动', function (): void {
    // 业务数据：门店（remap）存了乡镇级旧码；订单（keep）同样存乡镇级旧码
    Store::create(['title' => '赛图拉店', 'area_id' => 653223102]);
    Store::create(['title' => '皮山店', 'area_id' => 653223]);
    Order::create(['title' => '历史订单', 'region_id' => 653223102, 'region_name' => '赛图拉镇']);

    $result = app(MigrationExecutor::class)->apply(splitPayload());

    // 结构：和康县整族插入，皮山县保留
    expect(Area::query()->find(653228))->not->toBeNull()
        ->and(Area::query()->find(653228101)->ext_name)->toBe('昆岭镇')
        ->and(Area::query()->find(653223)->status)->toBe(1);

    // remap 列：命中下级边的改写为对应新码；等于旧单位本身的浅层值不动
    expect(Store::query()->where('title', '赛图拉店')->first()->area_id)->toBe(653228101)
        ->and(Store::query()->where('title', '皮山店')->first()->area_id)->toBe(653223);

    // keep 列不动
    expect(Order::query()->first()->region_id)->toBe(653223102);

    // 人工清单：split_shallow_value（单位级边 653223→653228 的 unit_mapping 不成立：
    // 旧单位在新版仍存续，浅层值不可判定）
    expect($result['manual'])->toHaveCount(1)
        ->and($result['manual'][0]['reason'])->toBe('split_shallow_value')
        ->and(implode('、', $result['manual'][0]['affected_columns']))->toContain('stores.area_id');

    // keep 列信息性报告
    expect(implode("\n", $result['info']))->toContain('orders.region_id');

    // 变更档案落库并标记 applied_at
    $record = AreaChange::query()->where('version', '2026.260101.260101')->where('change_type', 'split_from')->first();
    expect($record)->not->toBeNull()
        ->and($record->applied_at)->not->toBeNull()
        ->and($record->detail['affected_rows'])->toHaveKey('stores.area_id');
});

it('迁移幂等：重复执行结果一致', function (): void {
    Store::create(['title' => '赛图拉店', 'area_id' => 653223102]);

    $executor = app(MigrationExecutor::class);
    $executor->apply(splitPayload());
    $executor->apply(splitPayload());

    expect(Store::query()->first()->area_id)->toBe(653228101)
        ->and(Area::query()->whereIn('id', [653228, 653228101, 653228102])->count())->toBe(3)
        // v2 档案：2 条 node（和康县/朝阳区）+ 3 条 edge，主键 (version, kind, side, old_id, new_id)
        ->and(AreaChange::query()->where('version', '2026.260101.260101')->count())->toBe(5);
});

it('merge_into（unit_mapping 成立的单位级边）：remap 列批量改写，keep 列不动', function (): void {
    Store::create(['title' => '皮山店', 'area_id' => 653223]);
    Order::create(['title' => '历史订单', 'region_id' => 653223, 'region_name' => '皮山县']);

    $payload = [
        'version' => '2026.260101.260101',
        'areas' => [
            ['op' => 'retire', 'id' => 653223, 'successor_id' => 653200],
        ],
        'mappings' => [
            ['from' => 653223, 'to' => 653200, 'unit_level' => true],
        ],
        'manual' => [],
        'records' => [],
    ];

    app(MigrationExecutor::class)->apply($payload);

    expect(Store::query()->first()->area_id)->toBe(653200)
        ->and(Order::query()->first()->region_id)->toBe(653223)
        ->and(Area::query()->find(653223)->status)->toBe(0)
        ->and(Area::query()->find(653223)->successor_id)->toBe(653200);
});

it('unit_mapping 不成立的单位级对不进 mappings：一律进人工清单（§1.4 一刀切不复存在）', function (): void {
    Store::create(['title' => '渝北店', 'area_id' => 500112]);

    // v2 语义：不可判定的单位级对由生成期剔除出 mappings（payload 里根本没有该对），
    // 执行器不再有 full_transfer 类型分支
    $payload = [
        'version' => '2026.260101.260101',
        'areas' => [['op' => 'retire', 'id' => 500112, 'successor_id' => 500157]],
        'mappings' => [],
        'manual' => [
            ['type' => 'merge_into', 'reason' => 'partial_transfer', 'old_id' => 500112, 'new_id' => 500157, 'hint' => '疆域旁落'],
        ],
        'records' => [],
    ];

    $result = app(MigrationExecutor::class)->apply($payload);

    expect(Store::query()->first()->area_id)->toBe(500112)
        ->and($result['manual'][0]['affected_columns'])->toContain('stores.area_id（1 行）');
});

it('code_change：旧码退休、新码启用、remap 列改写', function (): void {
    createArea(['id' => 469003, 'pid' => 46, 'deep' => 2, 'name' => '儋州', 'ext_name' => '儋州市', 'ext_id' => 469003000000]);
    Store::create(['title' => '儋州店', 'area_id' => 469003]);

    $payload = [
        'version' => '2026.260101.260101',
        'areas' => [
            ['op' => 'insert', 'id' => 460400, 'pid' => 4604, 'deep' => 2, 'name' => '儋州', 'pinyin_prefix' => 'd', 'pinyin' => 'dan zhou', 'ext_id' => 460400000000, 'ext_name' => '儋州市'],
            ['op' => 'retire', 'id' => 469003, 'successor_id' => 460400],
        ],
        'mappings' => [
            ['from' => 469003, 'to' => 460400, 'unit_level' => true],
        ],
        'manual' => [],
        'records' => [],
    ];

    app(MigrationExecutor::class)->apply($payload);

    expect(Store::query()->first()->area_id)->toBe(460400)
        ->and(Area::query()->find(460400)->ext_name)->toBe('儋州市')
        ->and(Area::query()->find(469003)->status)->toBe(0);
});

it('code_reuse 归档迁移：旧行迁至归档 id，keep 引用一并指向归档 id，显示语义不变', function (): void {
    // 旧 500105 = 江北区（已废止）；新 500105 = 某新区
    createArea(['id' => 500105, 'pid' => 50, 'deep' => 2, 'name' => '江北', 'ext_name' => '江北区', 'ext_id' => 500105000000]);
    Order::create(['title' => '历史订单', 'region_id' => 500105, 'region_name' => '江北区']);

    $archiveId = (int) '90500105';
    $payload = [
        'version' => '2026.260101.260101',
        'areas' => [
            ['op' => 'archive', 'id' => 500105, 'archive_id' => $archiveId],
            ['op' => 'insert', 'id' => 500105, 'pid' => 50, 'deep' => 2, 'name' => '两江', 'pinyin_prefix' => 'l', 'pinyin' => 'liang jiang', 'ext_id' => 500105000001, 'ext_name' => '两江新区'],
        ],
        'mappings' => [
            ['from' => 500105, 'to' => $archiveId, 'unit_level' => true, 'archive' => true],
        ],
        'manual' => [],
        'records' => [],
    ];

    app(MigrationExecutor::class)->apply($payload);

    // 归档行保留原名称（回显不变），keep 引用指向归档 id（语义不断链）
    expect(Area::query()->find($archiveId)->ext_name)->toBe('江北区')
        ->and(Area::query()->find($archiveId)->status)->toBe(0)
        ->and(Order::query()->first()->region_id)->toBe($archiveId)
        // 新单位正常使用官方代码
        ->and(Area::query()->find(500105)->ext_name)->toBe('两江新区');
});

it('abolish：撤销不删行，进人工清单', function (): void {
    Order::create(['title' => '历史订单', 'region_id' => 653223, 'region_name' => '皮山县']);

    $payload = [
        'version' => '2026.260101.260101',
        'areas' => [['op' => 'retire', 'id' => 653223, 'successor_id' => null]],
        'mappings' => [],
        'manual' => [
            ['type' => 'abolish', 'reason' => 'abolish_no_successor', 'old_id' => 653223, 'new_id' => null, 'hint' => '撤销无承继'],
        ],
        'records' => [],
    ];

    $result = app(MigrationExecutor::class)->apply($payload);

    expect(Area::query()->find(653223)->status)->toBe(0)
        ->and(Order::query()->first()->region_id)->toBe(653223)
        ->and($result['manual'][0]['affected_columns'])->toContain('orders.region_id（1 行）');
});

it('rename / parent_change 只动 cmf_areas 结构', function (): void {
    $payload = [
        'version' => '2026.260101.260101',
        'areas' => [
            ['op' => 'rename', 'id' => 653223, 'name' => '皮山新', 'ext_name' => '皮山新县', 'pinyin_prefix' => 'p', 'pinyin' => 'pi shan xin'],
            ['op' => 'reparent', 'id' => 653223, 'pid' => 6500],
        ],
        'mappings' => [],
        'manual' => [],
        'records' => [],
    ];

    app(MigrationExecutor::class)->apply($payload);

    expect(Area::query()->find(653223)->ext_name)->toBe('皮山新县')
        ->and(Area::query()->find(653223)->pid)->toBe(6500);
});

it('延迟绑定：迁移文件不含业务表名；不同项目按自己的登记表生效', function (): void {
    $payload = splitPayload();
    $rendered = app(MigrationGenerator::class)->renderMigration($payload);

    // 生成物不含任何业务表名
    expect($rendered)->not->toContain('orders')
        ->and($rendered)->not->toContain('stores')
        ->and($rendered)->not->toContain('region_id')
        ->and($rendered)->toContain('MigrationExecutor');

    // 模拟"另一个项目"的引用登记：stores.area_id 改为 keep → 不自动改写
    Store::create(['title' => '赛图拉店', 'area_id' => 653223102]);
    AreaReference::query()->where('table_name', 'stores')->update(['merge_strategy' => 'keep']);

    $result = app(MigrationExecutor::class)->apply($payload);

    expect(Store::query()->first()->area_id)->toBe(653223102)
        ->and(implode("\n", $result['info']))->toContain('stores.area_id');
});

it('down() 回滚：映射严格按执行序逆序反向', function (): void {
    Store::create(['title' => '皮山店', 'area_id' => 653223]);

    $payload = [
        'version' => '2026.260101.260101',
        'areas' => [['op' => 'retire', 'id' => 653223, 'successor_id' => 653200]],
        'mappings' => [
            ['from' => 653223, 'to' => 653200, 'unit_level' => true],
        ],
        'manual' => [],
        'records' => [],
    ];

    $executor = app(MigrationExecutor::class);
    $executor->apply($payload);
    expect(Store::query()->first()->area_id)->toBe(653200);

    $executor->revert($payload);
    expect(Store::query()->first()->area_id)->toBe(653223)
        ->and(Area::query()->find(653223)->status)->toBe(1);
});
