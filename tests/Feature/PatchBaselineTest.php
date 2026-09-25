<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Quansitech\Cmf\Area\Services\ImportService;

/**
 * 基线补丁（升级方案 §7）与自证校验（§6.5）、重放校验（§5 规矩 2）。
 */

/**
 * 把 fixture 复制为临时基线 csv。
 */
function tmpBaselineCsv(string $name = 'baseline.csv'): string
{
    $path = sys_get_temp_dir().'/'.$name;
    copy(__DIR__.'/../Fixtures/data/old_cmf_areas.csv', $path);

    return $path;
}

/**
 * @return array<int, array<string, mixed>>
 */
function loadMap(string $path): array
{
    return app(ImportService::class)->loadCsvAsMap($path);
}

/**
 * 命令输出规范化：去 ANSI 转义与空白（Symfony 按终端宽度折行会切断长消息）。
 */
function normalizedOutput(): string
{
    $output = (string) preg_replace('/\e\[[\d;]*m/', '', Artisan::output());

    return (string) preg_replace('/\s+/', '', $output);
}

it('patch-baseline：审定变更打进基线，与 MigrationGenerator 同源（§7）', function (): void {
    $old = tmpBaselineCsv();
    $out = sys_get_temp_dir().'/patched_baseline.csv';

    $exit = Artisan::call('area:patch-baseline', [
        'changes' => __DIR__.'/../Fixtures/data/changes_split.json',
        '--old' => $old,
        '--new' => __DIR__.'/../Fixtures/data/new_cmf_areas.csv',
        '--output' => $out,
    ]);

    expect($exit)->toBe(0);

    $patched = loadMap($out);
    // insert（added）：和康县一族 + 朝阳区 进基线
    expect($patched)->toHaveKeys([653228, 653228101, 653228102, 110105]);
    // retire（removed）：赛图拉镇、木吉镇旧行移出基线
    expect($patched)->not->toHaveKeys([653223102, 653223103]);
    // 未触及的行一动不动
    expect($patched[653223100]['ext_name'])->toBe('固玛镇');
});

it('patch-baseline 自证校验：范围内仍有差异时阻断（§6.5）', function (): void {
    // changes 漏掉朝阳区（无任何记录引用 110105），补丁后基线缺该行
    $changes = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/data/changes_split.json'), true);
    array_pop($changes['changes']);
    $path = sys_get_temp_dir().'/changes_missing_insert.json';
    file_put_contents($path, json_encode($changes));

    $exit = Artisan::call('area:patch-baseline', [
        'changes' => $path,
        '--old' => tmpBaselineCsv('baseline_selfcheck.csv'),
        '--new' => __DIR__.'/../Fixtures/data/new_cmf_areas.csv',
        '--output' => sys_get_temp_dir().'/patched_selfcheck.csv',
    ]);

    expect($exit)->toBe(1);
    $output = normalizedOutput(); // Artisan::output() 读完即清空，只能取一次
    expect($output)->toContain('自证校验失败')
        ->and($output)->toContain('110105');
});

it('patch-baseline 自证校验：scoped 模式只校验选中范围（未选中地区差异不阻断）', function (): void {
    $changes = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/data/changes_split.json'), true);
    array_pop($changes['changes']); // 漏 110105（北京）
    $path = sys_get_temp_dir().'/changes_scoped_selfcheck.json';
    file_put_contents($path, json_encode($changes));

    $out = sys_get_temp_dir().'/patched_scoped_selfcheck.csv';
    $exit = Artisan::call('area:patch-baseline', [
        'changes' => $path,
        '--old' => tmpBaselineCsv('baseline_scoped.csv'),
        '--new' => __DIR__.'/../Fixtures/data/new_cmf_areas.csv',
        '--output' => $out,
        '--scope' => '65',
    ]);

    expect($exit)->toBe(0);
    // 新疆已补丁、北京 110105 未进基线（留待下次）
    expect(loadMap($out))->toHaveKey(653228)->not->toHaveKey(110105);
});
