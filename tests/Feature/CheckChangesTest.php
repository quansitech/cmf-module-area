<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('合法 changes.json（v3）通过全部校验', function (): void {
    $exit = Artisan::call('area:check-changes', [
        'changes' => __DIR__.'/../Fixtures/data/changes_split.json',
        '--old' => __DIR__.'/../Fixtures/data/old_cmf_areas.csv',
        '--new' => __DIR__.'/../Fixtures/data/new_cmf_areas.csv',
    ]);

    expect($exit)->toBe(0);
});

it('v1 格式（change_type/detail）被拒绝：schema_version 必须为 3', function (): void {
    $path = sys_get_temp_dir().'/changes_v1.json';
    file_put_contents($path, json_encode([
        'version' => '2026.260101.260101',
        'changes' => [
            [
                'change_type' => 'add',
                'new_id' => 110105,
                'detail' => ['summary' => 'x'],
                'evidence' => [['title' => 't', 'url' => 'https://example.com']],
            ],
        ],
    ]));

    $exit = Artisan::call('area:check-changes', ['changes' => $path]);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('schema_version');
});

it('缺 evidence 的判定被拒绝', function (): void {
    $path = sys_get_temp_dir().'/changes_no_evidence.json';
    file_put_contents($path, json_encode([
        'schema_version' => 3,
        'version' => '2026.260101.260101',
        'changes' => [
            ['kind' => 'node', 'id' => 110105, 'state' => 'appeared'],
        ],
    ]));

    $exit = Artisan::call('area:check-changes', ['changes' => $path]);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('evidence');
});

it('退化边（from_id == to_id）被拒绝', function (): void {
    $path = sys_get_temp_dir().'/changes_degenerate_edge.json';
    file_put_contents($path, json_encode([
        'schema_version' => 3,
        'version' => '2026.260101.260101',
        'evidence' => ['ev' => ['title' => 't', 'url' => 'https://example.com']],
        'changes' => [
            [
                'kind' => 'edge', 'from_id' => 653223, 'to_id' => 653223,
                'evidence' => ['ev'],
            ],
        ],
    ]));

    $exit = Artisan::call('area:check-changes', [
        'changes' => $path,
        '--old' => __DIR__.'/../Fixtures/data/old_cmf_areas.csv',
        '--new' => __DIR__.'/../Fixtures/data/new_cmf_areas.csv',
    ]);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('退化边');
});

it('diff 事实无人认领被拒绝（按侧覆盖率）', function (): void {
    // 该 changes.json 只认领了 split 相关事实，未认领 110105（朝阳区 add）
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
    expect(Artisan::output())->toContain('未被新侧认领');
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

it('多源合并：拍平边共同覆盖新单位下级即可通过', function (): void {
    // 微缩版 2025 重庆：撤江北区（500105）、渝北区（500112），合设两江新区（500157）
    Artisan::call('area:diff', [
        'new_csv' => __DIR__.'/../Fixtures/data/new_cmf_areas_merge.csv',
        '--old' => __DIR__.'/../Fixtures/data/old_cmf_areas_merge.csv',
        '--output' => sys_get_temp_dir().'/diff_merge_multi.json',
        '--to-version' => '2026.260101.260101',
    ]);

    $exit = Artisan::call('area:check-changes', [
        'changes' => __DIR__.'/../Fixtures/data/changes_merge_multi.json',
        '--diff' => sys_get_temp_dir().'/diff_merge_multi.json',
        '--old' => __DIR__.'/../Fixtures/data/old_cmf_areas_merge.csv',
        '--new' => __DIR__.'/../Fixtures/data/new_cmf_areas_merge.csv',
    ]);

    expect($exit)->toBe(0)->and(Artisan::output())->toContain('校验通过');
});

it('错挂他区下级：原单位的旧侧事实无人认领被拒绝（v2 残余捕获机制）', function (): void {
    // 复刻真实事故的 v2 形态：把渝北区下级 500112001 错挂成"来自江北区"——
    // v2 没有容器归属判定，但错挂后 500112001 的旧侧事实将无人认领
    $path = sys_get_temp_dir().'/changes_merge_wrong_from.json';
    $changes = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/data/changes_merge_multi.json'), true);
    foreach ($changes['changes'] as $i => $item) {
        if (($item['kind'] ?? null) === 'edge' && $item['from_id'] === 500112001) {
            $changes['changes'][$i]['from_id'] = 500105001; // 错挂：与江北区下级重复认领
        }
    }
    file_put_contents($path, json_encode($changes));

    Artisan::call('area:diff', [
        'new_csv' => __DIR__.'/../Fixtures/data/new_cmf_areas_merge.csv',
        '--old' => __DIR__.'/../Fixtures/data/old_cmf_areas_merge.csv',
        '--output' => sys_get_temp_dir().'/diff_merge_wrong.json',
        '--to-version' => '2026.260101.260101',
    ]);

    $exit = Artisan::call('area:check-changes', [
        'changes' => $path,
        '--diff' => sys_get_temp_dir().'/diff_merge_wrong.json',
        '--old' => __DIR__.'/../Fixtures/data/old_cmf_areas_merge.csv',
        '--new' => __DIR__.'/../Fixtures/data/new_cmf_areas_merge.csv',
    ]);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('500112001')->toContain('未被旧侧认领');
});

it('多源合并仍有新增下级无人认领时被拒绝', function (): void {
    $path = sys_get_temp_dir().'/changes_merge_missing.json';
    $changes = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/data/changes_merge_multi.json'), true);
    foreach ($changes['changes'] as $i => $item) {
        if (($item['kind'] ?? null) === 'edge' && $item['from_id'] === 500112002) {
            unset($changes['changes'][$i]); // 漏掉渝北区龙溪街道的对应边
        }
    }
    $changes['changes'] = array_values($changes['changes']);
    file_put_contents($path, json_encode($changes));

    Artisan::call('area:diff', [
        'new_csv' => __DIR__.'/../Fixtures/data/new_cmf_areas_merge.csv',
        '--old' => __DIR__.'/../Fixtures/data/old_cmf_areas_merge.csv',
        '--output' => sys_get_temp_dir().'/diff_merge_missing.json',
        '--to-version' => '2026.260101.260101',
    ]);

    $exit = Artisan::call('area:check-changes', [
        'changes' => $path,
        '--diff' => sys_get_temp_dir().'/diff_merge_missing.json',
        '--old' => __DIR__.'/../Fixtures/data/old_cmf_areas_merge.csv',
        '--new' => __DIR__.'/../Fixtures/data/new_cmf_areas_merge.csv',
    ]);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('未被旧侧认领');
});

it('continued node 声明了无变化字段被拒绝（attributes 只声明发生变化的字段）', function (): void {
    $path = sys_get_temp_dir().'/changes_bad_continued.json';
    file_put_contents($path, json_encode([
        'schema_version' => 3,
        'version' => '2026.260101.260101',
        'evidence' => ['ev' => ['title' => 't', 'url' => 'https://example.com']],
        'changes' => [
            [
                'kind' => 'node', 'id' => 653223, 'state' => 'continued',
                'attributes' => ['ext_name'], // 与 csv 事实不符（两版 ext_name 均为皮山县）
                'evidence' => ['ev'],
            ],
        ],
    ]));

    $exit = Artisan::call('area:check-changes', [
        'changes' => $path,
        '--old' => __DIR__.'/../Fixtures/data/old_cmf_areas.csv',
        '--new' => __DIR__.'/../Fixtures/data/new_cmf_areas.csv',
    ]);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('无变化');
});

it('手写 change_type 被拒绝（v3 纯派生，禁手写）', function (): void {
    $path = sys_get_temp_dir().'/changes_bad_label.json';
    $changes = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/data/changes_split.json'), true);
    // v3 可写契约内没有 change_type（纯派生字段）
    $changes['changes'][0]['change_type'] = 'add';
    file_put_contents($path, json_encode($changes));

    $exit = Artisan::call('area:check-changes', [
        'changes' => $path,
        '--old' => __DIR__.'/../Fixtures/data/old_cmf_areas.csv',
        '--new' => __DIR__.'/../Fixtures/data/new_cmf_areas.csv',
    ]);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('change_type 字段未定义');
});

it('手写 side 被拒绝（v3 由 state 派生）', function (): void {
    $path = sys_get_temp_dir().'/changes_bad_side.json';
    $changes = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/data/changes_split.json'), true);
    // v3 可写契约内没有 side（retired→old、appeared→new、continued 单侧书写）
    $changes['changes'][0]['side'] = 'new';
    file_put_contents($path, json_encode($changes));

    $exit = Artisan::call('area:check-changes', [
        'changes' => $path,
        '--old' => __DIR__.'/../Fixtures/data/old_cmf_areas.csv',
        '--new' => __DIR__.'/../Fixtures/data/new_cmf_areas.csv',
    ]);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('side 字段未定义');
});

it('证据池引用不存在被拒绝', function (): void {
    $path = sys_get_temp_dir().'/changes_bad_evidence_ref.json';
    $changes = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/data/changes_split.json'), true);
    $changes['changes'][0]['evidence'] = ['not-exists'];
    file_put_contents($path, json_encode($changes));

    $exit = Artisan::call('area:check-changes', [
        'changes' => $path,
        '--old' => __DIR__.'/../Fixtures/data/old_cmf_areas.csv',
        '--new' => __DIR__.'/../Fixtures/data/new_cmf_areas.csv',
    ]);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('not-exists')->toContain('不存在');
});
