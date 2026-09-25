<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Quansitech\Cmf\Area\Models\Area;
use Quansitech\Cmf\Area\Models\AreaChange;
use Quansitech\Cmf\Area\Models\AreaMigrationJournal;
use Quansitech\Cmf\Area\Models\AreaReference;
use Quansitech\Cmf\Area\Services\ImportService;
use Quansitech\Cmf\Area\Services\MigrationExecutor;
use Quansitech\Cmf\Area\Tests\Fixtures\Biz\Models\Order;
use Quansitech\Cmf\Area\Tests\Fixtures\Biz\Models\Store;

/**
 * A 级精确回滚用例（docs/area-precise-rollback-and-changes-v3.md §5 测试清单）：
 * 链式复用、废止复用、rename/reparent、守卫跳过、无日志拒绝、幂等、
 * chunk 大数据量、无单主键列回退、cleanup-journal 命令。
 */
beforeEach(function (): void {
    migrateAreaSchema();
    app(\Quansitech\Cmf\Area\Services\ReferenceCollector::class)->sync();
});

/** 三沙链式复用 payload（等价生成器产出：南沙 460302→460303、西沙 460301→460302，id 460302 复用） */
function chainReusePayload(): array
{
    return [
        'version' => '2026.260101.260101',
        'journal' => true,
        'areas' => [
            ['op' => 'retire', 'id' => 460301, 'successor_id' => 460302],
            ['op' => 'retire', 'id' => 460302, 'successor_id' => 460303],
            ['op' => 'insert', 'id' => 460303, 'pid' => 4603, 'deep' => 2, 'name' => '南沙', 'pinyin_prefix' => 'n', 'pinyin' => 'nan sha', 'ext_id' => 460303000000, 'ext_name' => '南沙区'],
            ['op' => 'insert', 'id' => 460303000, 'pid' => 460303, 'deep' => 3, 'name' => '永暑', 'pinyin_prefix' => 'y', 'pinyin' => 'yong shu', 'ext_id' => 460303000001, 'ext_name' => '永暑社区'],
            ['op' => 'insert', 'id' => 460302, 'pid' => 4603, 'deep' => 2, 'name' => '西沙', 'pinyin_prefix' => 'x', 'pinyin' => 'xi sha', 'ext_id' => 460302000000, 'ext_name' => '西沙区'],
            ['op' => 'insert', 'id' => 460302000, 'pid' => 460302, 'deep' => 3, 'name' => '永兴', 'pinyin_prefix' => 'y', 'pinyin' => 'yong xing', 'ext_id' => 460302000001, 'ext_name' => '永兴社区'],
        ],
        'mappings' => [
            ['from' => 460302, 'to' => 460303, 'unit_level' => true],
            ['from' => 460302000, 'to' => 460303000, 'unit_level' => false],
            ['from' => 460301, 'to' => 460302, 'unit_level' => true],
            ['from' => 460301000, 'to' => 460302000, 'unit_level' => false],
        ],
        'manual' => [],
        'records' => [],
    ];
}

/** @return array<string, mixed>|null */
function areaRow(int $id): ?array
{
    $row = DB::table('cmf_areas')->where('id', $id)->first();

    return $row === null ? null : (array) $row;
}

it('链式复用 apply+revert：460302 整行恢复（含时间戳），业务引用回流且不悬空', function (): void {
    $old = writeAreaCsvFixture('rb_sansha_old.csv', [
        [46, 0, 0, '海南', '海南省'],
        [4603, 46, 1, '三沙', '三沙市'],
        [460301, 4603, 2, '西沙', '西沙区'],
        [460301000, 460301, 3, '永兴', '永兴社区'],
        [460302, 4603, 2, '南沙', '南沙区'],
        [460302000, 460302, 3, '永暑', '永暑社区'],
    ]);
    app(ImportService::class)->import($old);

    $before302 = areaRow(460302);
    $before302000 = areaRow(460302000);
    Store::create(['title' => '南沙店', 'area_id' => 460302]);
    Store::create(['title' => '西沙店', 'area_id' => 460301]);

    $executor = app(MigrationExecutor::class);
    $executor->apply(chainReusePayload());

    // apply 后：460302 行被覆盖为西沙区（旧南沙行数据被覆盖）
    expect(Area::query()->find(460302)->ext_name)->toBe('西沙区');

    $result = $executor->revert(chainReusePayload());

    // 460302 整行恢复为南沙区（含 created_at/updated_at 原样写回），下级行同复
    expect(areaRow(460302))->toEqual($before302)
        ->and(areaRow(460302000))->toEqual($before302000)
        // 纯新增行删除
        ->and(areaRow(460303))->toBeNull()
        ->and(areaRow(460303000))->toBeNull()
        // 西沙区恢复启用
        ->and(areaRow(460301)['status'])->toBe(1)
        ->and(areaRow(460301)['successor_id'])->toBeNull()
        // 南沙店引用回流且指向存在的地区行（不悬空）
        ->and(Store::query()->where('title', '南沙店')->first()->area_id)->toBe(460302)
        ->and(Area::query()->whereKey(460302)->exists())->toBeTrue()
        ->and(Store::query()->where('title', '西沙店')->first()->area_id)->toBe(460301)
        ->and($result['stats']['biz_restored'])->toBe(2)
        ->and($result['skipped'])->toBeEmpty();
});

it('废止复用 revert：旧单位整行恢复 status=1，归档行删除，remap/keep 引用一并还原', function (): void {
    createArea(['id' => 700001, 'pid' => 7000, 'deep' => 2, 'name' => '旧仓', 'ext_name' => '旧仓镇']);
    $archiveId = (int) '90700001';
    $payload = [
        'version' => '2026.260101.260101',
        'journal' => true,
        'areas' => [
            ['op' => 'archive', 'id' => 700001, 'archive_id' => $archiveId],
            ['op' => 'insert', 'id' => 700001, 'pid' => 7000, 'deep' => 2, 'name' => '新仓', 'pinyin_prefix' => 'x', 'pinyin' => 'xin cang', 'ext_id' => 700001000001, 'ext_name' => '新仓镇'],
        ],
        'mappings' => [
            ['from' => 700001, 'to' => $archiveId, 'unit_level' => true, 'archive' => true],
        ],
        'manual' => [],
        'records' => [],
    ];

    $before = areaRow(700001);
    Store::create(['title' => '旧仓店', 'area_id' => 700001]);
    Order::create(['title' => '历史订单', 'region_id' => 700001, 'region_name' => '旧仓镇']);

    $executor = app(MigrationExecutor::class);
    $executor->apply($payload);

    expect(Area::query()->find($archiveId)->ext_name)->toBe('旧仓镇')
        ->and(Area::query()->find(700001)->ext_name)->toBe('新仓镇');

    $executor->revert($payload);

    // 旧行整行恢复（含 status=1 与时间戳），归档行删除，引用全部回流
    expect(areaRow(700001))->toEqual($before)
        ->and(areaRow(700001)['status'])->toBe(1)
        ->and(areaRow($archiveId))->toBeNull()
        ->and(Store::query()->first()->area_id)->toBe(700001)
        ->and(Order::query()->first()->region_id)->toBe(700001);
});

it('rename / reparent revert：旧名称与隶属关系（pid）整行恢复', function (): void {
    app(ImportService::class)->import(__DIR__.'/../Fixtures/data/old_cmf_areas.csv');
    $before = areaRow(653223);

    $payload = [
        'version' => '2026.260101.260101',
        'journal' => true,
        'areas' => [
            ['op' => 'rename', 'id' => 653223, 'name' => '皮山新', 'ext_name' => '皮山新县', 'pinyin_prefix' => 'p', 'pinyin' => 'pi shan xin'],
            ['op' => 'reparent', 'id' => 653223, 'pid' => 6500],
        ],
        'mappings' => [],
        'manual' => [],
        'records' => [],
    ];

    $executor = app(MigrationExecutor::class);
    $executor->apply($payload);
    expect(Area::query()->find(653223)->ext_name)->toBe('皮山新县')
        ->and(Area::query()->find(653223)->pid)->toBe(6500);

    $executor->revert($payload);

    expect(areaRow(653223))->toEqual($before);
});

it('biz 守卫：升级后被业务改写的行跳过且不还原，进跳过清单', function (): void {
    app(ImportService::class)->import(__DIR__.'/../Fixtures/data/old_cmf_areas.csv');
    $storeA = Store::create(['title' => '未动店', 'area_id' => 653223102]);
    $storeB = Store::create(['title' => '改写店', 'area_id' => 653223102]);

    $payload = [
        'version' => '2026.260101.260101',
        'journal' => true,
        'areas' => [],
        'mappings' => [['from' => 653223102, 'to' => 653228101, 'unit_level' => false]],
        'manual' => [],
        'records' => [],
    ];

    $executor = app(MigrationExecutor::class);
    $executor->apply($payload);
    expect(Store::query()->whereKey($storeA->id)->value('area_id'))->toBe(653228101);

    // 升级后业务方把改写店迁到了别的区划（不再是迁移写入值）
    Store::query()->whereKey($storeB->id)->update(['area_id' => 110101]);

    $result = $executor->revert($payload);

    expect(Store::query()->whereKey($storeA->id)->value('area_id'))->toBe(653223102)
        ->and(Store::query()->whereKey($storeB->id)->value('area_id'))->toBe(110101)
        ->and($result['stats']['biz_restored'])->toBe(1)
        ->and($result['skipped'])->toHaveCount(1)
        ->and($result['skipped'][0]['table'])->toBe('stores')
        ->and($result['skipped'][0]['pk'])->toBe((string) $storeB->id)
        ->and($result['skipped'][0]['current'])->toBe(110101);

    // 跳过清单写入回滚报告文件
    $report = file_get_contents(storage_path('logs/area-migration-2026_260101_260101-rollback.log'));
    expect($report)->toContain('[跳过] stores.area_id pk='.$storeB->id);
});

it('新格式 payload 无日志且已应用：拒绝回滚（不退回盲扫）', function (): void {
    app(ImportService::class)->import(__DIR__.'/../Fixtures/data/old_cmf_areas.csv');
    $payload = [
        'version' => '2026.260101.260101',
        'journal' => true,
        'areas' => [['op' => 'retire', 'id' => 653223, 'successor_id' => 653200]],
        'mappings' => [],
        'manual' => [],
        'records' => [
            ['kind' => 'node', 'side' => 'old', 'change_type' => 'merge_into', 'old_id' => 653223, 'new_id' => null,
                'old_name' => '皮山县', 'new_name' => null, 'detail' => [], 'evidence_url' => null, 'evidence_title' => null, 'ai_summary' => null],
        ],
    ];

    $executor = app(MigrationExecutor::class);
    $executor->apply($payload);
    expect(AreaChange::query()->where('version', '2026.260101.260101')->whereNotNull('applied_at')->exists())->toBeTrue();

    // 日志被清理（或丢失）
    AreaMigrationJournal::query()->where('version', '2026.260101.260101')->delete();

    expect(fn () => $executor->revert($payload))
        ->toThrow(RuntimeException::class, '拒绝回滚');
});

it('revert 幂等：二次回滚为空回放，状态保持', function (): void {
    app(ImportService::class)->import(__DIR__.'/../Fixtures/data/old_cmf_areas.csv');
    Store::create(['title' => '赛图拉店', 'area_id' => 653223102]);

    $payload = [
        'version' => '2026.260101.260101',
        'journal' => true,
        'areas' => [['op' => 'retire', 'id' => 653223102, 'successor_id' => 653228101]],
        'mappings' => [['from' => 653223102, 'to' => 653228101, 'unit_level' => false]],
        'manual' => [],
        'records' => [],
    ];

    $executor = app(MigrationExecutor::class);
    $executor->apply($payload);
    $executor->revert($payload);

    expect(Store::query()->first()->area_id)->toBe(653223102)
        ->and(AreaMigrationJournal::query()->where('version', '2026.260101.260101')->count())->toBe(0);

    // 二次回滚：日志已删、履历已置空 → 空回放，不报错
    $result = $executor->revert($payload);

    expect(Store::query()->first()->area_id)->toBe(653223102)
        ->and(areaRow(653223102)['status'])->toBe(1)
        ->and($result['stats'])->toBe(['area_restored' => 0, 'area_deleted' => 0, 'biz_restored' => 0, 'biz_scanned' => 0])
        ->and($result['skipped'])->toBeEmpty();
});

it('chunk 大数据量：1100 行业务日志跨块捕获与回放', function (): void {
    app(ImportService::class)->import(__DIR__.'/../Fixtures/data/old_cmf_areas.csv');

    foreach (array_chunk(range(1, 1100), 400) as $chunk) {
        DB::table('stores')->insert(array_map(
            fn (int $i): array => ['title' => "店{$i}", 'area_id' => 653223102],
            $chunk,
        ));
    }

    $payload = [
        'version' => '2026.260101.260101',
        'journal' => true,
        'areas' => [],
        'mappings' => [['from' => 653223102, 'to' => 653228101, 'unit_level' => false]],
        'manual' => [],
        'records' => [],
    ];

    $executor = app(MigrationExecutor::class);
    $result = $executor->apply($payload);

    expect($result['affected']['stores.area_id'])->toBe(1100)
        ->and(AreaMigrationJournal::query()->where('version', '2026.260101.260101')->where('kind', 'biz')->count())->toBe(1100)
        ->and(Store::query()->where('area_id', 653228101)->count())->toBe(1100);

    $reverted = $executor->revert($payload);

    expect(Store::query()->where('area_id', 653223102)->count())->toBe(1100)
        ->and($reverted['stats']['biz_restored'])->toBe(1100)
        ->and($reverted['skipped'])->toBeEmpty();
});

it('无单主键的登记列：退回值扫描（biz_scan 日志 + 报告警告）', function (): void {
    app(ImportService::class)->import(__DIR__.'/../Fixtures/data/old_cmf_areas.csv');
    DB::statement('create table no_pk_orders (title varchar(255), area_id bigint)');
    AreaReference::query()->create([
        'table_name' => 'no_pk_orders',
        'column_name' => 'area_id',
        'merge_strategy' => AreaReference::STRATEGY_REMAP,
        'description' => '无主键历史表',
    ]);
    DB::table('no_pk_orders')->insert([
        ['title' => '甲', 'area_id' => 653223102],
        ['title' => '乙', 'area_id' => 653223102],
    ]);

    $payload = [
        'version' => '2026.260101.260101',
        'journal' => true,
        'areas' => [],
        'mappings' => [['from' => 653223102, 'to' => 653228101, 'unit_level' => false]],
        'manual' => [],
        'records' => [],
    ];

    $executor = app(MigrationExecutor::class);
    $result = $executor->apply($payload);

    expect(DB::table('no_pk_orders')->where('area_id', 653228101)->count())->toBe(2)
        ->and(implode("\n", $result['info']))->toContain('未探测到单一主键')
        ->and(AreaMigrationJournal::query()->where('kind', AreaMigrationJournal::KIND_BIZ_SCAN)->count())->toBe(1);

    $reverted = $executor->revert($payload);

    expect(DB::table('no_pk_orders')->where('area_id', 653223102)->count())->toBe(2)
        ->and($reverted['stats']['biz_scanned'])->toBe(2);
});

it('area:cleanup-journal 清理指定版本的回滚日志；apply 报告附清理提示', function (): void {
    app(ImportService::class)->import(__DIR__.'/../Fixtures/data/old_cmf_areas.csv');
    Store::create(['title' => '赛图拉店', 'area_id' => 653223102]);

    $payload = [
        'version' => '2026.260101.260101',
        'journal' => true,
        'areas' => [],
        'mappings' => [['from' => 653223102, 'to' => 653228101, 'unit_level' => false]],
        'manual' => [],
        'records' => [],
    ];

    app(MigrationExecutor::class)->apply($payload);
    expect(AreaMigrationJournal::query()->where('version', '2026.260101.260101')->count())->toBeGreaterThan(0);

    // apply 报告末尾输出清理提示
    $report = file_get_contents(storage_path('logs/area-migration-2026_260101_260101.log'));
    expect($report)->toContain('area:cleanup-journal 2026.260101.260101');

    $exit = Artisan::call('area:cleanup-journal', ['version' => '2026.260101.260101']);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('2026.260101.260101')
        ->and(AreaMigrationJournal::query()->where('version', '2026.260101.260101')->count())->toBe(0);
});
