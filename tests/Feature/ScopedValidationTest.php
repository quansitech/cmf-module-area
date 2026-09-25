<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Quansitech\Cmf\Area\Services\ChangesValidator;
use Quansitech\Cmf\Area\Services\DiffRunner;

/**
 * scoped 校验（升级方案 §6.3/§6.5）：覆盖率只要求选中地区的事实 100% 认领。
 */

/**
 * 生成 fixture 的 diff 结构。
 *
 * @return array<string, mixed>
 */
function scopedDiffFixture(string $fromVersion = '2025.251231.260403'): array
{
    return app(DiffRunner::class)->run(
        __DIR__.'/../Fixtures/data/new_cmf_areas.csv',
        __DIR__.'/../Fixtures/data/old_cmf_areas.csv',
        $fromVersion,
        '2026.260101.260101',
    )['payload'];
}

/**
 * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
 */
function scopedMapsFixture(): array
{
    $import = app(\Quansitech\Cmf\Area\Services\ImportService::class);

    return [
        $import->loadCsvAsMap(__DIR__.'/../Fixtures/data/old_cmf_areas.csv'),
        $import->loadCsvAsMap(__DIR__.'/../Fixtures/data/new_cmf_areas.csv'),
    ];
}

it('scoped 校验：只勾选新疆时，北京未认领事实不阻断（§6.3）', function (): void {
    $changes = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/data/changes_split.json'), true);
    $changes['from_version'] = '2025.251231.260403';
    $changes['version'] = '2026.260101.260101'; // = diff.to_version（对齐候选）

    [$oldMap, $newMap] = scopedMapsFixture();
    $errors = app(ChangesValidator::class)->validate($changes, scopedDiffFixture(), $oldMap, $newMap, [65]);

    expect($errors)->toBe([]);
});

it('scoped 校验：只勾选北京时，北京未认领事实仍阻断', function (): void {
    $changes = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/data/changes_split.json'), true);
    array_pop($changes['changes']); // 弹掉 110105 的认领，模拟北京未判读
    $changes['from_version'] = '2025.251231.260403';
    $changes['version'] = '2026.260101.260101';

    [$oldMap, $newMap] = scopedMapsFixture();
    $errors = app(ChangesValidator::class)->validate($changes, scopedDiffFixture(), $oldMap, $newMap, [11]);

    expect(implode("\n", $errors))->toContain('110105');
});

it('全量校验：北京与新疆都须认领（与 scoped 对比）', function (): void {
    $changes = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/data/changes_split.json'), true);
    array_pop($changes['changes']);
    $changes['from_version'] = '2025.251231.260403';
    $changes['version'] = '2026.260101.260101';

    [$oldMap, $newMap] = scopedMapsFixture();
    $errors = app(ChangesValidator::class)->validate($changes, scopedDiffFixture(), $oldMap, $newMap, null);

    expect(implode("\n", $errors))->toContain('110105');
});

it('版本契约：部分地区发版允许 {基线}+N 版本号（§3.3）', function (): void {
    $changes = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/data/changes_split.json'), true);
    $changes['from_version'] = '2025.251231.260403';
    $changes['version'] = '2025.251231.260403+1'; // 自有版本号（部分地区发版）

    [$oldMap, $newMap] = scopedMapsFixture();
    $errors = app(ChangesValidator::class)->validate($changes, scopedDiffFixture(), $oldMap, $newMap, [65]);

    expect($errors)->toBe([]);
});

it('版本契约：version 既非上游 tag 也非基线递增时被拒绝', function (): void {
    $changes = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/data/changes_split.json'), true);
    $changes['from_version'] = '2025.251231.260403';
    $changes['version'] = '2999.010101.010101';

    [$oldMap, $newMap] = scopedMapsFixture();
    $errors = app(ChangesValidator::class)->validate($changes, scopedDiffFixture(), $oldMap, $newMap, [65]);

    expect(implode("\n", $errors))->toContain('版本不一致');
});

it('版本契约：from_version 与 diff 不一致被拒绝', function (): void {
    $changes = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/data/changes_split.json'), true);
    $changes['from_version'] = '1999.010101.010101';
    $changes['version'] = '2026.260101.260101';

    [$oldMap, $newMap] = scopedMapsFixture();
    $errors = app(ChangesValidator::class)->validate($changes, scopedDiffFixture(), $oldMap, $newMap, [65]);

    expect(implode("\n", $errors))->toContain('from_version');
});

it('版本契约：基线已带 +N 后缀时递增为 +N+1', function (): void {
    expect(ChangesValidator::incrementVersion('2025.251231.260403'))->toBe('2025.251231.260403+1')
        ->and(ChangesValidator::incrementVersion('2025.251231.260403+1'))->toBe('2025.251231.260403+2');
});

it('area:check-changes 支持 --scope 参数', function (): void {
    Artisan::call('area:diff', [
        'new_csv' => __DIR__.'/../Fixtures/data/new_cmf_areas.csv',
        '--old' => __DIR__.'/../Fixtures/data/old_cmf_areas.csv',
        '--output' => sys_get_temp_dir().'/diff_scoped.json',
        '--to-version' => '2026.260101.260101',
    ]);

    $exit = Artisan::call('area:check-changes', [
        'changes' => __DIR__.'/../Fixtures/data/changes_split.json',
        '--diff' => sys_get_temp_dir().'/diff_scoped.json',
        '--old' => __DIR__.'/../Fixtures/data/old_cmf_areas.csv',
        '--new' => __DIR__.'/../Fixtures/data/new_cmf_areas.csv',
        '--scope' => '65',
    ]);

    expect($exit)->toBe(0);
});
