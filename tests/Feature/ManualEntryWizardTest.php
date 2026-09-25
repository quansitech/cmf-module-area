<?php

declare(strict_types=1);

use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Wizard\Step as WizardStep;
use Livewire\Livewire;
use Quansitech\Cmf\Area\Filament\Pages\AreaUpgradePage;
use Quansitech\Cmf\Area\Services\ManualScenarioService;
use Quansitech\Cmf\Area\Services\UpgradeWorkspace;

/**
 * 人工录入场景向导（docs/area-upgrade-manual-entry-wizard.md）：
 * 六场景录入 → validateItemsWith 校验 → 落库待审（source=manual、review_status=pending）
 * → 审核列表可见；复杂情形进高级模式（原 node/edge 裸表单能力回归）。
 */

/**
 * 向导第一步的 Radio（反射取 action schema，与既有 startUpgradeAction 用例同手法）。
 */
function wizardScenarioRadio(): Radio
{
    $action = app(AreaUpgradePage::class)->manualItemAction();
    $property = new ReflectionProperty($action, 'schema');
    $property->setAccessible(true);
    $steps = $property->getValue($action);

    expect($steps)->toBeArray()->toHaveCount(2)
        ->and($steps[0])->toBeInstanceOf(WizardStep::class);

    // 无容器上下文（非 Livewire 渲染期）：Step 子组件只能反射取原始定义
    $childProperty = new ReflectionProperty($steps[0], 'childComponents');
    $childProperty->setAccessible(true);
    $children = $childProperty->getValue($steps[0])['default'] ?? [];
    $radio = $children[0] ?? null;
    expect($radio)->toBeInstanceOf(Radio::class);

    return $radio;
}

it('审核 tab 只有一个「人工录入」入口（原两个裸录入按钮收进向导高级模式）', function (): void {
    config()->set('cmf-area.upgrade.enabled', true);
    setupWizardEnv();
    actingAsTestUser();
    startWizardUpgrade();

    Livewire::test(AreaUpgradePage::class)
        ->set('activeTab', 'review')
        ->assertSee('人工录入')
        ->assertDontSee('人工录入单位状态')
        ->assertDontSee('人工录入对应关系');
});

it('第一步是六场景卡片，撤销场景提示先确认是否真有归属', function (): void {
    config()->set('cmf-area.upgrade.enabled', true);
    setupUpgradeEnv();
    actingAsTestUser();

    $radio = wizardScenarioRadio();

    expect(array_keys($radio->getOptions()))->toBe([
        ManualScenarioService::SCENARIO_MERGE,
        ManualScenarioService::SCENARIO_APPEAR,
        ManualScenarioService::SCENARIO_RETIRE,
        ManualScenarioService::SCENARIO_RENAME,
        ManualScenarioService::SCENARIO_REUSE,
        ManualScenarioService::SCENARIO_COMPLEX,
    ]);

    $descriptions = $radio->getDescriptions();
    expect($descriptions[ManualScenarioService::SCENARIO_RETIRE])->toContain('请先确认公告是否划归其他单位');
});

it('merge 撤并/换码：默认只生成 1 条 edge，落库待审且审核列表可见', function (): void {
    config()->set('cmf-area.upgrade.enabled', true);
    setupWizardEnv();
    actingAsTestUser();
    startWizardUpgrade();

    Livewire::test(AreaUpgradePage::class)
        ->callAction('manualItem', data: [
            'scenario' => 'merge',
            'from_id' => 500235,
            'to_id' => 500239,
            'notice_title' => '市政府关于乡镇行政区划调整的公告',
            'notice_url' => 'https://example.com/notice/1',
        ]);

    $items = app(UpgradeWorkspace::class)->items();
    expect($items)->toHaveCount(1)
        ->and($items[0]['kind'])->toBe('edge')
        ->and($items[0]['payload']['from_id'])->toBe(500235)
        ->and($items[0]['payload']['to_id'])->toBe(500239)
        ->and($items[0]['payload']['summary'])->toBe('撤旧集镇设新城街道')
        ->and($items[0]['review_status'])->toBe(UpgradeWorkspace::REVIEW_PENDING)
        ->and($items[0]['source'])->toBe(UpgradeWorkspace::SOURCE_MANUAL);

    // 政府公告入工作区证据池，payload.evidence 引用其 id
    $state = app(UpgradeWorkspace::class)->state();
    $ref = $items[0]['payload']['evidence'][0] ?? null;
    expect($ref)->toBeString()
        ->and($state['evidence_pool'][$ref]['url'] ?? null)->toBe('https://example.com/notice/1');

    Notification::assertNotified('已录入：旧集镇 → 新城街道');

    // 审核列表正常出现该记录（同 AI 产物待审）
    Livewire::test(AreaUpgradePage::class)
        ->set('activeTab', 'review')
        ->assertSee('撤旧集镇设新城街道');
});

it('向导 schema 内组件 key 全局唯一（同键会渲染重复 wire:partial，Livewire morph 抛错致 repeater Add 失灵）', function (): void {
    config()->set('cmf-area.upgrade.enabled', true);
    setupWizardEnv();
    actingAsTestUser();
    startWizardUpgrade();

    foreach (['merge', 'retire', 'appear', 'rename', 'reuse', 'complex'] as $scenario) {
        $test = Livewire::test(AreaUpgradePage::class)
            ->mountAction('manualItem')
            ->setActionData(['scenario' => $scenario]);

        $schema = $test->instance()->getSchema('mountedActionSchema0');
        expect($schema)->not->toBeNull();

        $keys = [];
        $walk = function (array $components) use (&$walk, &$keys): void {
            foreach ($components as $component) {
                $key = $component->getKey();
                if (filled($key)) {
                    $keys[] = $key;
                }
                if (method_exists($component, 'getChildSchemas')) {
                    foreach ($component->getChildSchemas() as $childSchema) {
                        $walk($childSchema->getComponents());
                    }
                }
            }
        };
        $walk($schema->getComponents());

        $duplicates = array_keys(array_filter(array_count_values($keys), fn (int $c): bool => $c > 1));
        expect($duplicates)->toBe([], "scenario={$scenario} 存在同键组件：".implode(', ', $duplicates));
    }
});

it('merge 场景点 repeater Add 按钮能新增例外下级条目（回归：点击无反应）', function (): void {
    config()->set('cmf-area.upgrade.enabled', true);
    setupWizardEnv();
    actingAsTestUser();
    startWizardUpgrade();

    Livewire::test(AreaUpgradePage::class)
        ->mountAction('manualItem')
        ->setActionData(['scenario' => 'merge'])
        // 与浏览器一致：wire:click="mountAction('add', {}, {schemaComponent: ...})"
        ->call('mountAction', 'add', [], ['schemaComponent' => 'mountedActionSchema0.exceptions'])
        ->assertOk()
        ->assertSet('mountedActions.0.data.exceptions', fn ($v): bool => is_array($v) && count($v) === 1);
});

it('merge 填了例外下级：edge + retired 端点 node（例外只挂 node，端点 node 是纯注脚）', function (): void {
    config()->set('cmf-area.upgrade.enabled', true);
    setupWizardEnv();
    actingAsTestUser();
    startWizardUpgrade();

    Livewire::test(AreaUpgradePage::class)
        ->callAction('manualItem', data: [
            'scenario' => 'merge',
            'from_id' => 500235,
            'to_id' => 500239,
            'exceptions' => [['id' => 500235001, 'reason' => '旧集村同期撤销，去向未交代']],
            'notice_title' => '市政府公告',
            'notice_url' => 'https://example.com/notice/2',
        ]);

    $items = app(UpgradeWorkspace::class)->items();
    expect($items)->toHaveCount(2)
        ->and($items[0]['kind'])->toBe('edge')
        ->and($items[1]['kind'])->toBe('node')
        ->and($items[1]['payload']['state'])->toBe('retired')
        ->and($items[1]['payload']['id'])->toBe(500235)
        ->and($items[1]['payload']['exceptions'])->toBe([['id' => 500235001, 'reason' => '旧集村同期撤销，去向未交代']]);
});

it('appear 新设（无前身）：1 条 appeared node', function (): void {
    config()->set('cmf-area.upgrade.enabled', true);
    setupWizardEnv();
    actingAsTestUser();
    startWizardUpgrade();

    Livewire::test(AreaUpgradePage::class)
        ->callAction('manualItem', data: [
            'scenario' => 'appear',
            'new_id' => 500240,
            'notice_title' => '市政府公告',
            'notice_url' => 'https://example.com/notice/3',
        ]);

    $items = app(UpgradeWorkspace::class)->items();
    expect($items)->toHaveCount(1)
        ->and($items[0]['kind'])->toBe('node')
        ->and($items[0]['payload']['state'])->toBe('appeared')
        ->and($items[0]['payload']['id'])->toBe(500240)
        ->and($items[0]['payload']['summary'])->toBe('新设高新街道');

    Notification::assertNotified('已录入：新设 高新街道');
});

it('retire 撤销（无承继）：1 条 retired node', function (): void {
    config()->set('cmf-area.upgrade.enabled', true);
    setupWizardEnv();
    actingAsTestUser();
    startWizardUpgrade();

    Livewire::test(AreaUpgradePage::class)
        ->callAction('manualItem', data: [
            'scenario' => 'retire',
            'old_id' => 500241,
            'notice_title' => '市政府公告',
            'notice_url' => 'https://example.com/notice/4',
        ]);

    $items = app(UpgradeWorkspace::class)->items();
    expect($items)->toHaveCount(1)
        ->and($items[0]['payload']['state'])->toBe('retired')
        ->and($items[0]['payload']['id'])->toBe(500241);

    Notification::assertNotified('已录入：撤销 景区管委会（无承继）');
});

it('rename 改名/换隶属：continued node，attributes 由勾选字段自动生成字段名清单', function (): void {
    config()->set('cmf-area.upgrade.enabled', true);
    setupWizardEnv();
    actingAsTestUser();
    startWizardUpgrade();

    Livewire::test(AreaUpgradePage::class)
        ->callAction('manualItem', data: [
            'scenario' => 'rename',
            'rename_id' => 500234,
            'rename_attributes' => ['name'],
            'notice_title' => '市政府公告',
            'notice_url' => 'https://example.com/notice/5',
        ]);

    $items = app(UpgradeWorkspace::class)->items();
    expect($items)->toHaveCount(1)
        ->and($items[0]['payload']['state'])->toBe('continued')
        ->and($items[0]['payload']['id'])->toBe(500234)
        ->and($items[0]['payload']['attributes'])->toBe(['name']);

    Notification::assertNotified('已录入：龙田乡 更名为 龙田镇');
});

it('reuse 代码复用：appeared node + id_reuse=true 自动带', function (): void {
    config()->set('cmf-area.upgrade.enabled', true);
    setupWizardEnv();
    actingAsTestUser();
    startWizardUpgrade();

    Livewire::test(AreaUpgradePage::class)
        ->callAction('manualItem', data: [
            'scenario' => 'reuse',
            'reuse_id' => 500236,
            'summary' => '老农场撤销后代码由新社区启用',
            'notice_title' => '市政府公告',
            'notice_url' => 'https://example.com/notice/6',
        ]);

    $items = app(UpgradeWorkspace::class)->items();
    expect($items)->toHaveCount(1)
        ->and($items[0]['payload']['state'])->toBe('appeared')
        ->and($items[0]['payload']['id'])->toBe(500236)
        ->and($items[0]['payload']['id_reuse'])->toBeTrue();

    Notification::assertNotified('已录入：新社区启用旧代码500236');
});

it('complex 复杂情形（高级模式）：可录入裸 node/edge（原能力回归）', function (): void {
    config()->set('cmf-area.upgrade.enabled', true);
    setupWizardEnv();
    actingAsTestUser();
    startWizardUpgrade();

    Livewire::test(AreaUpgradePage::class)
        ->callAction('manualItem', data: [
            'scenario' => 'complex',
            'complex' => [
                'complex_kind' => 'node',
                'node' => [
                    'id' => 500240,
                    'state' => 'appeared',
                    'summary' => '高级模式：新设高新街道',
                    'new_evidence' => [['title' => '市政府公告', 'url' => 'https://example.com/notice/7']],
                ],
            ],
        ]);

    Livewire::test(AreaUpgradePage::class)
        ->callAction('manualItem', data: [
            'scenario' => 'complex',
            'complex' => [
                'complex_kind' => 'edge',
                'edge' => [
                    'from_id' => 500235,
                    'to_id' => 500239,
                    'summary' => '高级模式：旧集镇并入新城街道',
                    'new_evidence' => [['title' => '市政府公告', 'url' => 'https://example.com/notice/8']],
                ],
            ],
        ]);

    $items = app(UpgradeWorkspace::class)->items();
    expect($items)->toHaveCount(2)
        ->and($items[0]['kind'])->toBe('node')
        ->and($items[0]['payload']['state'])->toBe('appeared')
        ->and($items[0]['payload']['id'])->toBe(500240)
        ->and($items[1]['kind'])->toBe('edge')
        ->and($items[1]['payload']['from_id'])->toBe(500235)
        ->and($items[1]['payload']['to_id'])->toBe(500239);

    Notification::assertNotified('已录入：旧集镇 → 新城街道');
});

it('校验未通过时原样展示错误、不落库', function (): void {
    config()->set('cmf-area.upgrade.enabled', true);
    setupWizardEnv();
    actingAsTestUser();
    startWizardUpgrade();

    // 跨层对应（deep 3 → deep 2）未写 cross_level_reason → I3 报错
    Livewire::test(AreaUpgradePage::class)
        ->callAction('manualItem', data: [
            'scenario' => 'merge',
            'from_id' => 500235001,
            'to_id' => 500239,
            'notice_title' => '市政府公告',
            'notice_url' => 'https://example.com/notice/9',
        ]);

    expect(app(UpgradeWorkspace::class)->items())->toBe([]);
    Notification::assertNotified('校验未通过，未保存');
});
