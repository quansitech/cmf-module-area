<?php

declare(strict_types=1);

use Quansitech\Cmf\Area\Services\UpgradeFlowService;
use Quansitech\Cmf\Area\Services\UpgradeWorkspace;

/**
 * 回收幂等与替换语义（真实事故回归：重判同地区时 fragment 与已入库记录撞键，
 * 旧实现一律报笼统 I1 重复，AI 无法理解只好去文件系统考古）：
 * - 本次地区内 AI 未审定记录：校验合并与成功落库同一口径剔除（整体替换，同键重写安全）；
 * - 不会被替换的记录（人工/已审定/其他地区）：同键同内容幂等跳过；同键不同内容报冲突并指明记录 id；
 * - 编辑/录入路径撞键：给"完全重复/内容冲突"明确错误，不再丢给笼统 I1。
 */

function splitFragment(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../Fixtures/data/changes_split.json'), true);
}

function writeFragment(array $fragment): string
{
    $path = sys_get_temp_dir().'/dedupe_frag_'.bin2hex(random_bytes(4)).'.json';
    file_put_contents($path, json_encode($fragment, JSON_UNESCAPED_UNICODE));

    return $path;
}

it('重判同地区：旧 AI 未审定记录被整体替换，同键重写不报重复', function (): void {
    setupUpgradeEnv();
    $state = startUpgradeWithRegions();
    $flow = app(UpgradeFlowService::class);
    $workspace = app(UpgradeWorkspace::class);

    [$state, $errors] = $flow->ingest($state, __DIR__.'/../Fixtures/data/changes_split.json');
    expect($errors)->toBe([]);
    $before = $workspace->items();
    expect($before)->not->toBeEmpty();

    // 第二轮：同键但改了摘要（AI 修正判定细节）——应整体替换而非报重复
    $fragment = splitFragment();
    $fragment['changes'][0]['summary'] = '修正后的判定摘要';
    [$state, $errors] = $flow->ingest($workspace->state(), writeFragment($fragment));

    expect($errors)->toBe([]);
    $after = $workspace->items();
    expect($after)->toHaveCount(count($before))
        ->and($after[0]['payload']['summary'])->toBe('修正后的判定摘要');
});

it('撞不会被替换的记录：同键同内容幂等跳过，不落重复', function (): void {
    setupUpgradeEnv();
    $state = startUpgradeWithRegions();
    $flow = app(UpgradeFlowService::class);
    $workspace = app(UpgradeWorkspace::class);

    [$state] = $flow->ingest($state, __DIR__.'/../Fixtures/data/changes_split.json');
    $before = $workspace->items();

    // 把第一条改为人工录入（不会被替换口径），再原样重收同一 fragment
    $workspace->updateItem((int) $before[0]['id'], ['source' => UpgradeWorkspace::SOURCE_MANUAL]);

    [$state, $errors] = $flow->ingest($workspace->state(), __DIR__.'/../Fixtures/data/changes_split.json');

    expect($errors)->toBe([]);
    $after = $workspace->items();
    // 人工那条幂等跳过（保持原 id），其余 AI 未审定被替换重插：总数不变、无同键重复
    expect($after)->toHaveCount(count($before));
    expect($after[0]['id'])->toBe($before[0]['id'])
        ->and($after[0]['source'])->toBe(UpgradeWorkspace::SOURCE_MANUAL);
});

it('撞不会被替换的记录：同键不同内容报冲突并指明已入库记录 id', function (): void {
    setupUpgradeEnv();
    $state = startUpgradeWithRegions();
    $flow = app(UpgradeFlowService::class);
    $workspace = app(UpgradeWorkspace::class);

    [$state] = $flow->ingest($state, __DIR__.'/../Fixtures/data/changes_split.json');
    $first = $workspace->items()[0];
    $workspace->updateItem((int) $first['id'], ['source' => UpgradeWorkspace::SOURCE_MANUAL]);

    // 同键但改了内容 → 冲突（人工记录不由 AI 判读覆盖）
    $fragment = splitFragment();
    $fragment['changes'][0]['summary'] = '与已入库人工记录冲突的另一判定';
    [$state, $errors] = $flow->ingest($workspace->state(), writeFragment($fragment));

    expect($errors)->not->toBeEmpty();
    expect(implode("\n", $errors))->toContain('内容冲突')
        ->toContain('#'.$first['id'])
        ->toContain('人工录入');
    // 冲突轮次记重试、人工记录原样保留
    expect($workspace->findItem((int) $first['id'])['payload']['summary'])
        ->toBe($first['payload']['summary']);
});

it('编辑/录入路径撞已入库同键：报"完全重复"指明记录，而非笼统 I1', function (): void {
    setupUpgradeEnv();
    $state = startUpgradeWithRegions();
    $flow = app(UpgradeFlowService::class);
    $workspace = app(UpgradeWorkspace::class);

    [$state] = $flow->ingest($state, __DIR__.'/../Fixtures/data/changes_split.json');
    $existing = $workspace->items()[0];

    $errors = $flow->validateItemsWith($workspace->state(), [$existing['payload']]);

    expect($errors)->not->toBeEmpty();
    expect(implode("\n", $errors))->toContain('完全重复')->toContain('#'.$existing['id']);
});

it('storedItemsDigest：只列不会被本次回收替换的记录（人工/已审定），AI 未审定不列出', function (): void {
    setupUpgradeEnv();
    $state = startUpgradeWithRegions();
    $flow = app(UpgradeFlowService::class);
    $workspace = app(UpgradeWorkspace::class);

    [$state] = $flow->ingest($state, __DIR__.'/../Fixtures/data/changes_split.json');
    $first = $workspace->items()[0];

    // 全部是本次地区内 AI 未审定 → 都会被替换 → 无需提示
    expect($flow->storedItemsDigest($workspace->state(), [65, 11]))->toBeNull();

    // 翻成人工录入后进入摘要，含记录号与键
    $workspace->updateItem((int) $first['id'], ['source' => UpgradeWorkspace::SOURCE_MANUAL]);
    $digest = $flow->storedItemsDigest($workspace->state(), [65, 11]);
    expect($digest)->toBeString()
        ->toContain('#'.$first['id'])
        ->toContain('人工录入');
});
