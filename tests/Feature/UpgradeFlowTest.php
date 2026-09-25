<?php

declare(strict_types=1);

use Quansitech\Cmf\Area\Services\UpgradeFinalizeService;
use Quansitech\Cmf\Area\Services\UpgradeFlowService;
use Quansitech\Cmf\Area\Services\UpgradeWorkspace;
use Quansitech\Cmf\Area\Services\UpstreamService;

/**
 * 升级全流程（文件态工作区，升级方案 §6 调整版）：
 * 发起（下载+diff）→ 地区选择 → 采集任务包 → 回收 → 审核 → 一键定稿。
 * 共享 helper（setupUpgradeEnv / startUpgradeWithRegions）在 tests/Pest.php。
 */

it('发起升级：下载上游 csv 并自动 diff，工作区状态就绪', function (): void {
    setupUpgradeEnv();

    $state = app(UpgradeFlowService::class)->start('2026.260101.260101');

    expect($state['status'])->toBe(UpgradeWorkspace::STATUS_DIFF_READY)
        ->and($state['from_version'])->toBe('2025.251231.260403')
        ->and($state['diff_summary'])->not->toBeEmpty()
        ->and(is_file(app(UpgradeWorkspace::class)->dir().'/diff.json'))->toBeTrue();
});

it('采集闭环：任务包 → 回收 → 机器校验全绿落库为待审核记录', function (): void {
    setupUpgradeEnv();
    $state = startUpgradeWithRegions();
    $flow = app(UpgradeFlowService::class);

    $dir = $flow->makeCollectPackage($state);
    foreach (['diff.json', 'old.csv', 'new.csv', 'scope.json', 'COLLECT_TASK.md'] as $file) {
        expect(is_file($dir.'/'.$file))->toBeTrue("{$file} 应存在于任务包");
    }

    $state = app(UpgradeWorkspace::class)->state();
    expect($state['collection_state']['65']['status'])->toBe('collecting')
        ->and($state['collection_state']['11']['status'])->toBe('collecting');

    [$state, $errors] = $flow->ingest($state, __DIR__.'/../Fixtures/data/changes_split.json');

    expect($errors)->toBe([]);
    $items = app(UpgradeWorkspace::class)->items();
    expect($items)->not->toBeEmpty();
    foreach ($items as $item) {
        expect($item['review_status'])->toBe(UpgradeWorkspace::REVIEW_PENDING)
            ->and($item['source'])->toBe(UpgradeWorkspace::SOURCE_AI);
    }
    expect($state['collection_state']['65']['status'])->toBe('passed')
        ->and($state['collection_state']['11']['status'])->toBe('passed');
});

it('采集任务折叠：父子同选（级联全选形态）时任务包只收顶层地区，重叠范围不重复判读', function (): void {
    setupWizardEnv();
    $flow = app(UpgradeFlowService::class);
    $state = $flow->start('2026.260101.260101');
    // 级联全选后存储的是省+市全集（页面 syncParent 的形态）
    $state = $flow->updateRegions($state, [50, 50023]);

    // 顶层折叠：市 50023 的祖先 50 同选，被剔除
    expect($flow->collectRegionIds($state))->toBe([50]);

    $dir = $flow->makeCollectPackage($state);
    $scope = json_decode((string) file_get_contents($dir.'/scope.json'), true);
    expect(array_column($scope['regions'], 'id'))->toBe([50]);

    // 采集状态只记顶层地区，下级不再产生独立任务行（状态/重试账本不分裂）
    $collectState = app(UpgradeWorkspace::class)->state()['collection_state'];
    expect($collectState)->toHaveKey('50')->not->toHaveKey('50023');
});

it('回收校验不过：错误清单写回采集状态并记重试，超限转人工', function (): void {    setupUpgradeEnv();
    config()->set('cmf-area.upgrade.max_retries', 2);
    $state = startUpgradeWithRegions();
    $flow = app(UpgradeFlowService::class);
    $flow->makeCollectPackage($state);

    // 少一条朝阳区认领的坏片段（范围内北京事实未被认领）
    $fragment = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/data/changes_split.json'), true);
    array_pop($fragment['changes']);
    $badPath = sys_get_temp_dir().'/bad_fragment_'.bin2hex(random_bytes(4)).'.json';
    file_put_contents($badPath, json_encode($fragment));

    $state = app(UpgradeWorkspace::class)->state();
    [$state, $errors] = $flow->ingest($state, $badPath);
    expect($errors)->not->toBe([])
        ->and($state['collection_state']['11']['retries'])->toBe(1)
        ->and($state['collection_state']['11']['status'])->toBe('collecting')
        ->and(app(UpgradeWorkspace::class)->items())->toBeEmpty();

    [$state] = $flow->ingest($state, $badPath);
    expect($state['collection_state']['11']['status'])->toBe('failed');
});

it('一键定稿：合并→校验→补丁基线→对齐版本号→迁移与快照落盘→config 写回', function (): void {
    $env = setupUpgradeEnv();
    $state = startUpgradeWithRegions();
    $flow = app(UpgradeFlowService::class);
    $workspace = app(UpgradeWorkspace::class);

    $flow->makeCollectPackage($state);
    $flow->ingest($workspace->state(), __DIR__.'/../Fixtures/data/changes_split.json');

    // 全部待审核记录标记通过（审定口径）
    foreach ($workspace->items() as $item) {
        $workspace->updateItem((int) $item['id'], ['review_status' => UpgradeWorkspace::REVIEW_APPROVED]);
    }

    [$state, $result] = app(UpgradeFinalizeService::class)->finalize($workspace->state());

    // 全量 diff 为零：自动回归上游语义（§3.3）
    expect($result['assigned_version'])->toBe('2026.260101.260101')
        ->and($result['aligned'])->toBeTrue()
        ->and($state['status'])->toBe(UpgradeWorkspace::STATUS_GENERATED);

    // 基线 csv 已补丁（和康县进基线，赛图拉镇旧行移出）
    $patched = file_get_contents($env['data_dir'].'/ok_data_level4.csv');
    expect($patched)->toContain('和康县')->and($patched)->not->toContain('赛图拉镇');

    // 快照（from/to 两端）与 changes 留档、迁移文件
    expect(is_file($env['data_dir'].'/baselines/baseline_2025.251231.260403.csv'))->toBeTrue()
        ->and(is_file($env['data_dir'].'/baselines/baseline_2026.260101.260101.csv'))->toBeTrue()
        ->and(is_file($env['data_dir'].'/changes_2026.260101.260101.json'))->toBeTrue();
    $migrations = glob(dirname($env['data_dir']).'/migrations/updates/*_area_update_2026_260101_260101.php') ?: [];
    expect($migrations)->not->toBeEmpty();

    // config 版本号写回（三件套同源）
    $configContent = file_get_contents($env['config_file']);
    expect($configContent)->toContain("'data_version' => '2026.260101.260101',")
        ->and($configContent)->toContain("'upstream_base' => '2026.260101.260101',");

    // 重复定稿被拒绝
    app(UpgradeFinalizeService::class)->finalize($workspace->state());
})->throws(RuntimeException::class, '不能重复生成');

it('定稿门禁：审定口径未 100% 覆盖时阻断', function (): void {
    setupUpgradeEnv();
    $state = startUpgradeWithRegions();
    $flow = app(UpgradeFlowService::class);

    $flow->makeCollectPackage($state);
    $flow->ingest(app(UpgradeWorkspace::class)->state(), __DIR__.'/../Fixtures/data/changes_split.json');
    // 记录全部停在 pending（不审核）→ 审定口径覆盖为 0

    app(UpgradeFinalizeService::class)->finalize(app(UpgradeWorkspace::class)->state());
})->throws(RuntimeException::class, '生成门禁未通过');

it('空裁确定稿：只勾新疆喀什时，区域外（北京）的审定记录被过滤、不污染基线', function (): void {
    $env = setupUpgradeEnv();
    $state = startUpgradeWithRegions();
    $flow = app(UpgradeFlowService::class);
    $workspace = app(UpgradeWorkspace::class);

    // 只勾新疆喀什地区（6532），但审定记录整份认领了新疆+北京（110105 区域外）
    $state = $flow->updateRegions($state, [6532]);
    $flow->makeCollectPackage($state);
    $flow->ingest($workspace->state(), __DIR__.'/../Fixtures/data/changes_split.json');
    foreach ($workspace->items() as $item) {
        $workspace->updateItem((int) $item['id'], ['review_status' => UpgradeWorkspace::REVIEW_APPROVED]);
    }

    [$state, $result] = app(UpgradeFinalizeService::class)->finalize($workspace->state());

    // 北京未对齐 → 部分地区发版（自有版本号），北京基线一行不动（§7）
    expect($result['aligned'])->toBeFalse()
        ->and($result['assigned_version'])->toBe('2025.251231.260403+1');
    $patched = file_get_contents($env['data_dir'].'/ok_data_level4.csv');
    expect($patched)->not->toContain('朝阳区')        // 北京没进基线
        ->and($patched)->toContain('和康县')          // 新疆喀什已补丁
        ->and($patched)->not->toContain('赛图拉镇');   // 旧行已移出

    // config 写回自有版本号（未对齐上游）
    expect(file_get_contents($env['config_file']))->toContain("'data_version' => '2025.251231.260403+1',");
});
