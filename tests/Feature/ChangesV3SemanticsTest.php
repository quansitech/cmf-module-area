<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Quansitech\Cmf\Area\Models\Area;
use Quansitech\Cmf\Area\Services\ImportService;
use Quansitech\Cmf\Area\Services\MigrationExecutor;
use Quansitech\Cmf\Area\Services\MigrationGenerator;
use Quansitech\Cmf\Area\Tests\Fixtures\Biz\Models\Order;
use Quansitech\Cmf\Area\Tests\Fixtures\Biz\Models\Store;

/**
 * changes.json v3 语义不变量用例（docs/changes-json-semantics.md §4.6 +
 * docs/area-precise-rollback-and-changes-v3.md §6）：跨层边、exceptions 挂点、
 * id_reuse 声明、渝北式 partial、异侧认领、id_reuse 链执行顺序、复用环禁环。
 * v3：side 由 state 派生、change_type 纯派生禁手写、attributes 为字段名清单、
 * evidence 为文件级证据池引用（下级边可继承单位级边）。
 */

/**
 * 三沙案例 fixture：西沙区 460301→460302、南沙区 460302→460303（id 460302 复用）。
 *
 * @return array{old: string, new: string, changes: array<string, mixed>}
 */
function sanshaFixtures(): array
{
    $old = writeAreaCsvFixture('v3_sansha_old.csv', [
        [46, 0, 0, '海南', '海南省'],
        [4603, 46, 1, '三沙', '三沙市'],
        [460301, 4603, 2, '西沙', '西沙区'],
        [460301000, 460301, 3, '永兴', '永兴社区'],
        [460302, 4603, 2, '南沙', '南沙区'],
        [460302000, 460302, 3, '永暑', '永暑社区'],
    ]);
    $new = writeAreaCsvFixture('v3_sansha_new.csv', [
        [46, 0, 0, '海南', '海南省'],
        [4603, 46, 1, '三沙', '三沙市'],
        [460302, 4603, 2, '西沙', '西沙区'],   // id 复用：旧 460302 是南沙区
        [460302000, 460302, 3, '永兴', '永兴社区'],
        [460303, 4603, 2, '南沙', '南沙区'],
        [460303000, 460303, 3, '永暑', '永暑社区'],
    ]);

    $changes = [
        'schema_version' => 3,
        'version' => '2026.260101.260101',
        'evidence' => ['ev-sansha' => ['title' => '民政部公告', 'url' => 'https://example.com/sansha']],
        'changes' => [
            ['kind' => 'node', 'id' => 460301, 'name' => '西沙区', 'state' => 'retired',
                'summary' => '西沙区代码调整', 'evidence' => ['ev-sansha']],
            ['kind' => 'node', 'id' => 460302, 'name' => '南沙区', 'state' => 'retired',
                'summary' => '南沙区代码调整', 'evidence' => ['ev-sansha']],
            ['kind' => 'node', 'id' => 460302, 'name' => '西沙区', 'state' => 'appeared',
                'id_reuse' => true,
                'summary' => '链式复用：旧 460302 为南沙区（已迁至 460303 存续），新 460302 为西沙区（自 460301 换码）',
                'evidence' => ['ev-sansha']],
            ['kind' => 'node', 'id' => 460303, 'name' => '南沙区', 'state' => 'appeared',
                'summary' => '南沙区启用新码', 'evidence' => ['ev-sansha']],
            // 刻意把南沙的边写在前面：拓扑排序必须把它排到西沙的边之前执行（§2.5）
            ['kind' => 'edge', 'from_id' => 460302, 'to_id' => 460303, 'summary' => '南沙区换码', 'evidence' => ['ev-sansha']],
            ['kind' => 'edge', 'from_id' => 460302000, 'to_id' => 460303000, 'summary' => '南沙区下级换码', 'evidence' => ['ev-sansha']],
            ['kind' => 'edge', 'from_id' => 460301, 'to_id' => 460302, 'summary' => '西沙区换码', 'evidence' => ['ev-sansha']],
            ['kind' => 'edge', 'from_id' => 460301000, 'to_id' => 460302000, 'summary' => '西沙区下级换码', 'evidence' => ['ev-sansha']],
        ],
    ];

    return ['old' => $old, 'new' => $new, 'changes' => $changes];
}

/**
 * 渝北式 partial fixture：渝北区（500112）撤销，2 个街道并入两江新区（500157）、
 * 1 个镇划归北碚区（500109）——unit_mapping 因覆盖条款不成立（疆域旁落）。
 *
 * @return array{old: string, new: string, changes: array<string, mixed>}
 */
function yubeiFixtures(): array
{
    $old = writeAreaCsvFixture('v3_yubei_old.csv', [
        [50, 0, 0, '重庆', '重庆市'],
        [5001, 50, 1, '重庆', '重庆市'],
        [500109, 5001, 2, '北碚', '北碚区'],
        [500112, 5001, 2, '渝北', '渝北区'],
        [500112001, 500112, 3, '双凤桥', '双凤桥街道'],
        [500112002, 500112, 3, '龙溪', '龙溪街道'],
        [500112113, 500112, 3, '大湾', '大湾镇'],
    ]);
    $new = writeAreaCsvFixture('v3_yubei_new.csv', [
        [50, 0, 0, '重庆', '重庆市'],
        [5001, 50, 1, '重庆', '重庆市'],
        [500109, 5001, 2, '北碚', '北碚区'],
        [500109126, 500109, 3, '大湾', '大湾镇'],
        [500157, 5001, 2, '两江新区', '两江新区'],
        [500157006, 500157, 3, '双凤桥', '双凤桥街道'],
        [500157007, 500157, 3, '龙溪', '龙溪街道'],
    ]);

    $changes = [
        'schema_version' => 3,
        'version' => '2026.260101.260101',
        'evidence' => ['ev-cq' => ['title' => '国务院批复', 'url' => 'https://example.com/cq']],
        'changes' => [
            ['kind' => 'node', 'id' => 500112, 'name' => '渝北区', 'state' => 'retired',
                'summary' => '撤销渝北区：2 个街道并入两江新区，1 个镇划归北碚区，单位无单一承继者', 'evidence' => ['ev-cq']],
            ['kind' => 'node', 'id' => 500157, 'name' => '两江新区', 'state' => 'appeared',
                'summary' => '设立两江新区', 'evidence' => ['ev-cq']],
            ['kind' => 'edge', 'from_id' => 500112, 'to_id' => 500157, 'summary' => '渝北区大部分区域并入两江新区', 'evidence' => ['ev-cq']],
            ['kind' => 'edge', 'from_id' => 500112001, 'to_id' => 500157006, 'summary' => '双凤桥街道并入两江新区', 'evidence' => ['ev-cq']],
            ['kind' => 'edge', 'from_id' => 500112002, 'to_id' => 500157007, 'summary' => '龙溪街道并入两江新区', 'evidence' => ['ev-cq']],
            ['kind' => 'edge', 'from_id' => 500112113, 'to_id' => 500109126, 'summary' => '渝北区大湾镇划归北碚区', 'evidence' => ['ev-cq']],
        ],
    ];

    return ['old' => $old, 'new' => $new, 'changes' => $changes];
}

beforeEach(function (): void {
    migrateAreaSchema();
    app(\Quansitech\Cmf\Area\Services\ReferenceCollector::class)->sync();
});

it('① 跨层边未声明 cross_level_reason 被拒绝（I3），声明后通过', function (): void {
    $old = writeAreaCsvFixture('v3_lvl_old.csv', [
        [10, 0, 0, '测试省', '测试省'],
        [1000, 10, 1, '测试市', '测试市'],
        [100001, 1000, 2, '旧镇', '旧镇'],
    ]);
    $new = writeAreaCsvFixture('v3_lvl_new.csv', [
        [10, 0, 0, '测试省', '测试省'],
        [1000, 10, 1, '测试市', '测试市'],
        [200001, 1000, 2, '新镇', '新镇'],
        [200001001, 200001, 3, '新社区', '新社区'],
    ]);

    $changes = [
        'schema_version' => 3,
        'version' => '2026.260101.260101',
        'evidence' => ['ev' => ['title' => 't', 'url' => 'https://example.com']],
        'changes' => [
            ['kind' => 'edge', 'from_id' => 100001, 'to_id' => 200001001, // deep 2 → deep 3 跨层
                'evidence' => ['ev']],
        ],
    ];
    $path = sys_get_temp_dir().'/v3_cross_level.json';
    file_put_contents($path, json_encode($changes));

    $exit = Artisan::call('area:check-changes', ['changes' => $path, '--old' => $old, '--new' => $new]);
    expect($exit)->toBe(1)->and(Artisan::output())->toContain('cross_level_reason');

    $changes['changes'][0]['cross_level_reason'] = '镇改社区，层级下沉';
    file_put_contents($path, json_encode($changes));
    $exit = Artisan::call('area:check-changes', ['changes' => $path, '--old' => $old, '--new' => $new]);
    expect($exit)->toBe(0);
});

it('② exceptions 挂到非本侧/非本子树的 node 被拒绝（I4）', function (): void {
    $f = yubeiFixtures();

    // 情形一：例外 id 不是本节点同侧子树（500157005 不是 500112 的旧版下级）
    $changes = $f['changes'];
    $changes['changes'][0]['exceptions'] = [['id' => 500157005, 'reason' => '错挂']];
    $path = sys_get_temp_dir().'/v3_ex_wrong.json';
    file_put_contents($path, json_encode($changes));

    $exit = Artisan::call('area:check-changes', ['changes' => $path, '--old' => $f['old'], '--new' => $f['new']]);
    expect($exit)->toBe(1)->and(Artisan::output())->toContain('同侧子树');

    // 情形二：例外 id 在对侧仍存在（500109126 在新版存在，不能挂 retired node）
    $changes = $f['changes'];
    $changes['changes'][0]['exceptions'] = [['id' => 500109126, 'reason' => '错挂']];
    file_put_contents($path, json_encode($changes));

    $exit = Artisan::call('area:check-changes', ['changes' => $path, '--old' => $f['old'], '--new' => $f['new']]);
    expect($exit)->toBe(1)->and(Artisan::output())->toContain('在新版仍存在');
});

it('③ 三沙案例：同 id 既是 from 又是 to 但未声明 id_reuse 被拒绝（I7）', function (): void {
    $f = sanshaFixtures();

    // 摘掉 id_reuse 声明
    $changes = $f['changes'];
    foreach ($changes['changes'] as $i => $item) {
        if (($item['kind'] ?? null) === 'node' && ($item['id'] ?? null) === 460302 && ($item['state'] ?? null) === 'appeared') {
            unset($changes['changes'][$i]['id_reuse']);
        }
    }
    $path = sys_get_temp_dir().'/v3_sansha_no_reuse.json';
    file_put_contents($path, json_encode($changes));

    $exit = Artisan::call('area:check-changes', ['changes' => $path, '--old' => $f['old'], '--new' => $f['new']]);
    expect($exit)->toBe(1)->and(Artisan::output())->toContain('id 复用未显式声明');

    // 声明后通过
    $path = sys_get_temp_dir().'/v3_sansha_ok.json';
    file_put_contents($path, json_encode($f['changes']));
    $exit = Artisan::call('area:check-changes', ['changes' => $path, '--old' => $f['old'], '--new' => $f['new']]);
    expect($exit)->toBe(0);
});

it('④ 渝北式 partial：单位级对进人工清单，下级边照常执行（§1.4 修复）', function (): void {
    $f = yubeiFixtures();

    // 校验通过（30 条下级边形态的微缩版）
    $path = sys_get_temp_dir().'/v3_yubei.json';
    file_put_contents($path, json_encode($f['changes']));
    $exit = Artisan::call('area:check-changes', ['changes' => $path, '--old' => $f['old'], '--new' => $f['new']]);
    expect($exit)->toBe(0);

    $import = app(ImportService::class);
    $payload = app(MigrationGenerator::class)->payload(
        $f['changes'], $import->loadCsvAsMap($f['old']), $import->loadCsvAsMap($f['new']));

    // 单位级对 500112→500157 不在 mappings（unit_mapping 因覆盖条款不成立）
    $mappingPairs = array_map(fn (array $m): string => "{$m['from']}→{$m['to']}", $payload['mappings']);
    expect($mappingPairs)->not->toContain('500112→500157')
        // 3 条下级边照常进 mappings
        ->toContain('500112001→500157006')
        ->toContain('500112002→500157007')
        ->toContain('500112113→500109126');

    // 人工清单：partial_transfer 附未覆盖原因
    expect($payload['manual'])->toHaveCount(1)
        ->and($payload['manual'][0]['reason'])->toBe('partial_transfer')
        ->and($payload['manual'][0]['hint'])->toContain('疆域旁落');

    // 执行：下级边全部改写，浅层值不动
    app(ImportService::class)->import($f['old']);
    Store::create(['title' => '双凤桥店', 'area_id' => 500112001]);
    Store::create(['title' => '大湾店', 'area_id' => 500112113]);
    Store::create(['title' => '渝北店', 'area_id' => 500112]);

    app(MigrationExecutor::class)->apply($payload);

    expect(Store::query()->where('title', '双凤桥店')->first()->area_id)->toBe(500157006)
        ->and(Store::query()->where('title', '大湾店')->first()->area_id)->toBe(500109126)
        ->and(Store::query()->where('title', '渝北店')->first()->area_id)->toBe(500112)
        ->and(Area::query()->find(500157)->ext_name)->toBe('两江新区')
        ->and(Area::query()->find(500112)->status)->toBe(0);
});

it('⑤ renamed 事实只被旧侧认领时被拒绝（I5 按侧覆盖）', function (): void {
    $old = writeAreaCsvFixture('v3_side_old.csv', [
        [10, 0, 0, '测试省', '测试省'],
        [1000, 10, 1, '测试市', '测试市'],
        [100001, 1000, 2, '旧名', '旧名镇'],
    ]);
    $new = writeAreaCsvFixture('v3_side_new.csv', [
        [10, 0, 0, '测试省', '测试省'],
        [1000, 10, 1, '测试市', '测试市'],
        [100001, 1000, 2, '新名', '新名镇'],   // renamed：id 两版均在、ext_name 变化
        [100002, 1000, 2, '另一镇', '另一镇'],
    ]);

    Artisan::call('area:diff', [
        'new_csv' => $new, '--old' => $old,
        '--output' => sys_get_temp_dir().'/v3_side_diff.json', '--to-version' => '2026.260101.260101',
    ]);

    // 只给旧侧认领（edge from），新侧没有任何 100001 的认领
    $changes = [
        'schema_version' => 3,
        'version' => '2026.260101.260101',
        'evidence' => ['ev' => ['title' => 't', 'url' => 'https://example.com']],
        'changes' => [
            ['kind' => 'edge', 'from_id' => 100001, 'to_id' => 100002,
                'summary' => '错误示例：renamed 事实的新侧无人认领', 'evidence' => ['ev']],
        ],
    ];
    $path = sys_get_temp_dir().'/v3_side.json';
    file_put_contents($path, json_encode($changes));

    $exit = Artisan::call('area:check-changes', [
        'changes' => $path, '--diff' => sys_get_temp_dir().'/v3_side_diff.json',
        '--old' => $old, '--new' => $new,
    ]);

    expect($exit)->toBe(1)->and(Artisan::output())->toContain('未被新侧认领');
});

it('⑥ id_reuse 链按拓扑序执行：南沙数据正确落 460303，不错乱（§2.5）', function (): void {
    $f = sanshaFixtures();
    app(ImportService::class)->import($f['old']);

    $import = app(ImportService::class);
    $payload = app(MigrationGenerator::class)->payload(
        $f['changes'], $import->loadCsvAsMap($f['old']), $import->loadCsvAsMap($f['new']));

    // payload 自证执行序：南沙换码必须先于西沙换码（e_out(460302) ≺ e_in(460302)）
    $mappingPairs = array_map(fn (array $m): string => "{$m['from']}→{$m['to']}", $payload['mappings']);
    expect(array_search('460302→460303', $mappingPairs))->toBeLessThan(array_search('460301→460302', $mappingPairs));
    expect(array_search('460302000→460303000', $mappingPairs))->toBeLessThan(array_search('460301000→460302000', $mappingPairs));

    // 业务数据：南沙区、西沙区各自的存量行（remap 列）
    Store::create(['title' => '南沙店', 'area_id' => 460302]);
    Store::create(['title' => '西沙店', 'area_id' => 460301]);

    app(MigrationExecutor::class)->apply($payload);

    // 南沙业务行落到 460303（南沙新码），西沙业务行落到 460302（西沙新码）
    expect(Store::query()->where('title', '南沙店')->first()->area_id)->toBe(460303)
        ->and(Store::query()->where('title', '西沙店')->first()->area_id)->toBe(460302)
        // 结构：460302 现在是西沙区（复用），460303 是南沙区，旧 460301 已退休
        ->and(Area::query()->find(460302)->ext_name)->toBe('西沙区')
        ->and(Area::query()->find(460302)->status)->toBe(1)
        ->and(Area::query()->find(460303)->ext_name)->toBe('南沙区')
        ->and(Area::query()->find(460301)->status)->toBe(0);
});

it('⑥（反例）错误顺序执行同一份映射：西沙业务行被裹进 460303，数据不可逆错乱', function (): void {
    $f = sanshaFixtures();
    app(ImportService::class)->import($f['old']);

    $import = app(ImportService::class);
    $payload = app(MigrationGenerator::class)->payload(
        $f['changes'], $import->loadCsvAsMap($f['old']), $import->loadCsvAsMap($f['new']));

    // 人为打乱成错误顺序（先执行西沙换码）——证明 §2.5 排序不是可选项
    usort($payload['mappings'], fn (array $a, array $b): int => ($a['from'] === 460301 ? -1 : 0) <=> ($b['from'] === 460301 ? -1 : 0));

    Store::create(['title' => '南沙店', 'area_id' => 460302]);
    Store::create(['title' => '西沙店', 'area_id' => 460301]);

    app(MigrationExecutor::class)->apply($payload);

    // 错误顺序下：西沙行先被改写成 460302，随后被南沙映射一起扫进 460303
    expect(Store::query()->where('title', '西沙店')->first()->area_id)->toBe(460303);
});

it('⑦ 复用环（A→B、B→A 互换）校验报错转人工（§2.5 禁环）', function (): void {
    $old = writeAreaCsvFixture('v3_ring_old.csv', [
        [10, 0, 0, '测试省', '测试省'],
        [1000, 10, 1, '测试市', '测试市'],
        [100001, 1000, 2, '甲', '甲镇'],
        [100002, 1000, 2, '乙', '乙镇'],
    ]);
    $new = writeAreaCsvFixture('v3_ring_new.csv', [
        [10, 0, 0, '测试省', '测试省'],
        [1000, 10, 1, '测试市', '测试市'],
        [100001, 1000, 2, '乙', '乙镇'],   // 环形换码：新 100001 是原乙镇
        [100002, 1000, 2, '甲', '甲镇'],   // 新 100002 是原甲镇
    ]);

    $changes = [
        'schema_version' => 3,
        'version' => '2026.260101.260101',
        'evidence' => ['ev' => ['title' => 't', 'url' => 'https://example.com']],
        'changes' => [
            ['kind' => 'node', 'id' => 100001, 'state' => 'retired', 'evidence' => ['ev']],
            ['kind' => 'node', 'id' => 100002, 'state' => 'retired', 'evidence' => ['ev']],
            ['kind' => 'node', 'id' => 100001, 'state' => 'appeared', 'id_reuse' => true,
                'summary' => '环形换码：新 100001 为原乙镇', 'evidence' => ['ev']],
            ['kind' => 'node', 'id' => 100002, 'state' => 'appeared', 'id_reuse' => true,
                'summary' => '环形换码：新 100002 为原甲镇', 'evidence' => ['ev']],
            ['kind' => 'edge', 'from_id' => 100001, 'to_id' => 100002, 'summary' => '甲镇换码', 'evidence' => ['ev']],
            ['kind' => 'edge', 'from_id' => 100002, 'to_id' => 100001, 'summary' => '乙镇换码', 'evidence' => ['ev']],
        ],
    ];
    $path = sys_get_temp_dir().'/v3_ring.json';
    file_put_contents($path, json_encode($changes));

    $exit = Artisan::call('area:check-changes', ['changes' => $path, '--old' => $old, '--new' => $new]);

    expect($exit)->toBe(1)->and(Artisan::output())->toContain('成环');
});

it('⑧ continued node 的属性变化生成 rename/reparent 结构操作（attributes 字段名清单，值从 csv 派生）', function (): void {
    $old = writeAreaCsvFixture('v3_ren_old.csv', [
        [10, 0, 0, '测试省', '测试省'],
        [1000, 10, 1, '测试市', '测试市'],
        [1001, 10, 1, '另市', '另市'],
        [100001, 1000, 2, '旧名', '旧名镇'],
    ]);
    $new = writeAreaCsvFixture('v3_ren_new.csv', [
        [10, 0, 0, '测试省', '测试省'],
        [1000, 10, 1, '测试市', '测试市'],
        [1001, 10, 1, '另市', '另市'],
        [100001, 1001, 2, '新名', '新名镇'],   // ext_name 与 pid 均变
    ]);

    $changes = [
        'schema_version' => 3,
        'version' => '2026.260101.260101',
        'evidence' => ['ev' => ['title' => 't', 'url' => 'https://example.com']],
        'changes' => [
            ['kind' => 'node', 'id' => 100001, 'state' => 'continued',
                'attributes' => ['ext_name', 'pid'],
                'summary' => '更名并调整隶属', 'evidence' => ['ev']],
        ],
    ];

    // 校验通过（含 diff 覆盖：continued 单侧书写派生对侧）
    Artisan::call('area:diff', [
        'new_csv' => $new, '--old' => $old,
        '--output' => sys_get_temp_dir().'/v3_ren_diff.json', '--to-version' => '2026.260101.260101',
    ]);
    $path = sys_get_temp_dir().'/v3_ren.json';
    file_put_contents($path, json_encode($changes));
    $exit = Artisan::call('area:check-changes', [
        'changes' => $path, '--diff' => sys_get_temp_dir().'/v3_ren_diff.json', '--old' => $old, '--new' => $new,
    ]);
    expect($exit)->toBe(0);

    // 派生标签：name/ext_name 类变化优先于 pid（rename）；detail.attributes 值从 csv 派生
    $import = app(ImportService::class);
    $payload = app(MigrationGenerator::class)->payload($changes, $import->loadCsvAsMap($old), $import->loadCsvAsMap($new));
    expect($payload['records'][0]['change_type'])->toBe('rename')
        ->and($payload['records'][0]['detail']['attributes'])->toBe([
            'ext_name' => ['旧名镇', '新名镇'],
            'pid' => [1000, 1001],
        ]);

    // 执行：只动 cmf_areas 结构
    app(ImportService::class)->import($old);
    app(MigrationExecutor::class)->apply($payload);
    expect(Area::query()->find(100001)->ext_name)->toBe('新名镇')
        ->and(Area::query()->find(100001)->pid)->toBe(1001);
});

it('⑨ abolish：无出边的 retired node 退休并入人工清单', function (): void {
    $old = writeAreaCsvFixture('v3_ab_old.csv', [
        [80, 0, 0, '测试省', '测试省'],
        [8000, 80, 1, '测试市', '测试市'],
        [800001, 8000, 2, '废弃', '废弃镇'],
    ]);
    $new = writeAreaCsvFixture('v3_ab_new.csv', [
        [80, 0, 0, '测试省', '测试省'],
        [8000, 80, 1, '测试市', '测试市'],
    ]);

    $changes = [
        'schema_version' => 3,
        'version' => '2026.260101.260101',
        'evidence' => ['ev' => ['title' => 't', 'url' => 'https://example.com']],
        'changes' => [
            ['kind' => 'node', 'id' => 800001, 'name' => '废弃镇', 'state' => 'retired',
                'summary' => '撤销，无承继单位', 'evidence' => ['ev']],
        ],
    ];

    $path = sys_get_temp_dir().'/v3_ab.json';
    file_put_contents($path, json_encode($changes));
    $exit = Artisan::call('area:check-changes', ['changes' => $path, '--old' => $old, '--new' => $new]);
    expect($exit)->toBe(0);

    $import = app(ImportService::class);
    $payload = app(MigrationGenerator::class)->payload($changes, $import->loadCsvAsMap($old), $import->loadCsvAsMap($new));
    expect($payload['mappings'])->toBeEmpty()
        ->and($payload['manual'])->toHaveCount(1)
        ->and($payload['manual'][0]['reason'])->toBe('abolish_no_successor')
        ->and($payload['records'][0]['change_type'])->toBe('abolish');

    app(ImportService::class)->import($old);
    Store::create(['title' => '废弃店', 'area_id' => 800001]);
    $result = app(MigrationExecutor::class)->apply($payload);

    expect(Area::query()->find(800001)->status)->toBe(0)
        ->and(Area::query()->find(800001)->successor_id)->toBeNull()
        ->and(Store::query()->first()->area_id)->toBe(800001)
        ->and($result['manual'][0]['affected_columns'])->toContain('stores.area_id（1 行）');
});

it('⑩ 废止复用（code_reuse）：旧单位归档 90{id} 段，新单位启用同码', function (): void {
    $old = writeAreaCsvFixture('v3_cr_old.csv', [
        [70, 0, 0, '测试省', '测试省'],
        [7000, 70, 1, '测试市', '测试市'],
        [700001, 7000, 2, '旧仓', '旧仓镇'],
    ]);
    $new = writeAreaCsvFixture('v3_cr_new.csv', [
        [70, 0, 0, '测试省', '测试省'],
        [7000, 70, 1, '测试市', '测试市'],
        [700001, 7000, 2, '新仓', '新仓镇'],   // 同码不同单位：废止复用
    ]);

    $changes = [
        'schema_version' => 3,
        'version' => '2026.260101.260101',
        'evidence' => ['ev-cr' => ['title' => '民政部公告', 'url' => 'https://example.com/cr']],
        'changes' => [
            ['kind' => 'node', 'id' => 700001, 'name' => '旧仓镇', 'state' => 'retired',
                'summary' => '旧仓镇撤销，无承继单位', 'evidence' => ['ev-cr']],
            ['kind' => 'node', 'id' => 700001, 'name' => '新仓镇', 'state' => 'appeared',
                'id_reuse' => true,
                'summary' => '废止复用：旧 700001 为旧仓镇（已撤销、无承继，归档 90700001），新 700001 为新设新仓镇',
                'evidence' => ['ev-cr']],
        ],
    ];

    $path = sys_get_temp_dir().'/v3_cr.json';
    file_put_contents($path, json_encode($changes));
    $exit = Artisan::call('area:check-changes', ['changes' => $path, '--old' => $old, '--new' => $new]);
    expect($exit)->toBe(0);

    $import = app(ImportService::class);
    $payload = app(MigrationGenerator::class)->payload($changes, $import->loadCsvAsMap($old), $import->loadCsvAsMap($new));

    $archiveId = (int) '90700001';
    // archive 操作 + 归档映射（remap/keep 全列执行）+ 人工复核项 + code_reuse 标签
    expect(array_column($payload['areas'], 'op'))->toContain('archive')
        ->and($payload['mappings'])->toBe([['from' => 700001, 'to' => $archiveId, 'unit_level' => true, 'archive' => true]])
        ->and($payload['manual'][0]['reason'])->toBe('code_reuse')
        ->and($payload['records'][1]['change_type'])->toBe('code_reuse');

    app(ImportService::class)->import($old);
    Store::create(['title' => '旧仓店', 'area_id' => 700001]);
    Order::create(['title' => '历史订单', 'region_id' => 700001, 'region_name' => '旧仓镇']);

    app(MigrationExecutor::class)->apply($payload);

    // 旧行归档保留原名（keep 列引用随归档不断链），新单位启用官方代码
    expect(Area::query()->find($archiveId)->ext_name)->toBe('旧仓镇')
        ->and(Area::query()->find($archiveId)->status)->toBe(0)
        ->and(Area::query()->find(700001)->ext_name)->toBe('新仓镇')
        ->and(Store::query()->first()->area_id)->toBe($archiveId)
        ->and(Order::query()->first()->region_id)->toBe($archiveId);
});

it('⑪ 下级边省略 evidence/summary 时继承单位级边；无单位级边的独立边必须自带证据', function (): void {
    $f = yubeiFixtures();

    // 下级边省略 evidence 与 summary → 继承单位级边（500112→500157）
    $changes = $f['changes'];
    foreach ($changes['changes'] as $i => $item) {
        if (($item['kind'] ?? null) === 'edge' && ($item['from_id'] ?? null) === 500112001) {
            unset($changes['changes'][$i]['evidence'], $changes['changes'][$i]['summary']);
        }
    }
    $path = sys_get_temp_dir().'/v3_yubei_inherit.json';
    file_put_contents($path, json_encode($changes));
    $exit = Artisan::call('area:check-changes', ['changes' => $path, '--old' => $f['old'], '--new' => $f['new']]);
    expect($exit)->toBe(0);

    // 生成器：继承的边 records 取到单位级边的证据与摘要
    $import = app(ImportService::class);
    $payload = app(MigrationGenerator::class)->payload(
        $changes, $import->loadCsvAsMap($f['old']), $import->loadCsvAsMap($f['new']));
    $edgeRecord = collect($payload['records'])->firstWhere(fn (array $r): bool => $r['kind'] === 'edge' && $r['old_id'] === 500112001);
    expect($edgeRecord['evidence_url'])->toBe('https://example.com/cq')
        ->and($edgeRecord['ai_summary'])->toBe('渝北区大部分区域并入两江新区');

    // 单位级边摘掉 evidence → 必须自证
    $changes = $f['changes'];
    foreach ($changes['changes'] as $i => $item) {
        if (($item['kind'] ?? null) === 'edge' && ($item['from_id'] ?? null) === 500112) {
            unset($changes['changes'][$i]['evidence']);
        }
    }
    $path = sys_get_temp_dir().'/v3_yubei_no_unit_ev.json';
    file_put_contents($path, json_encode($changes));
    $exit = Artisan::call('area:check-changes', ['changes' => $path, '--old' => $f['old'], '--new' => $f['new']]);
    expect($exit)->toBe(1)->and(Artisan::output())->toContain('必须自证');
});

it('⑫ 换码的两种合法姿势：只写 edge（极简）与 edge+端点 node（叙述型）均通过', function (): void {
    $old = writeAreaCsvFixture('v3_i8_old.csv', [
        [10, 0, 0, '测试省', '测试省'],
        [1000, 10, 1, '测试市', '测试市'],
        [100001, 1000, 2, '龙田乡', '龙田乡'],
        [100001001, 100001, 3, '龙田村', '龙田村'],
    ]);
    $new = writeAreaCsvFixture('v3_i8_new.csv', [
        [10, 0, 0, '测试省', '测试省'],
        [1000, 10, 1, '测试市', '测试市'],
        [200001, 1000, 2, '龙田镇', '龙田镇'],
        [200001001, 200001, 3, '龙田社区', '龙田社区'],
    ]);
    $path = sys_get_temp_dir().'/v3_i8.json';

    // 极简姿势：只写 edge（端点存续状态由边端点蕴含）
    $changes = [
        'schema_version' => 3,
        'version' => '2026.260101.260101',
        'evidence' => ['ev' => ['title' => 't', 'url' => 'https://example.com']],
        'changes' => [
            ['kind' => 'edge', 'from_id' => 100001, 'to_id' => 200001,
                'summary' => '撤龙田乡设龙田镇', 'evidence' => ['ev']],
            ['kind' => 'edge', 'from_id' => 100001001, 'to_id' => 200001001, 'evidence' => ['ev']],
        ],
    ];
    file_put_contents($path, json_encode($changes));
    $exit = Artisan::call('area:check-changes', ['changes' => $path, '--old' => $old, '--new' => $new]);
    expect($exit)->toBe(0);

    // 叙述型姿势：edge 之外补端点 node 挂 summary/evidence（v3 双层设计：边为执行骨架、
    // node 为存续叙述与证据载体，机器按图整体消费，不构成冗余/失真）
    $changes['changes'][] = ['kind' => 'node', 'id' => 100001, 'name' => '龙田乡', 'state' => 'retired',
        'summary' => '撤销龙田乡设立龙田镇，行政区域不变', 'evidence' => ['ev']];
    $changes['changes'][] = ['kind' => 'node', 'id' => 200001, 'name' => '龙田镇', 'state' => 'appeared',
        'summary' => '撤龙田乡设立龙田镇，区划代码 200001', 'evidence' => ['ev']];
    file_put_contents($path, json_encode($changes));
    $exit = Artisan::call('area:check-changes', ['changes' => $path, '--old' => $old, '--new' => $new]);
    expect($exit)->toBe(0);
});

