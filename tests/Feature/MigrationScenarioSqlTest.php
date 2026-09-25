<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Quansitech\Cmf\Area\Services\ImportService;
use Quansitech\Cmf\Area\Services\MigrationExecutor;
use Quansitech\Cmf\Area\Services\MigrationGenerator;
use Quansitech\Cmf\Area\Services\UpgradeFinalizeService;
use Quansitech\Cmf\Area\Services\UpgradeFlowService;
use Quansitech\Cmf\Area\Services\UpgradeWorkspace;
use Quansitech\Cmf\Area\Services\UpstreamService;
use Quansitech\Cmf\Area\Tests\Fixtures\Biz\Models\Order;
use Quansitech\Cmf\Area\Tests\Fixtures\Biz\Models\Store;

/**
 * 场景驱动的迁移生成集成测试：新设 / 析出新设 / 撤并 / 换码 / 改名 / 换隶属 / 撤销 / 废止复用。
 * （县级析出新设带下级已由 MigrationPipelineTest 的 changes_split.json 全链路覆盖；
 *   本数据集的「析出新设」补街道级独立析出——镇级边自身即单位级边、无下级的形态。）
 *
 * 同一套场景数据集跑两条链路，断言同一组「生成物 + 变更 SQL」：
 *  ① 直生链路：迷你 csv 两版 + changes → MigrationGenerator::payload → apply；
 *  ② 定稿链路：升级工作区 → ingest 机器校验 → 审定 → UpgradeFinalizeService::finalize
 *     → 从落盘的迁移文件反射提取冻结 payload → 导入旧版 csv 后 apply。
 * ② 同时验证定稿产物：版本号对齐、config 写回、迁移文件落盘。
 */

beforeEach(function (): void {
    migrateAreaSchema();
    app(\Quansitech\Cmf\Area\Services\ReferenceCollector::class)->sync();
});

// ── 场景数据集 ─────────────────────────────────────────────────────────────
// csv 行格式 [id, pid, deep, name, ext_name]；deep：0省 1市 2乡镇 3村。
// changes 只写 v3 node/edge 记录（schema_version/version/证据池由驱动函数补全）。

dataset('area-migration-scenarios', [
    '新设' => [[
        'old' => [
            [10, 0, 0, '测试省', '测试省'],
            [1000, 10, 1, '测试市', '测试市'],
            [100001, 1000, 2, '甲镇', '甲镇'],
        ],
        'new' => [
            [10, 0, 0, '测试省', '测试省'],
            [1000, 10, 1, '测试市', '测试市'],
            [100001, 1000, 2, '甲镇', '甲镇'],
            [100002, 1000, 2, '乙镇', '乙镇'],
            [100002001, 100002, 3, '乙一村', '乙一村'],
        ],
        // 认领粒度是 id 自身（下级不被自动认领），新设单位与其下级各需一条 node；
        // 生成器 familyOf 去重，整族 insert 不会重复
        'changes' => [
            ['kind' => 'node', 'id' => 100002, 'state' => 'appeared', 'summary' => '新设乙镇', 'evidence' => ['ev']],
            ['kind' => 'node', 'id' => 100002001, 'state' => 'appeared', 'summary' => '乙镇下辖乙一村', 'evidence' => ['ev']],
        ],
        'seed' => null,
        'expect' => function (array $payload, array $queries): void {
            // 生成物：两条 insert（新单位 + 其下级整族），无映射、无人工清单
            expect(array_column($payload['areas'], 'op'))->toBe(['insert', 'insert'])
                ->and(array_column($payload['areas'], 'id'))->toBe([100002, 100002001])
                ->and($payload['mappings'])->toBe([])
                ->and($payload['manual'])->toBe([]);

            // SQL：恰好两条 cmf_areas 插入；无任何 update/delete/业务表写入
            $inserts = findQueries($queries, 'insert into "cmf_areas"');
            expect($inserts)->toHaveCount(2)
                ->and(in_array(100002, $inserts[0]['bindings']))->toBeTrue()
                ->and(in_array(100002001, $inserts[1]['bindings']))->toBeTrue();
            expect(findQueries($queries, 'update "cmf_areas"'))->toBe([])
                ->and(findQueries($queries, 'update "stores"'))->toBe([])
                ->and(findQueries($queries, 'update "orders"'))->toBe([]);
        },
    ]],

    '析出新设（街道级、母体存续、无下级）' => [[
        // image.png 真实案例形态：头桥街道析自奉城镇（沪府〔2024〕42号）。
        // 与 MigrationPipelineTest 的县级析出不同——镇级边祖先链上无其他边，
        // 自己就是单位级边（classifyEdges topmost 判定），from 存续 → 转人工；
        // 新单位无下级 → 整族=自身，areas 仅一条 insert；mappings 为空。
        'old' => [
            [10, 0, 0, '测试省', '测试省'],
            [1000, 10, 1, '测试市', '测试市'],
            [100001, 1000, 2, '甲镇', '甲镇'],
            [100002, 1000, 2, '乙镇', '乙镇'],
        ],
        'new' => [
            [10, 0, 0, '测试省', '测试省'],
            [1000, 10, 1, '测试市', '测试市'],
            [100001, 1000, 2, '甲镇', '甲镇'],
            [100002, 1000, 2, '乙镇', '乙镇'],
            [100003, 1000, 2, '丙街道', '丙街道'],
        ],
        'changes' => [
            ['kind' => 'node', 'id' => 100003, 'state' => 'appeared', 'summary' => '析乙镇北部设丙街道', 'evidence' => ['ev']],
            ['kind' => 'edge', 'from_id' => 100002, 'to_id' => 100003, 'summary' => '丙街道析自乙镇（乙镇存续）', 'evidence' => ['ev']],
        ],
        'seed' => function (): void {
            Store::create(['title' => '乙镇店', 'area_id' => 100002]);
            Order::create(['title' => '历史订单', 'region_id' => 100002, 'region_name' => '乙镇']);
        },
        'expect' => function (array $payload, array $queries): void {
            // 生成物：仅一条 insert（整族=自身）；无映射；manual 记 split_shallow_value
            expect($payload['areas'])->toHaveCount(1)
                ->and($payload['areas'][0]['op'])->toBe('insert')
                ->and($payload['areas'][0]['id'])->toBe(100003)
                ->and($payload['areas'][0]['pid'])->toBe(1000)
                ->and($payload['mappings'])->toBe([])
                ->and($payload['manual'])->toHaveCount(1)
                ->and($payload['manual'][0]['type'])->toBe('split_from')
                ->and($payload['manual'][0]['reason'])->toBe('split_shallow_value')
                ->and($payload['manual'][0]['old_id'])->toBe(100002)
                ->and($payload['manual'][0]['new_id'])->toBe(100003);

            // 档案：node/edge 均派生 split_from；镇级边无祖先 → 单位级（detail.unit_level=true）
            $records = collect($payload['records']);
            $node = $records->firstWhere('kind', 'node');
            $edge = $records->firstWhere('kind', 'edge');
            expect($node['change_type'])->toBe('split_from')->and($node['side'])->toBe('new')
                ->and($edge['change_type'])->toBe('split_from')
                ->and($edge['detail']['unit_level'])->toBeTrue();

            // SQL：恰好一条 cmf_areas 插入（丙街道）；母体乙镇无任何更新；
            //      业务表零自动改写——存乙镇浅层值的行（remap/keep  alike）一律人工
            $inserts = findQueries($queries, 'insert into "cmf_areas"');
            expect($inserts)->toHaveCount(1)
                ->and(in_array(100003, $inserts[0]['bindings']))->toBeTrue();
            expect(findQueries($queries, 'update "cmf_areas"'))->toBe([])
                ->and(findQueries($queries, 'update "stores"'))->toBe([])
                ->and(findQueries($queries, 'update "orders"'))->toBe([])
                ->and(Store::query()->first()->area_id)->toBe(100002)
                ->and(Order::query()->first()->region_id)->toBe(100002);
        },
    ]],

    '撤并' => [[
        'old' => [
            [10, 0, 0, '测试省', '测试省'],
            [1000, 10, 1, '测试市', '测试市'],
            [100001, 1000, 2, '甲镇', '甲镇'],
            [100002, 1000, 2, '乙镇', '乙镇'],
        ],
        'new' => [
            [10, 0, 0, '测试省', '测试省'],
            [1000, 10, 1, '测试市', '测试市'],
            [100002, 1000, 2, '乙镇', '乙镇'],
        ],
        'changes' => [
            ['kind' => 'edge', 'from_id' => 100001, 'to_id' => 100002, 'summary' => '甲镇撤并入乙镇', 'evidence' => ['ev']],
        ],
        'seed' => function (): void {
            Store::create(['title' => '甲镇店', 'area_id' => 100001]);
            Order::create(['title' => '历史订单', 'region_id' => 100001, 'region_name' => '甲镇']);
        },
        'expect' => function (array $payload, array $queries): void {
            // 生成物：retire(successor=100002) + 单位级映射；无 insert、无人工清单
            expect($payload['areas'])->toBe([['op' => 'retire', 'id' => 100001, 'successor_id' => 100002]])
                ->and($payload['mappings'])->toBe([['from' => 100001, 'to' => 100002, 'unit_level' => true]])
                ->and($payload['manual'])->toBe([]);

            // SQL：cmf_areas 退休更新；stores(remap) 改写；orders(keep) 不动
            expect(hasQueryWithBindings($queries, 'update "cmf_areas" set "status" = ?, "successor_id" = ?', [0, 100002, 100001]))->toBeTrue()
                ->and(hasQueryWithBindings($queries, 'update "stores" set "area_id" = ?', [100002, 100001]))->toBeTrue();
            expect(findQueries($queries, 'update "orders"'))->toBe([]);
            expect(Store::query()->first()->area_id)->toBe(100002)
                ->and(Order::query()->first()->region_id)->toBe(100001);
        },
    ]],

    '换码' => [[
        'old' => [
            [10, 0, 0, '测试省', '测试省'],
            [1000, 10, 1, '测试市', '测试市'],
            [100001, 1000, 2, '甲镇', '甲镇'],
        ],
        'new' => [
            [10, 0, 0, '测试省', '测试省'],
            [1000, 10, 1, '测试市', '测试市'],
            [100009, 1000, 2, '甲镇', '甲镇'],
        ],
        'changes' => [
            ['kind' => 'edge', 'from_id' => 100001, 'to_id' => 100009, 'summary' => '甲镇换码', 'evidence' => ['ev']],
        ],
        'seed' => function (): void {
            Store::create(['title' => '甲镇店', 'area_id' => 100001]);
        },
        'expect' => function (array $payload, array $queries): void {
            // 生成物：retire 旧码 + insert 新码 + 单位级映射（端点全部由边派生，无需手写 node）
            expect(array_column($payload['areas'], 'op'))->toBe(['retire', 'insert'])
                ->and($payload['areas'][0])->toBe(['op' => 'retire', 'id' => 100001, 'successor_id' => 100009])
                ->and($payload['areas'][1]['id'])->toBe(100009)
                ->and($payload['mappings'])->toBe([['from' => 100001, 'to' => 100009, 'unit_level' => true]]);

            // SQL：插入新码行；旧码退休且 successor 指向新码；stores remap 改写
            expect(hasQueryWithBindings($queries, 'insert into "cmf_areas"', [100009]))->toBeTrue()
                ->and(hasQueryWithBindings($queries, 'update "cmf_areas" set "status" = ?, "successor_id" = ?', [0, 100009, 100001]))->toBeTrue()
                ->and(hasQueryWithBindings($queries, 'update "stores" set "area_id" = ?', [100009, 100001]))->toBeTrue();
            expect(Store::query()->first()->area_id)->toBe(100009);
        },
    ]],

    '改名' => [[
        'old' => [
            [10, 0, 0, '测试省', '测试省'],
            [1000, 10, 1, '测试市', '测试市'],
            [100001, 1000, 2, '甲镇', '甲镇'],
        ],
        'new' => [
            [10, 0, 0, '测试省', '测试省'],
            [1000, 10, 1, '测试市', '测试市'],
            [100001, 1000, 2, '甲新镇', '甲新镇'],
        ],
        'changes' => [
            ['kind' => 'node', 'id' => 100001, 'state' => 'continued', 'attributes' => ['name', 'ext_name'], 'summary' => '甲镇改名', 'evidence' => ['ev']],
        ],
        'seed' => function (): void {
            Store::create(['title' => '甲镇店', 'area_id' => 100001]);
        },
        'expect' => function (array $payload, array $queries): void {
            // 生成物：仅 rename（值从新版 csv 派生）；无映射、无人工清单
            expect($payload['areas'])->toHaveCount(1)
                ->and($payload['areas'][0]['op'])->toBe('rename')
                ->and($payload['areas'][0]['name'])->toBe('甲新镇')
                ->and($payload['mappings'])->toBe([])
                ->and($payload['manual'])->toBe([]);

            // SQL：唯一一条 cmf_areas 更新只含名称列，绑定新名；stores/orders 完全无写入
            $updates = findQueries($queries, 'update "cmf_areas"');
            expect($updates)->toHaveCount(1)
                ->and($updates[0]['sql'])->toContain('"name" = ?')->toContain('"ext_name" = ?')
                ->and($updates[0]['sql'])->not->toContain('"status"')->not->toContain('"pid"')
                ->and(in_array('甲新镇', $updates[0]['bindings']))->toBeTrue()
                ->and(in_array(100001, $updates[0]['bindings']))->toBeTrue();
            expect(findQueries($queries, 'update "stores"'))->toBe([])
                ->and(findQueries($queries, 'update "orders"'))->toBe([])
                ->and(Store::query()->first()->area_id)->toBe(100001);
        },
    ]],

    '换隶属' => [[
        'old' => [
            [10, 0, 0, '测试省', '测试省'],
            [1000, 10, 1, '甲市', '甲市'],
            [1001, 10, 1, '乙市', '乙市'],
            [100001, 1000, 2, '甲镇', '甲镇'],
        ],
        'new' => [
            [10, 0, 0, '测试省', '测试省'],
            [1000, 10, 1, '甲市', '甲市'],
            [1001, 10, 1, '乙市', '乙市'],
            [100001, 1001, 2, '甲镇', '甲镇'],
        ],
        'changes' => [
            ['kind' => 'node', 'id' => 100001, 'state' => 'continued', 'attributes' => ['pid'], 'summary' => '甲镇划归乙市', 'evidence' => ['ev']],
        ],
        'seed' => null,
        'expect' => function (array $payload, array $queries): void {
            // 生成物：仅 reparent，新 pid 从新版 csv 派生
            expect($payload['areas'])->toBe([['op' => 'reparent', 'id' => 100001, 'pid' => 1001]])
                ->and($payload['mappings'])->toBe([]);

            // SQL：唯一一条更新只含 pid 列，绑定 1001 / where 100001
            $updates = findQueries($queries, 'update "cmf_areas"');
            expect($updates)->toHaveCount(1)
                ->and($updates[0]['sql'])->toContain('"pid" = ?')
                ->and($updates[0]['sql'])->not->toContain('"name"')->not->toContain('"status"')
                ->and(in_array(1001, $updates[0]['bindings']))->toBeTrue()
                ->and(in_array(100001, $updates[0]['bindings']))->toBeTrue();
        },
    ]],

    '撤销' => [[
        'old' => [
            [10, 0, 0, '测试省', '测试省'],
            [1000, 10, 1, '测试市', '测试市'],
            [100001, 1000, 2, '甲镇', '甲镇'],
        ],
        'new' => [
            [10, 0, 0, '测试省', '测试省'],
            [1000, 10, 1, '测试市', '测试市'],
        ],
        'changes' => [
            ['kind' => 'node', 'id' => 100001, 'state' => 'retired', 'summary' => '甲镇撤销无承继', 'evidence' => ['ev']],
        ],
        'seed' => function (): void {
            Order::create(['title' => '历史订单', 'region_id' => 100001, 'region_name' => '甲镇']);
        },
        'expect' => function (array $payload, array $queries): void {
            // 生成物：retire(successor=null) + manual abolish_no_successor；无映射
            expect($payload['areas'])->toBe([['op' => 'retire', 'id' => 100001, 'successor_id' => null]])
                ->and($payload['mappings'])->toBe([])
                ->and($payload['manual'])->toHaveCount(1)
                ->and($payload['manual'][0]['reason'])->toBe('abolish_no_successor');

            // SQL：退休更新 successor 为 null；不删除行；orders 不动
            expect(hasQueryWithBindings($queries, 'update "cmf_areas" set "status" = ?, "successor_id" = ?', [0, null, 100001]))->toBeTrue();
            expect(findQueries($queries, 'delete from "cmf_areas"'))->toBe([])
                ->and(findQueries($queries, 'update "orders"'))->toBe([])
                ->and(findQueries($queries, 'update "stores"'))->toBe([]);
        },
    ]],

    '废止复用' => [[
        'old' => [
            [10, 0, 0, '测试省', '测试省'],
            [1000, 10, 1, '测试市', '测试市'],
            [100001, 1000, 2, '甲镇', '甲镇'],
        ],
        'new' => [
            [10, 0, 0, '测试省', '测试省'],
            [1000, 10, 1, '测试市', '测试市'],
            [100001, 1000, 2, '乙街道', '乙街道'],
        ],
        'changes' => [
            ['kind' => 'node', 'id' => 100001, 'state' => 'retired', 'summary' => '旧甲镇废止', 'evidence' => ['ev']],
            ['kind' => 'node', 'id' => 100001, 'state' => 'appeared', 'id_reuse' => true, 'summary' => '新乙街道启用同码', 'evidence' => ['ev']],
        ],
        'seed' => function (): void {
            Store::create(['title' => '甲镇店', 'area_id' => 100001]);
            Order::create(['title' => '历史订单', 'region_id' => 100001, 'region_name' => '甲镇']);
        },
        'expect' => function (array $payload, array $queries): void {
            // 生成物：archive + insert（无 retire）；归档映射 archive=true；manual 记 code_reuse
            expect(array_column($payload['areas'], 'op'))->toBe(['archive', 'insert'])
                ->and($payload['areas'][0])->toBe(['op' => 'archive', 'id' => 100001, 'archive_id' => 90100001])
                ->and($payload['areas'][1]['id'])->toBe(100001)
                ->and($payload['mappings'])->toBe([['from' => 100001, 'to' => 90100001, 'unit_level' => true, 'archive' => true]])
                ->and($payload['manual'][0]['reason'])->toBe('code_reuse');

            // SQL：插入归档行(90100001) → 删除旧行(100001) → 插入新行(100001)；
            //      归档映射对 remap(stores) 与 keep(orders) 都执行改写
            expect(hasQueryWithBindings($queries, 'insert into "cmf_areas"', [90100001]))->toBeTrue()
                ->and(hasQueryWithBindings($queries, 'insert into "cmf_areas"', [100001]))->toBeTrue()
                ->and(hasQueryWithBindings($queries, 'delete from "cmf_areas"', [100001]))->toBeTrue()
                ->and(hasQueryWithBindings($queries, 'update "stores" set "area_id" = ?', [90100001, 100001]))->toBeTrue()
                ->and(hasQueryWithBindings($queries, 'update "orders"', [90100001, 100001]))->toBeTrue();
            expect(Store::query()->first()->area_id)->toBe(90100001)
                ->and(Order::query()->first()->region_id)->toBe(90100001);
        },
    ]],
]);

// ── 驱动与断言辅助 ──────────────────────────────────────────────────────────

/**
 * ① 直生链路：导入旧版 csv → MigrationGenerator::payload → 捕获 apply 全过程 SQL。
 *
 * @return array{0: array<string, mixed>, 1: list<array{sql: string, bindings: array<int, mixed>}>}
 */
function runScenario(array $oldRows, array $newRows, array $changes): array
{
    $tag = bin2hex(random_bytes(4));
    $old = writeAreaCsvFixture("scen_old_{$tag}.csv", $oldRows);
    $new = writeAreaCsvFixture("scen_new_{$tag}.csv", $newRows);

    $import = app(ImportService::class);
    $import->import($old);

    $payload = app(MigrationGenerator::class)->payload([
        'schema_version' => 3,
        'version' => '2026.260101.260101',
        'evidence' => ['ev' => ['title' => '证据', 'url' => 'https://example.com']],
        'changes' => $changes,
    ], $import->loadCsvAsMap($old), $import->loadCsvAsMap($new));

    return [$payload, applyWithSqlLog($payload)];
}

/**
 * ② 定稿链路：隔离升级环境（旧版为基线、上游打桩为新版）→ 发起升级选中测试省(10)
 * → ingest 机器校验 → 全部审定 → 一键定稿 → 从落盘迁移文件反射提取冻结 payload。
 *
 * @return array{0: array<string, mixed> 定稿结果, 1: array<string, mixed> 迁移文件 payload, 2: string 旧版 csv 路径}
 */
function finalizeScenario(array $oldRows, array $newRows, array $changes): array
{
    $tag = bin2hex(random_bytes(4));
    $env = setupUpgradeEnv();

    $oldCsv = writeAreaCsvFixture("fin_old_{$tag}.csv", $oldRows);
    $newCsv = writeAreaCsvFixture("fin_new_{$tag}.csv", $newRows);
    copy($oldCsv, $env['data_dir'].'/ok_data_level4.csv');

    // 上游打桩：新版 csv 充当上游发布版
    app()->instance(UpstreamService::class, new class($newCsv) extends UpstreamService
    {
        public function __construct(private readonly string $csv) {}

        public function download(string $version, ?string $output = null, ?string $workDir = null): string
        {
            return $this->csv;
        }

        public function versions(): array
        {
            return ['2026.260101.260101', '2025.251231.260403'];
        }
    });

    $flow = app(UpgradeFlowService::class);
    $workspace = app(UpgradeWorkspace::class);
    $state = $flow->start('2026.260101.260101');
    $state = $flow->updateRegions($state, [10]);

    $fragment = sys_get_temp_dir()."/fin_changes_{$tag}.json";
    file_put_contents($fragment, json_encode([
        'schema_version' => 3,
        'version' => '2026.260101.260101',
        'evidence' => ['ev' => ['title' => '证据', 'url' => 'https://example.com']],
        'changes' => $changes,
    ], JSON_UNESCAPED_UNICODE));

    $flow->makeCollectPackage($state);
    [, $errors] = $flow->ingest($workspace->state(), $fragment);
    expect($errors)->toBeEmpty('场景 changes 应通过 ingest 机器校验：'.implode('；', $errors));

    foreach ($workspace->items() as $item) {
        $workspace->updateItem((int) $item['id'], ['review_status' => UpgradeWorkspace::REVIEW_APPROVED]);
    }

    [, $result] = app(UpgradeFinalizeService::class)->finalize($workspace->state());

    // 从落盘的迁移文件提取冻结 payload（薄壳文件只含数据，include 即得匿名迁移实例）
    $migrations = glob(dirname($env['data_dir']).'/migrations/updates/*_area_update_2026_260101_260101.php') ?: [];
    expect($migrations)->toHaveCount(1, '定稿应落盘恰好一个迁移文件');
    $migration = include $migrations[0];
    $payload = (new ReflectionClass($migration))->getProperty('payload')->getValue($migration);

    return [$result, $payload, $oldCsv];
}

/** 捕获 MigrationExecutor::apply 执行全过程的 SQL。 */
function applyWithSqlLog(array $payload): array
{
    $queries = [];
    DB::listen(function ($q) use (&$queries): void {
        $queries[] = ['sql' => $q->sql, 'bindings' => $q->bindings];
    });
    app(MigrationExecutor::class)->apply($payload);

    return $queries;
}

/** 按 SQL 片段过滤捕获的查询（表名带引号，精确区分 cmf_areas 与 journal 表）。 */
function findQueries(array $queries, string $needle): array
{
    return array_values(array_filter($queries, fn (array $q): bool => str_contains($q['sql'], $needle)));
}

/** 存在一条匹配 SQL 且绑定值包含全部给定值。 */
function hasQueryWithBindings(array $queries, string $needle, array $expectedBindings): bool
{
    foreach (findQueries($queries, $needle) as $q) {
        $hit = true;
        foreach ($expectedBindings as $value) {
            if (! in_array($value, $q['bindings'])) {
                $hit = false;
                break;
            }
        }
        if ($hit) {
            return true;
        }
    }

    return false;
}

// ── 两条链路共用同一组场景断言 ──────────────────────────────────────────────

it('直生链路：生成 payload 与变更 SQL 正确', function (array $scenario): void {
    ($scenario['seed'] ?? fn () => null)();

    [$payload, $queries] = runScenario($scenario['old'], $scenario['new'], $scenario['changes']);

    ($scenario['expect'])($payload, $queries);
})->with('area-migration-scenarios');

it('定稿链路：迁移文件冻结 payload 与变更 SQL 正确', function (array $scenario): void {
    [$result, $payload, $oldCsv] = finalizeScenario($scenario['old'], $scenario['new'], $scenario['changes']);

    // 定稿产物：补丁后基线与上游全量 diff 为零 → 自动对齐上游版本号
    expect($result['aligned'])->toBeTrue()
        ->and($result['assigned_version'])->toBe('2026.260101.260101')
        ->and($payload['version'])->toBe('2026.260101.260101')
        ->and($payload['from_version'])->toBe('2025.251231.260403')
        ->and($payload['journal'])->toBeTrue();

    // 模拟业务项目执行迁移：库内还是旧版数据，应用迁移文件里冻结的 payload
    app(ImportService::class)->import($oldCsv);
    ($scenario['seed'] ?? fn () => null)();
    $queries = applyWithSqlLog($payload);

    ($scenario['expect'])($payload, $queries);
})->with('area-migration-scenarios');
