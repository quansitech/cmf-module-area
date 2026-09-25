<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Quansitech\Cmf\Area\Models\Area;
use Quansitech\Cmf\Area\Services\ImportService;
use Quansitech\Cmf\Area\Tests\Fixtures\Biz\Models\Order;
use Quansitech\Cmf\Area\Tests\Fixtures\Biz\Models\Store;

/**
 * 真实案例回归：2024 和康县（653228）析自皮山县（653223）（split 类）全流程走通——
 * diff → changes.json（人工判读产物 fixture）→ check-changes → generate-migration → 执行。
 */
beforeEach(function (): void {
    migrateAreaSchema();
    app(\Quansitech\Cmf\Area\Services\ReferenceCollector::class)->sync();
    app(ImportService::class)->import(__DIR__.'/../Fixtures/data/old_cmf_areas.csv');
});

it('和康县析自皮山县：全流程（diff → 校验 → 生成迁移 → 执行）', function (): void {
    // ① diff
    $exit = Artisan::call('area:diff', [
        'new_csv' => __DIR__.'/../Fixtures/data/new_cmf_areas.csv',
        '--old' => __DIR__.'/../Fixtures/data/old_cmf_areas.csv',
        '--output' => sys_get_temp_dir().'/diff_hekang.json',
        '--to-version' => '2026.260101.260101',
    ]);
    expect($exit)->toBe(0);

    $diff = json_decode((string) file_get_contents(sys_get_temp_dir().'/diff_hekang.json'), true);
    expect($diff['summary']['added'])->toBe(4)
        ->and($diff['summary']['removed'])->toBe(2)
        ->and($diff['blocked'])->toBeFalse();

    // ② changes.json（fixture 模拟 AI 判读产出）③ 机器校验
    $exit = Artisan::call('area:check-changes', [
        'changes' => __DIR__.'/../Fixtures/data/changes_split.json',
        '--diff' => sys_get_temp_dir().'/diff_hekang.json',
        '--old' => __DIR__.'/../Fixtures/data/old_cmf_areas.csv',
        '--new' => __DIR__.'/../Fixtures/data/new_cmf_areas.csv',
    ]);
    expect($exit)->toBe(0)->and(Artisan::output())->toContain('校验通过');

    // ④ 生成迁移
    $exit = Artisan::call('area:generate-migration', [
        'changes' => __DIR__.'/../Fixtures/data/changes_split.json',
        '--old' => __DIR__.'/../Fixtures/data/old_cmf_areas.csv',
        '--new' => __DIR__.'/../Fixtures/data/new_cmf_areas.csv',
        '--output-dir' => sys_get_temp_dir().'/area-migrations',
    ]);
    expect($exit)->toBe(0);

    $file = sys_get_temp_dir().'/area-migrations/'.date('Y_m_d').'_area_update_2026_260101_260101.php';
    expect(is_file($file))->toBeTrue();

    // 业务数据：门店 remap 存乡镇级旧码（自动迁移）；订单 keep 存浅层县码（历史不动）
    Store::create(['title' => '木吉店', 'area_id' => 653223103]);
    Order::create(['title' => '历史订单', 'region_id' => 653223, 'region_name' => '皮山县']);

    // ⑤ 执行业务项目 migrate（加载生成的迁移文件并 up）
    $migration = require $file;
    $migration->up();

    expect(Area::query()->find(653228)->ext_name)->toBe('和康县')
        ->and(Area::query()->find(653228101)->ext_name)->toBe('昆岭镇')
        ->and(Area::query()->find(653228101)->pid)->toBe(653228)
        ->and(Store::query()->first()->area_id)->toBe(653228102)
        ->and(Order::query()->first()->region_id)->toBe(653223);

    // 变更档案留痕（v2：node/edge 分录，evidence 自 changes.json）
    $record = \Quansitech\Cmf\Area\Models\AreaChange::query()
        ->where('change_type', 'split_from')->where('kind', 'node')->where('side', 'new')->first();
    expect($record->evidence_url)->toContain('wikipedia.org');
    // 下级边留档：kind=edge、old_id/new_id 存 from/to
    $edgeRecord = \Quansitech\Cmf\Area\Models\AreaChange::query()
        ->where('kind', 'edge')->where('old_id', 653223102)->where('new_id', 653228101)->first();
    expect($edgeRecord)->not->toBeNull()->and($edgeRecord->side)->toBeNull();

    // ⑥ 回滚
    $migration->down();
    expect(Area::query()->whereKey(653228)->exists())->toBeFalse()
        ->and(Store::query()->first()->area_id)->toBe(653223103);
});
