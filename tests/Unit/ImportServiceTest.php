<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Quansitech\Cmf\Area\Models\Area;
use Quansitech\Cmf\Area\Services\ImportService;

it('导入 fixture csv：行数与字段正确', function (): void {
    $count = app(ImportService::class)->import(__DIR__.'/../Fixtures/data/old_cmf_areas.csv');

    expect($count)->toBe(10)
        ->and(Area::query()->count())->toBe(10);

    $pishan = Area::query()->find(653223);
    expect($pishan)->not->toBeNull()
        ->and($pishan->pid)->toBe(6532)
        ->and($pishan->deep)->toBe(2)
        ->and($pishan->name)->toBe('皮山')
        ->and($pishan->ext_name)->toBe('皮山县')
        ->and($pishan->status)->toBe(1);
});

it('csv 解析支持 UTF-8 BOM 与双引号限定符', function (): void {
    $rows = iterator_to_array(app(ImportService::class)->readCsv(__DIR__.'/../Fixtures/data/old_cmf_areas.csv'));

    // 第一行 id=11 北京，BOM 不污染首列
    expect($rows[0]['id'])->toBe(11)
        ->and($rows[0]['ext_name'])->toBe('北京市');
});

it('ext_id 为 12 位长码时按 int 解析（PG int8 场景）', function (): void {
    $rows = iterator_to_array(app(ImportService::class)->readCsv(__DIR__.'/../Fixtures/data/old_cmf_areas.csv'));

    $saitula = collect($rows)->firstWhere('id', 653223102);
    expect($saitula['ext_id'])->toBe(653223102000);
});

it('import 可重复执行（truncate 后全量重导）', function (): void {
    $service = app(ImportService::class);
    $service->import(__DIR__.'/../Fixtures/data/old_cmf_areas.csv');
    $service->import(__DIR__.'/../Fixtures/data/new_cmf_areas.csv');

    expect(Area::query()->count())->toBe(12)
        ->and(DB::table('cmf_areas')->where('id', 653228)->exists())->toBeTrue();
});
