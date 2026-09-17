<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('合法 changes.json 通过全部校验', function (): void {
    $exit = Artisan::call('area:check-changes', [
        'changes' => __DIR__.'/../Fixtures/data/changes_split.json',
        '--old' => __DIR__.'/../Fixtures/data/old_cmf_areas.csv',
        '--new' => __DIR__.'/../Fixtures/data/new_cmf_areas.csv',
    ]);

    expect($exit)->toBe(0);
});

it('缺 evidence 的判定被拒绝', function (): void {
    $path = sys_get_temp_dir().'/changes_no_evidence.json';
    file_put_contents($path, json_encode([
        'version' => '2026.260101.260101',
        'changes' => [
            ['change_type' => 'add', 'new_id' => 110105, 'detail' => []],
        ],
    ]));

    $exit = Artisan::call('area:check-changes', ['changes' => $path]);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('evidence');
});

it('merge_into 缺 full_transfer 被拒绝', function (): void {
    $path = sys_get_temp_dir().'/changes_merge_no_ft.json';
    file_put_contents($path, json_encode([
        'version' => '2026.260101.260101',
        'changes' => [
            [
                'change_type' => 'merge_into',
                'old_id' => 653223, 'new_id' => 653200,
                'detail' => ['summary' => '并入'],
                'evidence' => [['title' => 't', 'url' => 'https://example.com']],
            ],
        ],
    ]));

    $exit = Artisan::call('area:check-changes', ['changes' => $path]);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('full_transfer');
});

it('diff 事实无人认领被拒绝（覆盖率）', function (): void {
    // 该 changes.json 只认领了 split，未认领 110105（朝阳区 add）
    $path = sys_get_temp_dir().'/changes_partial.json';
    $changes = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/data/changes_split.json'), true);
    array_pop($changes['changes']);
    file_put_contents($path, json_encode($changes));

    // 先生成 diff.json
    Artisan::call('area:diff', [
        'new_csv' => __DIR__.'/../Fixtures/data/new_cmf_areas.csv',
        '--old' => __DIR__.'/../Fixtures/data/old_cmf_areas.csv',
        '--output' => sys_get_temp_dir().'/diff_partial.json',
        '--to-version' => '2026.260101.260101',
    ]);

    $exit = Artisan::call('area:check-changes', [
        'changes' => $path,
        '--diff' => sys_get_temp_dir().'/diff_partial.json',
        '--old' => __DIR__.'/../Fixtures/data/old_cmf_areas.csv',
        '--new' => __DIR__.'/../Fixtures/data/new_cmf_areas.csv',
    ]);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('未被任何 change 认领');
});

it('版本不一致被拒绝', function (): void {
    Artisan::call('area:diff', [
        'new_csv' => __DIR__.'/../Fixtures/data/new_cmf_areas.csv',
        '--old' => __DIR__.'/../Fixtures/data/old_cmf_areas.csv',
        '--output' => sys_get_temp_dir().'/diff_ver.json',
        '--to-version' => '2999.999999.999999',
    ]);

    $exit = Artisan::call('area:check-changes', [
        'changes' => __DIR__.'/../Fixtures/data/changes_split.json',
        '--diff' => sys_get_temp_dir().'/diff_ver.json',
    ]);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('版本不一致');
});

it('rename 类型与事实不符被拒绝', function (): void {
    $path = sys_get_temp_dir().'/changes_bad_rename.json';
    file_put_contents($path, json_encode([
        'version' => '2026.260101.260101',
        'changes' => [
            [
                'change_type' => 'rename',
                'old_id' => 653223, 'new_id' => 653228,
                'detail' => ['summary' => 'x'],
                'evidence' => [['title' => 't', 'url' => 'https://example.com']],
            ],
        ],
    ]));

    $exit = Artisan::call('area:check-changes', [
        'changes' => $path,
        '--old' => __DIR__.'/../Fixtures/data/old_cmf_areas.csv',
        '--new' => __DIR__.'/../Fixtures/data/new_cmf_areas.csv',
    ]);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('old_id == new_id');
});
