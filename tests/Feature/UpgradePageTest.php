<?php

declare(strict_types=1);

use Quansitech\Cmf\Area\Filament\Pages\AreaUpgradePage;
use Quansitech\Cmf\Area\Services\UpgradeWorkspace;

use Livewire\Livewire;

/**
 * 升级向导页（去批次化后的单一页面）：权限开关、地区选择、AI 判读入口、审核动作。
 */

it('开关关闭时不可访问（业务项目后台默认隐藏，§12）', function (): void {
    config()->set('cmf-area.upgrade.enabled', false);
    actingAsTestUser();

    expect(AreaUpgradePage::canAccess())->toBeFalse()
        ->and(AreaUpgradePage::shouldRegisterNavigation())->toBeFalse();

    $this->get(AreaUpgradePage::getUrl())->assertForbidden();
});

it('开关打开时页面可渲染，空工作区提示发起升级', function (): void {
    config()->set('cmf-area.upgrade.enabled', true);
    setupUpgradeEnv();
    actingAsTestUser();

    $this->get(AreaUpgradePage::getUrl())->assertOk();

    Livewire::test(AreaUpgradePage::class)
        ->assertSee('发起升级');
});

it('可升级版本下拉：仅列出高于当前基线的上游版本（忽略 +N 后缀）', function (): void {
    config()->set('cmf-area.upgrade.enabled', true);
    setupUpgradeEnv();
    config()->set('cmf-area.data_version', '2025.251231.260403+1');

    // stub 上游版本：2026.260101.260101 / 2025.251231.260403 / 2024.240101.240101
    expect(app(AreaUpgradePage::class)->targetUpgradeOptions())
        ->toBe(['2026.260101.260101' => '2026.260101.260101']);

    config()->set('cmf-area.data_version', '2026.260101.260101');
    expect(app(AreaUpgradePage::class)->targetUpgradeOptions())->toBe([]);
});

it('发起升级弹窗：渲染可升级版本下拉而非手填输入框', function (): void {
    config()->set('cmf-area.upgrade.enabled', true);
    setupUpgradeEnv();
    actingAsTestUser();

    $action = app(AreaUpgradePage::class)->startUpgradeAction();
    $property = new ReflectionProperty($action, 'schema');
    $property->setAccessible(true);
    $schema = $property->getValue($action);
    expect($schema)->toBeInstanceOf(Closure::class);

    // 有更高版本时：首组件为 target_upstream 的 Select（可选值来自 targetUpgradeOptions）
    $components = $schema();
    expect($components)->toHaveCount(1)
        ->and($components[0])->toBeInstanceOf(\Filament\Forms\Components\Select::class)
        ->and($components[0]->getName())->toBe('target_upstream');

    // 无更高版本时：占位提示，不出现手填输入框
    config()->set('cmf-area.data_version', '2099.990101.990101');
    $components = $schema();
    expect($components[0])->toBeInstanceOf(\Filament\Forms\Components\Placeholder::class);
});

it('地区选择：勾选根节点级联全选子树（到市级）并保存', function (): void {
    config()->set('cmf-area.upgrade.enabled', true);
    setupUpgradeEnv();
    actingAsTestUser();

    app(\Quansitech\Cmf\Area\Services\UpgradeFlowService::class)->start('2026.260101.260101');

    // 树结构：65 → 6532 → [653228, 653223]（县）；11 → 1101 → [110105]（县）
    // 级联只到市级：县级不可选，市/省 id 经 isInRegions 子树语义覆盖县事实
    Livewire::test(AreaUpgradePage::class)
        ->call('toggleRegion', 65)
        ->assertSet('regionSelection', [65, 6532])
        ->call('toggleRegion', 11)
        ->assertSet('regionSelection', [65, 6532, 11, 1101])
        ->call('saveRegions');

    $state = app(UpgradeWorkspace::class)->state();
    expect(array_column($state['selected_regions'], 'id'))->toBe([65, 6532, 11, 1101]);
});

it('地区选择：勾选市级联其子县；县级不可勾选；市内变更全覆盖时省自动选中', function (): void {
    config()->set('cmf-area.upgrade.enabled', true);
    setupUpgradeEnv();
    actingAsTestUser();

    app(\Quansitech\Cmf\Area\Services\UpgradeFlowService::class)->start('2026.260101.260101');

    Livewire::test(AreaUpgradePage::class)
        // 勾市 6532：它是省 65 在 diff 树里唯一有变更的市 → 市内变更全覆盖 → 省自动选中
        ->call('toggleRegion', 6532)
        ->assertSet('regionSelection', [6532, 65])
        ->call('toggleRegion', 6532)
        ->assertSet('regionSelection', [])
        // 县级 110105 不可勾选（deep 2 被忽略）
        ->call('toggleRegion', 110105)
        ->assertSet('regionSelection', []);
});

it('地区选择：勾省后取消一个市，省变半选、其余市保留（树不塌）', function (): void {
    config()->set('cmf-area.upgrade.enabled', true);
    setupUpgradeEnv();
    actingAsTestUser();

    app(\Quansitech\Cmf\Area\Services\UpgradeFlowService::class)->start('2026.260101.260101');

    // 勾两省 → 取消 6532（65 唯一有变更市）：65 失去"子全选"被移除（半选态由 blade 渲染），
    // 但 11 子树不受影响仍保留——展开状态与勾选解耦后不再联动塌树
    Livewire::test(AreaUpgradePage::class)
        ->call('toggleRegion', 65)
        ->call('toggleRegion', 11)
        ->assertSet('regionSelection', [65, 6532, 11, 1101])
        ->call('toggleRegion', 6532)
        ->assertSet('regionSelection', [11, 1101]);
});

it('地区选择：全选/清空快捷动作', function (): void {
    config()->set('cmf-area.upgrade.enabled', true);
    setupUpgradeEnv();
    actingAsTestUser();

    app(\Quansitech\Cmf\Area\Services\UpgradeFlowService::class)->start('2026.260101.260101');

    Livewire::test(AreaUpgradePage::class)
        ->call('selectAllRegions')
        ->assertSet('regionSelection', [65, 6532, 11, 1101])
        ->call('clearRegions')
        ->assertSet('regionSelection', []);
});

it('审核动作：通过与批量通过', function (): void {
    config()->set('cmf-area.upgrade.enabled', true);
    config()->set('cmf-area.upgrade.agent_command', fakePiScript('good'));
    setupUpgradeEnv();
    actingAsTestUser();
    startUpgradeWithRegions();

    $workspace = app(UpgradeWorkspace::class);
    app(\Quansitech\Cmf\Area\Services\CollectAgentRunner::class)->run($workspace->state());

    $firstPending = collect($workspace->items())->firstWhere('review_status', UpgradeWorkspace::REVIEW_PENDING);
    expect($firstPending)->not->toBeNull();

    Livewire::test(AreaUpgradePage::class)
        ->call('approveItem', (int) $firstPending['id'])
        ->call('approveBulk');

    $items = collect($workspace->items());
    expect($items->firstWhere('id', $firstPending['id'])['review_status'])->toBe(UpgradeWorkspace::REVIEW_APPROVED);
    // 批量通过只覆盖高置信边（§6.4）；剩余 pending 应全部是 node 记录
    expect($items->where('review_status', UpgradeWorkspace::REVIEW_PENDING)->where('kind', 'edge'))->toBeEmpty();
});

it('采集状态行按顶层折叠：父子同选（如直辖市 31/3101）只出省级一行', function (): void {
    config()->set('cmf-area.upgrade.enabled', true);
    setupWizardEnv();
    actingAsTestUser();

    $flow = app(\Quansitech\Cmf\Area\Services\UpgradeFlowService::class);
    $state = $flow->start('2026.260101.260101');
    // 级联全选后存储的是省+市全集
    $flow->updateRegions($state, [50, 50023]);

    Livewire::test(AreaUpgradePage::class)
        ->set('activeTab', 'collect')
        ->assertSee('重庆市（50）')
        ->assertDontSee('江北区（50023）');
});

it('判读进行中显示终止按钮：点击后进程被灭杀、地区标记已终止', function (): void {
    config()->set('cmf-area.upgrade.enabled', true);
    setupUpgradeEnv();
    actingAsTestUser();
    $state = startUpgradeWithRegions();
    $workspace = app(UpgradeWorkspace::class);
    app(\Quansitech\Cmf\Area\Services\UpgradeFlowService::class)->makeCollectPackage($state);

    // 伪造进行中的后台运行：真实 sleep 进程充当 artisan（pid 文件指向它）
    $log = $workspace->dir().'/agent-run.log';
    file_put_contents($log, '');
    $pid = (int) trim((string) shell_exec('setsid sleep 300 >/dev/null 2>&1 & echo $!'));
    file_put_contents($log.'.pid', (string) $pid);
    $workspace->saveState(['agent_run' => ['started_at' => date('c'), 'log' => $log, 'regions' => [65]]]);

    Livewire::test(AreaUpgradePage::class)
        ->set('activeTab', 'collect')
        ->assertSee('终止判读')
        ->call('stopAiCollect')
        ->assertSee('已终止');

    expect($workspace->state()['collection_state']['65']['status'])->toBe('terminated');

    $deadline = microtime(true) + 3;
    while (is_dir("/proc/{$pid}") && microtime(true) < $deadline) {
        usleep(50000);
    }
    expect(is_dir("/proc/{$pid}"))->toBeFalse();
});

it('清空工作区后可重新发起', function (): void {    config()->set('cmf-area.upgrade.enabled', true);
    setupUpgradeEnv();
    actingAsTestUser();
    startUpgradeWithRegions();

    expect(app(UpgradeWorkspace::class)->exists())->toBeTrue();

    Livewire::test(AreaUpgradePage::class)->call('resetUpgrade');

    expect(app(UpgradeWorkspace::class)->exists())->toBeFalse();
});

it('未选地区时覆盖率按空统计、门禁不通过、定稿被引导文案阻断', function (): void {
    config()->set('cmf-area.upgrade.enabled', true);
    setupUpgradeEnv();
    actingAsTestUser();

    $flow = app(\Quansitech\Cmf\Area\Services\UpgradeFlowService::class);
    $flow->start('2026.260101.260101');

    // 空选区不按全量统计：覆盖率全 0
    $coverage = $flow->coverage(app(UpgradeWorkspace::class)->state());
    expect($coverage['total'])->toBe(0)
        ->and($coverage['claimed'])->toBe(0)
        ->and($coverage['approved'])->toBe(0)
        ->and($coverage['regions'])->toBe([])
        ->and($coverage['uncovered_final'])->toBe([]);

    $page = Livewire::test(AreaUpgradePage::class)
        ->set('activeTab', 'review')
        ->assertSee('尚未选择升级地区')
        ->set('activeTab', 'finalize')
        ->assertSee('请先在「地区选择」勾选本次升级的范围后再定稿');

    // 门禁不通过 → finalize 被前置阻断
    expect($page->instance()->gatePassed())->toBeFalse();
    $page->call('finalize');

    // 工作区状态未被定稿推进
    expect(app(UpgradeWorkspace::class)->state()['status'] ?? null)
        ->not->toBe(UpgradeWorkspace::STATUS_GENERATED);
});
