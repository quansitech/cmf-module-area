<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Quansitech\Cmf\Area\Tests\Fixtures\Models\TestUser;
use Quansitech\Cmf\Area\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');
uses(TestCase::class, RefreshDatabase::class)->in('Unit');

/**
 * 创建测试用户并登录（默认授予全部 Gate 权限）。
 */
function actingAsTestUser(bool $grantAll = true): TestUser
{
    $user = TestUser::query()->create([
        'name' => '测试用户',
        'email' => 'tester@example.com',
        'password' => 'password',
    ]);

    Illuminate\Support\Facades\Auth::login($user);

    if ($grantAll) {
        grantAllPermissions();
    }

    return $user;
}

/**
 * 放行全部权限（等价宿主的 super_admin Gate::before）。
 */
function grantAllPermissions(): void
{
    Illuminate\Support\Facades\Gate::before(fn (): bool => true);
}

/**
 * 只迁移模块的建表/结构迁移（跳过 4 万行的全量数据 seed，加速测试）。
 */
function migrateAreaSchema(): void
{
    $files = glob(__DIR__.'/../database/migrations/2026_09_1*_*.php') ?: [];
    foreach ($files as $file) {
        if (str_contains(basename($file), '_seed_')) {
            continue;
        }
        Illuminate\Support\Facades\Artisan::call('migrate', ['--path' => $file, '--realpath' => true]);
    }
}

/**
 * 快速写入地区行。
 */
function createArea(array $attributes): Quansitech\Cmf\Area\Models\Area
{
    return Quansitech\Cmf\Area\Models\Area::query()->create([
        'pid' => 0,
        'deep' => 0,
        'name' => '测试',
        'pinyin_prefix' => 'c',
        'pinyin' => 'ce shi',
        'ext_id' => $attributes['id'] * 1000000,
        'ext_name' => '测试地区',
        'status' => 1,
        ...$attributes,
    ]);
}

/**
 * 生成迷你 csv fixture（v2 语义用例用），返回文件路径。
 * 行格式：[id, pid, deep, name, ext_name]（pinyin/ext_id 自动填充）。
 *
 * @param  list<array{int, int, int, string, string}>  $rows
 */
function writeAreaCsvFixture(string $fileName, array $rows): string
{
    $path = sys_get_temp_dir().'/'.$fileName;
    $fh = fopen($path, 'w');
    fputcsv($fh, ['id', 'pid', 'deep', 'name', 'pinyin_prefix', 'pinyin', 'ext_id', 'ext_name']);
    foreach ($rows as [$id, $pid, $deep, $name, $extName]) {
        fputcsv($fh, [$id, $pid, $deep, $name, 'c', 'ce shi', $id * 1000000, $extName]);
    }
    fclose($fh);

    return $path;
}

/**
 * 隔离的升级环境：tmp 工作区 + tmp 基线目录（fixture 旧版 csv 充当基线）
 * + tmp config 文件；上游下载打桩为 fixture 新版 csv。
 *
 * @return array{work_dir: string, data_dir: string, config_file: string}
 */
function setupUpgradeEnv(): array
{
    $base = sys_get_temp_dir().'/upgrade-test-'.getmypid().'-'.bin2hex(random_bytes(4));
    $workDir = $base.'/workspace';
    $dataDir = $base.'/data';
    mkdir($workDir, 0755, true);
    mkdir($dataDir, 0755, true);
    copy(__DIR__.'/Fixtures/data/old_cmf_areas.csv', $dataDir.'/ok_data_level4.csv');

    $configFile = $base.'/cmf-area.php';
    copy(dirname(__DIR__).'/config/cmf-area.php', $configFile);

    config()->set('cmf-area.upgrade.work_dir', $workDir);
    config()->set('cmf-area.upgrade.data_dir', $dataDir);
    config()->set('cmf-area.upgrade.config_file', $configFile);
    config()->set('cmf-area.data_version', '2025.251231.260403');

    app()->instance(Quansitech\Cmf\Area\Services\UpstreamService::class, new class extends Quansitech\Cmf\Area\Services\UpstreamService
    {
        public function download(string $version, ?string $output = null, ?string $workDir = null): string
        {
            return __DIR__.'/Fixtures/data/new_cmf_areas.csv';
        }

        public function versions(): array
        {
            return ['2026.260101.260101', '2025.251231.260403', '2024.240101.240101'];
        }
    });

    return ['work_dir' => $workDir, 'data_dir' => $dataDir, 'config_file' => $configFile];
}

/**
 * 发起升级并把北京（11）、新疆（65）选入范围。
 *
 * @return array<string, mixed>
 */
function startUpgradeWithRegions(): array
{
    $flow = app(Quansitech\Cmf\Area\Services\UpgradeFlowService::class);
    $state = $flow->start('2026.260101.260101');

    return $flow->updateRegions($state, [65, 11]);
}

/**
 * 人工录入向导的升级环境：自定义新旧 csv（一对 fixture 覆盖撤并/新设/撤销/改名/复用
 * 五类变化事实，互不耦合于共享 fixture），重庆（50）整省选入范围。
 *
 * 旧 → 新：
 *  - 500234 龙田乡 → 龙田镇（改名，continued）
 *  - 500235 旧集镇 → 500239 新城街道（撤并/换码，edge；500235001 旧集村为 merge 例外下级）
 *  - 500240 高新街道（新设无前身，appeared）
 *  - 500241 景区管委会（撤销无承继，retired）
 *  - 500236 老农场 → 新社区（同 id 换单位，id_reuse）
 *
 * @return array{work_dir: string, data_dir: string, config_file: string}
 */
function setupWizardEnv(): array
{
    $env = setupUpgradeEnv();

    $oldCsv = writeAreaCsvFixture('wizard_old_'.bin2hex(random_bytes(4)).'.csv', [
        [50, 0, 0, '重庆', '重庆市'],
        [50023, 50, 1, '江北', '江北区'],
        [500234, 50023, 2, '龙田乡', '龙田乡'],
        [500235, 50023, 2, '旧集镇', '旧集镇'],
        [500235001, 500235, 3, '旧集村', '旧集村'],
        [500236, 50023, 2, '老农场', '老农场'],
        [500241, 50023, 2, '景区管委会', '景区管委会'],
    ]);
    $newCsv = writeAreaCsvFixture('wizard_new_'.bin2hex(random_bytes(4)).'.csv', [
        [50, 0, 0, '重庆', '重庆市'],
        [50023, 50, 1, '江北', '江北区'],
        [500234, 50023, 2, '龙田镇', '龙田镇'],
        [500236, 50023, 2, '新社区', '新社区'],
        [500239, 50023, 2, '新城街道', '新城街道'],
        [500240, 50023, 2, '高新街道', '高新街道'],
    ]);
    copy($oldCsv, $env['data_dir'].'/ok_data_level4.csv');

    app()->instance(Quansitech\Cmf\Area\Services\UpstreamService::class, new class($newCsv) extends Quansitech\Cmf\Area\Services\UpstreamService
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

    return $env;
}

/**
 * 在向导 fixture 环境上发起升级并选中重庆（50）。
 *
 * @return array<string, mixed>
 */
function startWizardUpgrade(): array
{
    $flow = app(Quansitech\Cmf\Area\Services\UpgradeFlowService::class);
    $state = $flow->start('2026.260101.260101');

    return $flow->updateRegions($state, [50]);
}

/**
 * 生成假 pi 可执行脚本（CollectAgentRunner 测试用）：按行为模式处理 changes_fragment.json。
 *
 *  - good：直接复制有效片段（changes_split.json）
 *  - flaky：第一次写坏片段（缺朝阳区认领），第二次起写有效片段
 *  - bad：永远写坏片段
 *  - none：异常退出且不落产物
 *
 * @return string 脚本路径
 */
function fakePiScript(string $behavior): string
{
    $good = __DIR__.'/Fixtures/data/changes_split.json';

    $bad = sys_get_temp_dir().'/fake_pi_bad_'.bin2hex(random_bytes(4)).'.json';
    $badContent = json_decode((string) file_get_contents($good), true);
    array_pop($badContent['changes']);
    file_put_contents($bad, json_encode($badContent));

    $marker = sys_get_temp_dir().'/fake_pi_marker_'.bin2hex(random_bytes(4));

    $body = match ($behavior) {
        'good' => "cp '{$good}' \"\$PWD/changes_fragment.json\"",
        'flaky' => "if [ -f '{$marker}' ]; then cp '{$good}' \"\$PWD/changes_fragment.json\"; else touch '{$marker}'; cp '{$bad}' \"\$PWD/changes_fragment.json\"; fi",
        'bad' => "cp '{$bad}' \"\$PWD/changes_fragment.json\"",
        'none' => "echo 'boom' >&2; exit 1",
        // 模拟长任务 agent：起一个后台子进程（模拟工具调用）后自身长眠，
        // 供超时灭杀测试断言整组（含工具子进程）被收掉
        'slow' => "sleep 60 & echo \$! > \"\$0.childpid\"; sleep 60",
        default => throw new InvalidArgumentException($behavior),
    };

    $script = sys_get_temp_dir().'/fake_pi_'.bin2hex(random_bytes(4)).'.sh';
    file_put_contents($script, "#!/bin/bash\n{$body}\n");
    chmod($script, 0755);

    return $script;
}
