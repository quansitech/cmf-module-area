<?php

declare(strict_types=1);

use Quansitech\Cmf\Area\Services\DiffService;
use Quansitech\Cmf\Area\Services\ImportService;

function diffFixtureMaps(): array
{
    $import = app(ImportService::class);

    return [
        $import->loadCsvAsMap(__DIR__.'/../Fixtures/data/old_cmf_areas.csv'),
        $import->loadCsvAsMap(__DIR__.'/../Fixtures/data/new_cmf_areas.csv'),
    ];
}

it('判定 added / removed / renamed / parent_changed 四类 diff', function (): void {
    [$oldMap, $newMap] = diffFixtureMaps();

    $diff = app(DiffService::class)->diff($oldMap, $newMap);

    expect(array_column($diff['added'], 'id'))->toContain(110105, 653228, 653228101, 653228102)
        ->and(array_column($diff['removed'], 'id'))->toContain(653223102, 653223103)
        ->and($diff['renamed'])->toBe([])
        ->and($diff['parent_changed'])->toBe([])
        ->and($diff['code_reuse_suspected'])->toBe([]);
});

it('id 相同 ext_name 不同判定为 renamed', function (): void {
    [$oldMap, $newMap] = diffFixtureMaps();
    $newMap[110101]['ext_name'] = '东城新区';

    $diff = app(DiffService::class)->diff($oldMap, $newMap);

    expect($diff['renamed'])->toHaveCount(1)
        ->and($diff['renamed'][0]['id'])->toBe(110101)
        ->and($diff['renamed'][0]['old_ext_name'])->toBe('东城区')
        ->and($diff['renamed'][0]['new_ext_name'])->toBe('东城新区');
});

it('id 相同 pid 不同判定为 parent_changed', function (): void {
    [$oldMap, $newMap] = diffFixtureMaps();
    $newMap[653223]['pid'] = 6500;

    $diff = app(DiffService::class)->diff($oldMap, $newMap);

    expect($diff['parent_changed'])->toHaveCount(1)
        ->and($diff['parent_changed'][0]['id'])->toBe(653223)
        ->and($diff['parent_changed'][0]['old_pid'])->toBe(6532)
        ->and($diff['parent_changed'][0]['new_pid'])->toBe(6500);
});

it('新增 id 命中历史废止行时判定为疑似代码重用（硬规则阻断）', function (): void {
    [$oldMap, $newMap] = diffFixtureMaps();

    // 历史废止行：假设 653228 曾经是被撤销的其他单位
    $retired = [653228 => ['ext_name' => '旧和康农场']];

    $diff = app(DiffService::class)->diff($oldMap, $newMap, $retired);

    expect($diff['code_reuse_suspected'])->toHaveCount(1)
        ->and($diff['code_reuse_suspected'][0]['id'])->toBe(653228)
        ->and($diff['code_reuse_suspected'][0]['previous_ext_name'])->toBe('旧和康农场');
});

it('diff.json 结构按省分组且携带阻断标记', function (): void {
    [$oldMap, $newMap] = diffFixtureMaps();

    $diffService = app(DiffService::class);
    $diff = $diffService->diff($oldMap, $newMap, [653228 => ['ext_name' => '旧单位']]);
    $json = $diffService->toJsonStructure($diff, $oldMap, $newMap, '2025.251231.260403', '2026.260101.260101');

    expect($json['blocked'])->toBeTrue()
        ->and($json['from_version'])->toBe('2025.251231.260403')
        ->and($json['summary']['added'])->toBe(4)
        ->and($json['provinces'])->toHaveKey('新疆维吾尔自治区')
        ->and($json['provinces']['新疆维吾尔自治区']['added'])->not->toBe([]);
});
