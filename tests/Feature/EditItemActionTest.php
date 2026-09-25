<?php

declare(strict_types=1);

use Filament\Notifications\Notification;
use Livewire\Livewire;
use Quansitech\Cmf\Area\Filament\Pages\AreaUpgradePage;
use Quansitech\Cmf\Area\Services\UpgradeWorkspace;

/**
 * 审核记录编辑动作（editItem）：浏览器里点卡片上的「编辑」按钮，
 * 渲染出的 wire:click 必须携带 item 参数（Filament v5 只有 __invoke 渲染
 * 才会把参数嵌进 mountAction；->arguments() 只设默认值不嵌入），
 * 否则 fillForm 拿到空参数 → 表单空白（本文件的回归场景）。
 */

function addEditItemFixture(string $kind = 'node', bool $withEvidence = false): int
{
    $evidence = $withEvidence ? ['fx-2024'] : null;
    $payload = $kind === 'edge'
        ? array_filter(['kind' => 'edge', 'from_id' => 500235, 'to_id' => 500239, 'summary' => '撤旧集镇设新城街道', 'confidence' => 'high', 'evidence' => $evidence])
        : array_filter(['kind' => 'node', 'id' => 500235, 'state' => 'retired', 'summary' => '撤销旧集镇', 'confidence' => 'low', 'evidence' => $evidence]);

    if ($withEvidence) {
        app(UpgradeWorkspace::class)->saveState(['evidence_pool' => ['fx-2024' => ['title' => '区划调整公告', 'url' => 'https://example.com/notice']]]);
    }

    return app(UpgradeWorkspace::class)->addItem(['kind' => $kind, 'payload' => $payload])['id'];
}

it('卡片上的编辑/驳回按钮渲染时携带 item 参数（浏览器点击路径）', function (): void {
    config()->set('cmf-area.upgrade.enabled', true);
    setupWizardEnv();
    actingAsTestUser();
    startWizardUpgrade();
    $id = addEditItemFixture();

    $html = Livewire::test(AreaUpgradePage::class)->set('activeTab', 'review')->html();

    expect($html)->toContain("mountAction('editItem', JSON.parse('{\\u0022item\\u0022:{$id}}')")
        ->and($html)->toContain("mountAction('rejectItem', JSON.parse('{\\u0022item\\u0022:{$id}}')");
});

it('编辑动作打开时预填当前记录内容（node）', function (): void {
    config()->set('cmf-area.upgrade.enabled', true);
    setupWizardEnv();
    actingAsTestUser();
    startWizardUpgrade();
    $id = addEditItemFixture();

    Livewire::test(AreaUpgradePage::class)
        ->mountAction('editItem', ['item' => $id])
        ->assertActionDataSet(['id' => 500235, 'state' => 'retired', 'summary' => '撤销旧集镇', 'confidence' => 'low']);
});

it('编辑动作打开时预填当前记录内容（edge）', function (): void {
    config()->set('cmf-area.upgrade.enabled', true);
    setupWizardEnv();
    actingAsTestUser();
    startWizardUpgrade();
    $id = addEditItemFixture('edge');

    Livewire::test(AreaUpgradePage::class)
        ->mountAction('editItem', ['item' => $id])
        ->assertActionDataSet(['from_id' => 500235, 'to_id' => 500239, 'summary' => '撤旧集镇设新城街道']);
});

it('编辑保存：校验通过落库并标 edited', function (): void {
    config()->set('cmf-area.upgrade.enabled', true);
    setupWizardEnv();
    actingAsTestUser();
    startWizardUpgrade();
    $id = addEditItemFixture('edge', true);

    Livewire::test(AreaUpgradePage::class)
        ->mountAction('editItem', ['item' => $id])
        ->setActionData(['summary' => '改后的摘要'])
        ->callMountedAction();

    $item = app(UpgradeWorkspace::class)->findItem($id);
    expect($item['payload']['summary'])->toBe('改后的摘要')
        ->and($item['review_status'])->toBe(UpgradeWorkspace::REVIEW_EDITED);

    Notification::assertNotified('已保存（标 edited）');
});
