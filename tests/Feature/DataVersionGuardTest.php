<?php

declare(strict_types=1);

use Quansitech\Cmf\Area\Models\Area;
use Quansitech\Cmf\Area\Models\AreaChange;
use Quansitech\Cmf\Area\Services\AreaDataVersion;
use Quansitech\Cmf\Area\Services\ImportService;
use Quansitech\Cmf\Area\Services\MigrationExecutor;
use Quansitech\Cmf\Area\Services\MigrationGenerator;

/**
 * 数据版本守卫（payload.from_version）：全新安装导入的基线已包含变更时，
 * 历史 update 迁移必须跳过（否则 archive 类操作会误删有效行）；
 * 升级路径（版本匹配 / 空标记老项目）正常执行并推进标记。
 *
 * @return array<string, mixed>
 */
function guardPayload(array $override = []): array
{
    return [
        'version' => '2026.260101.260101',
        'from_version' => '2025.251231.260403',
        'journal' => true,
        'areas' => [
            ['op' => 'insert', 'id' => 65, 'pid' => 0, 'deep' => 0, 'name' => '新疆', 'pinyin_prefix' => 'x', 'pinyin' => 'xin jiang', 'ext_id' => 650000000000, 'ext_name' => '新疆维吾尔自治区'],
        ],
        'mappings' => [],
        'manual' => [],
        'records' => [],
        ...$override,
    ];
}

it('守卫跳过：库内数据版本已越过迁移基线（全新安装场景），update 不执行、标记不回写', function (): void {
    app(AreaDataVersion::class)->mark('2026.260101.260101'); // 新基线导入后写入的标记

    $result = app(MigrationExecutor::class)->apply(guardPayload());

    expect($result['affected'])->toBe([])
        ->and(Area::query()->find(65))->toBeNull()
        ->and(app(AreaDataVersion::class)->current())->toBe('2026.260101.260101')
        ->and(AreaChange::query()->where('version', '2026.260101.260101')->exists())->toBeFalse();
});

it('升级路径：库内版本与 from_version 一致，正常执行并推进标记', function (): void {
    app(AreaDataVersion::class)->mark('2025.251231.260403');

    $result = app(MigrationExecutor::class)->apply(guardPayload());

    expect($result['affected'])->toBe([])
        ->and(Area::query()->find(65))->not->toBeNull()
        ->and(app(AreaDataVersion::class)->current())->toBe('2026.260101.260101');
});

it('空标记放行：未接入守卫的老项目按原逻辑执行，执行后建立标记', function (): void {
    expect(app(AreaDataVersion::class)->current())->toBeNull();

    app(MigrationExecutor::class)->apply(guardPayload());

    expect(Area::query()->find(65))->not->toBeNull()
        ->and(app(AreaDataVersion::class)->current())->toBe('2026.260101.260101');
});

it('无 from_version 的旧格式 payload 不受守卫影响', function (): void {
    app(AreaDataVersion::class)->mark('2026.260101.260101');

    $payload = guardPayload();
    unset($payload['from_version']);

    app(MigrationExecutor::class)->apply($payload);

    expect(Area::query()->find(65))->not->toBeNull();
});

it('重复执行同一版本：标记已推进后第二次 apply 被守卫拦截（no-op）', function (): void {
    app(AreaDataVersion::class)->mark('2025.251231.260403');

    $executor = app(MigrationExecutor::class);
    $executor->apply(guardPayload());
    expect(Area::query()->find(65))->not->toBeNull();

    // 人为制造可重放现场：删除数据行，验证第二次 apply 确实被跳过而非重新写入
    Area::query()->where('id', 65)->delete();
    $result = $executor->apply(guardPayload());

    expect($result['affected'])->toBe([])
        ->and(Area::query()->find(65))->toBeNull();
});

it('被守卫跳过的迁移 revert 为空回放（up 未产生任何写入），不报错', function (): void {
    app(AreaDataVersion::class)->mark('2026.260101.260101');

    $executor = app(MigrationExecutor::class);
    $executor->apply(guardPayload());

    $result = $executor->revert(guardPayload());

    expect($result['stats'])->toBe(['area_restored' => 0, 'area_deleted' => 0, 'biz_restored' => 0, 'biz_scanned' => 0])
        ->and($result['skipped'])->toBeEmpty();
});

it('生成器冻结 from_version：取自生成时的 config data_version', function (): void {
    config()->set('cmf-area.data_version', '2025.251231.260403');

    $changes = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/data/changes_split.json'), true);
    $import = app(ImportService::class);
    $payload = app(MigrationGenerator::class)->payload(
        $changes,
        $import->loadCsvAsMap(__DIR__.'/../Fixtures/data/old_cmf_areas.csv'),
        $import->loadCsvAsMap(__DIR__.'/../Fixtures/data/new_cmf_areas.csv'),
    );

    expect($payload['from_version'])->toBe('2025.251231.260403');
});
