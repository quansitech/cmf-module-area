<?php

declare(strict_types=1);

use Quansitech\Cmf\Area\Services\CollectAgentRunner;
use Quansitech\Cmf\Area\Services\UpgradeFlowService;
use Quansitech\Cmf\Area\Services\UpgradeWorkspace;

/**
 * CollectAgentRunner（升级方案 §11-A）：Web 一键拉起 pi 完成判读并回收。
 * 测试用假 pi 脚本（fakePiScript，见 tests/Pest.php）替代真实 agent。
 */

it('AI 判读闭环：假 pi 产出有效片段 → 机器校验全绿 → 落库待审核', function (): void {
    setupUpgradeEnv();
    config()->set('cmf-area.upgrade.agent_command', fakePiScript('good'));
    $state = startUpgradeWithRegions();

    [$state, $errors] = app(CollectAgentRunner::class)->run($state);

    expect($errors)->toBe([])
        ->and($state['collection_state']['65']['status'])->toBe('passed')
        ->and($state['collection_state']['11']['status'])->toBe('passed')
        ->and(app(UpgradeWorkspace::class)->items())->not->toBeEmpty();
});

it('AI 判读重试：首轮产物校验错误喂回，次轮修正后全绿', function (): void {
    setupUpgradeEnv();
    config()->set('cmf-area.upgrade.agent_command', fakePiScript('flaky'));
    config()->set('cmf-area.upgrade.max_retries', 3);
    $state = startUpgradeWithRegions();

    [$state, $errors] = app(CollectAgentRunner::class)->run($state);

    expect($errors)->toBe([])
        ->and($state['collection_state']['11']['status'])->toBe('passed')
        ->and($state['collection_state']['11']['retries'])->toBe(1)
        ->and(app(UpgradeWorkspace::class)->items())->not->toBeEmpty();
});

it('AI 判读超限：产物持续不过，地区标 failed 转人工', function (): void {
    setupUpgradeEnv();
    config()->set('cmf-area.upgrade.agent_command', fakePiScript('bad'));
    config()->set('cmf-area.upgrade.max_retries', 2);
    $state = startUpgradeWithRegions();

    [$state, $errors] = app(CollectAgentRunner::class)->run($state);

    expect($errors)->not->toBe([])
        ->and($state['collection_state']['11']['status'])->toBe('failed')
        ->and($state['collection_state']['11']['retries'])->toBe(2)
        ->and(app(UpgradeWorkspace::class)->items())->toBeEmpty();
});

it('AI 判读进程异常：错误写回采集状态（不抛出）', function (): void {
    setupUpgradeEnv();
    config()->set('cmf-area.upgrade.agent_command', fakePiScript('none'));
    config()->set('cmf-area.upgrade.max_retries', 1);
    $state = startUpgradeWithRegions();

    [$state, $errors] = app(CollectAgentRunner::class)->run($state);

    expect(implode(' ', $errors))->toContain('异常退出')
        ->and($state['collection_state']['11']['status'])->toBe('failed');
});

it('AI 判读可用性：agent_command 为空时不可用', function (): void {
    config()->set('cmf-area.upgrade.agent_command', '');
    expect(app(CollectAgentRunner::class)->available())->toBeFalse();
    config()->set('cmf-area.upgrade.agent_command', 'pi');
    expect(app(CollectAgentRunner::class)->available())->toBeTrue();
});

it('模型与 skill 配置拼进 agent 命令行（pi --model/--skill）', function (): void {
    setupUpgradeEnv();

    // 假 pi：记录 argv 后产出有效片段
    $good = __DIR__.'/../Fixtures/data/changes_split.json';
    $script = sys_get_temp_dir().'/fake_pi_argv_'.bin2hex(random_bytes(4)).'.sh';
    file_put_contents($script, "#!/bin/bash\nprintf '%s\\n' \"\$@\" > \"\$PWD/argv.log\"\ncp '{$good}' \"\$PWD/changes_fragment.json\"\n");
    chmod($script, 0755);

    config()->set('cmf-area.upgrade.agent_command', $script);
    config()->set('cmf-area.upgrade.agent_model', 'anthropic/claude-sonnet-4-5:high');
    config()->set('cmf-area.upgrade.agent_skills', [dirname(__DIR__, 2).'/skill']);
    $state = startUpgradeWithRegions();

    [, $errors] = app(CollectAgentRunner::class)->run($state);
    expect($errors)->toBe([]);

    $argv = file(app(UpgradeWorkspace::class)->dir().'/collect/argv.log', FILE_IGNORE_NEW_LINES);
    expect($argv)->toContain('-p')
        ->and($argv)->toContain('--no-session')
        ->and($argv)->toContain('--model')
        ->and($argv)->toContain('anthropic/claude-sonnet-4-5:high')
        ->and($argv)->toContain('--skill')
        ->and($argv)->toContain(dirname(__DIR__, 2).'/skill');
});

it('判读 prompt 附已入库不可替换记录摘要（撞键预警）；全 AI 未审定时不附', function (): void {
    setupUpgradeEnv();

    // 假 pi：记录完整 prompt（最后一个参数）后产出有效片段
    $good = __DIR__.'/../Fixtures/data/changes_split.json';
    $script = sys_get_temp_dir().'/fake_pi_prompt_'.bin2hex(random_bytes(4)).'.sh';
    file_put_contents($script, "#!/bin/bash\nprintf '%s' \"\${@: -1}\" > \"\$PWD/prompt.txt\"\ncp '{$good}' \"\$PWD/changes_fragment.json\"\n");
    chmod($script, 0755);
    config()->set('cmf-area.upgrade.agent_command', $script);

    $state = startUpgradeWithRegions();
    $workspace = app(UpgradeWorkspace::class);

    // 预置一条与夹具同键同内容的人工记录（幂等跳过，不冲突；且不会被替换 → 应出现在 prompt 摘要里）
    $workspace->addItem([
        'kind' => 'edge',
        'payload' => ['kind' => 'edge', 'from_id' => 653223, 'to_id' => 653228, 'summary' => '和康县析自皮山县（皮山县存续，单位级浅层值进人工清单）', 'evidence' => ['wiki-2024'], 'confidence' => 'high'],
        'source' => UpgradeWorkspace::SOURCE_MANUAL,
        'region' => '新疆维吾尔自治区',
    ]);

    [, $errors] = app(CollectAgentRunner::class)->run($workspace->state());
    expect($errors)->toBe([]);

    $prompt = (string) file_get_contents($workspace->dir().'/collect/prompt.txt');
    expect($prompt)->toContain('已入库且不会被本次回收替换')
        ->toContain('edge:653223→653228')
        ->toContain('人工录入');
});

it('agent_model 为空 / agent_skills 目录不存在时不拼对应参数', function (): void {
    setupUpgradeEnv();

    $good = __DIR__.'/../Fixtures/data/changes_split.json';
    $script = sys_get_temp_dir().'/fake_pi_argv_'.bin2hex(random_bytes(4)).'.sh';
    file_put_contents($script, "#!/bin/bash\nprintf '%s\\n' \"\$@\" > \"\$PWD/argv.log\"\ncp '{$good}' \"\$PWD/changes_fragment.json\"\n");
    chmod($script, 0755);

    config()->set('cmf-area.upgrade.agent_command', $script);
    config()->set('cmf-area.upgrade.agent_model', null);
    config()->set('cmf-area.upgrade.agent_skills', ['/nonexistent-skill-dir']);
    $state = startUpgradeWithRegions();

    [, $errors] = app(CollectAgentRunner::class)->run($state);
    expect($errors)->toBe([]);

    $argv = file(app(UpgradeWorkspace::class)->dir().'/collect/argv.log', FILE_IGNORE_NEW_LINES);
    expect($argv)->not->toContain('--model')
        ->and($argv)->not->toContain('--skill');
});

it('命令行模式：area:collect --run-agent 全流程并输出起止标记', function (): void {
    setupUpgradeEnv();
    config()->set('cmf-area.upgrade.agent_command', fakePiScript('good'));
    startUpgradeWithRegions();

    \Illuminate\Support\Facades\Artisan::call('area:collect', ['--run-agent' => true]);
    $output = \Illuminate\Support\Facades\Artisan::output();

    expect($output)->toContain('__AGENT_RUN_START__')
        ->toContain('[判读] 任务包已生成')
        ->toContain('[校验] 判读产物机器校验全绿')
        ->toContain('__AGENT_RUN_DONE__:ok');
    expect(app(UpgradeWorkspace::class)->items())->not->toBeEmpty();
});

it('后台拉起：写入 agent_run 状态、防重入、DONE 后可重启', function (): void {
    setupUpgradeEnv();
    $state = startUpgradeWithRegions();
    $workspace = app(UpgradeWorkspace::class);

    // stub 实际进程拉起（后台是独立 PHP 进程，测试环境 config 传不过去）
    $runner = new class($workspace, app(UpgradeFlowService::class)) extends CollectAgentRunner
    {
        public int $launchCount = 0;

        protected function launchBackground(string $logFile, array $regionIds): void
        {
            $this->launchCount++;
        }
    };

    $state = $runner->startBackground($state, [65]);
    expect($runner->launchCount)->toBe(1)
        ->and($state['agent_run']['regions'])->toBe([65])
        ->and($state['agent_run']['log'])->toEndWith('agent-run.log')
        ->and(is_file($state['agent_run']['log']))->toBeTrue();

    // 无 pid 文件（进程未真正拉起）→ 不拦截重启
    $runner->startBackground($workspace->state());
    expect($runner->launchCount)->toBe(2);

    // 模拟进程运行中（pid 存活 + 日志无 DONE）→ 重入被拒
    $log = $workspace->state()['agent_run']['log'];
    file_put_contents($log.'.pid', (string) getmypid());
    expect(fn () => $runner->startBackground($workspace->state()))->toThrow(RuntimeException::class, '正在进行中');
    expect($runner->launchCount)->toBe(2);

    // 写入 DONE 标记（模拟上轮进程正常结束）→ 允许重启，日志清空
    file_put_contents($log, "xxx\n__AGENT_RUN_DONE__:ok\n");
    $state2 = $runner->startBackground($workspace->state());
    expect($runner->launchCount)->toBe(3)
        ->and(file_get_contents($state2['agent_run']['log']))->toBe('');

    // pid 指向已死进程且无 DONE（进程被杀/崩溃）→ 允许重启，不永久卡死
    file_put_contents($state2['agent_run']['log'], "半截输出\n");
    file_put_contents($state2['agent_run']['log'].'.pid', '99999999');
    $runner->startBackground($workspace->state());
    expect($runner->launchCount)->toBe(4);
});

it('页面 AI 判读：后台拉起写状态；agentRunInfo 判定运行中/已结束并过滤标记行', function (): void {
    config()->set('cmf-area.upgrade.enabled', true);
    setupUpgradeEnv();
    actingAsTestUser();
    startUpgradeWithRegions();
    $workspace = app(UpgradeWorkspace::class);

    app()->instance(CollectAgentRunner::class, new class($workspace, app(UpgradeFlowService::class)) extends CollectAgentRunner
    {
        protected function launchBackground(string $logFile, array $regionIds): void
        {
            // 模拟 launcher 落 pid 文件（用当前存活进程 pid 冒充运行中的判读进程）
            file_put_contents($logFile.'.pid', (string) getmypid());
        }
    });

    $page = Livewire\Livewire::test(\Quansitech\Cmf\Area\Filament\Pages\AreaUpgradePage::class);
    $page->call('aiCollect', 65);

    $info = $page->instance()->agentRunInfo();
    expect($info)->not->toBeNull()
        ->and($info['regions'])->toBe([65])
        ->and($info['running'])->toBeTrue();

    // 模拟进程输出：系统行 + JSON 事件流（思考/回复/工具调用/协议帧）→ 全部渲染为可读行
    $log = $workspace->state()['agent_run']['log'];
    file_put_contents($log, implode("\n", [
        '[判读] 第 1/3 轮，拉起 agent 判读…',
        '{"type":"message_update","assistantMessageEvent":{"type":"thinking_delta","delta":"想一下"}}',
        '{"type":"message_update","assistantMessageEvent":{"type":"text_delta","delta":"你好，"}}',
        '{"type":"message_update","assistantMessageEvent":{"type":"text_delta","delta":"世界"}}',
        '{"type":"tool_execution_start","toolCallId":"c1","toolName":"bash","args":{"command":"cat <dump.csv"}}',
        '{"type":"tool_execution_end","toolCallId":"c1","toolName":"bash","result":{"content":[{"type":"text","text":"ok"}]},"isError":false}',
        '{"type":"tool_execution_start","toolCallId":"c2","toolName":"read","args":{"path":"/tmp/x.json"}}',
        '{"type":"tool_execution_end","toolCallId":"c2","toolName":"read","result":{"content":[{"type":"text","text":"boom 冲突"}]},"isError":true}',
        '{"type":"message_update","assistantMessageEvent":{"type":"toolcall_delta","partialJson":"{\\"command\\":"}}',
        '__AGENT_RUN_DONE__:ok',
    ])."\n");
    $page2 = Livewire\Livewire::test(\Quansitech\Cmf\Area\Filament\Pages\AreaUpgradePage::class);
    $info2 = $page2->instance()->agentRunInfo();
    expect($info2['running'])->toBeFalse()
        ->and($info2['log_tail'])->toContain('拉起 agent 判读')
        ->and($info2['log_tail'])->toContain('你好，世界')
        ->and($info2['log_tail'])->toContain('想一下')
        ->and($info2['log_tail'])->toContain('执行命令')
        ->and($info2['log_tail'])->toContain('cat &lt;dump.csv')
        ->and($info2['log_tail'])->toContain('读取文件：/tmp/x.json')
        ->and($info2['log_tail'])->toContain('✓ 执行完成')
        ->and($info2['log_tail'])->toContain('✗ 执行失败')
        ->and($info2['log_tail'])->toContain('boom 冲突')
        ->and($info2['log_tail'])->not->toContain('toolcall_delta')
        ->and($info2['log_tail'])->not->toContain('toolCallId')
        ->and($info2['log_tail'])->not->toContain('__AGENT_RUN_DONE__');

    // 采集 tab 渲染判读输出面板（已结束态）
    $page2->set('activeTab', 'collect')
        ->assertSee('判读输出（已结束）')
        ->assertSee('拉起 agent 判读');
});

it('parseModelList：解析 pi --list-models 表格输出', function (): void {
    $runner = app(CollectAgentRunner::class);
    $output = <<<'TXT'
provider     model                         context  max-out  thinking  images
kimi-coding  k3                            1.0M     131.1K   yes       yes
kimi-coding  k3-256k                       262.1K   131.1K   yes       yes
opencode-go  glm-5.3                       1M       131.1K   yes       no

TXT;

    $models = $runner->parseModelList($output);
    expect($models)->toBe([
        'kimi-coding/k3' => 'kimi-coding/k3（1.0M）',
        'kimi-coding/k3-256k' => 'kimi-coding/k3-256k（262.1K）',
        'opencode-go/glm-5.3' => 'opencode-go/glm-5.3（1M）',
    ]);
});

it('工作区选中的判读模型优先于 config agent_model（--model 显式指定）', function (): void {
    setupUpgradeEnv();
    $good = __DIR__.'/../Fixtures/data/changes_split.json';
    $script = sys_get_temp_dir().'/fake_pi_argv_'.bin2hex(random_bytes(4)).'.sh';
    file_put_contents($script, "#!/bin/bash\nprintf '%s\\n' \"\$@\" > \"\$PWD/argv.log\"\ncp '{$good}' \"\$PWD/changes_fragment.json\"\n");
    chmod($script, 0755);

    config()->set('cmf-area.upgrade.agent_command', $script);
    config()->set('cmf-area.upgrade.agent_model', 'anthropic/claude-sonnet-4-5:high');
    $state = startUpgradeWithRegions();
    $state = app(UpgradeWorkspace::class)->saveState(['agent_model' => 'kimi-coding/k3']);

    [, $errors] = app(CollectAgentRunner::class)->run($state);
    expect($errors)->toBe([]);

    $argv = file(app(UpgradeWorkspace::class)->dir().'/collect/argv.log', FILE_IGNORE_NEW_LINES);
    expect($argv)->toContain('--model')
        ->and($argv)->toContain('kimi-coding/k3')
        ->and($argv)->not->toContain('anthropic/claude-sonnet-4-5:high');
});

it('页面判读模型下拉：mount 读工作区、变更即保存（供后台进程读取）', function (): void {
    config()->set('cmf-area.upgrade.enabled', true);
    setupUpgradeEnv();
    actingAsTestUser();
    startUpgradeWithRegions();
    $workspace = app(UpgradeWorkspace::class);

    $page = Livewire\Livewire::test(\Quansitech\Cmf\Area\Filament\Pages\AreaUpgradePage::class);
    expect($page->instance()->agentModel)->toBeNull();

    // 选择模型 → 立即落工作区 state
    $page->set('agentModel', 'kimi-coding/k3');
    expect($workspace->state()['agent_model'] ?? null)->toBe('kimi-coding/k3');

    // 重新挂载 → 从工作区回读
    $page2 = Livewire\Livewire::test(\Quansitech\Cmf\Area\Filament\Pages\AreaUpgradePage::class);
    expect($page2->instance()->agentModel)->toBe('kimi-coding/k3');

    // 清空 → state 置 null（走默认模型）
    $page2->set('agentModel', '');
    expect($workspace->state()['agent_model'] ?? null)->toBeNull();
});

it('flock 内核锁：锁被持有时拒绝重复发起，释放后放行', function (): void {
    setupUpgradeEnv();
    $state = startUpgradeWithRegions();
    $workspace = app(UpgradeWorkspace::class);

    $runner = new class($workspace, app(UpgradeFlowService::class)) extends CollectAgentRunner
    {
        public int $launchCount = 0;

        protected function launchBackground(string $logFile, array $regionIds): void
        {
            $this->launchCount++;
        }
    };

    $state = $runner->startBackground($state, [65]);
    $log = $state['agent_run']['log'];

    // 外部持有判读锁（模拟 launcher/artisan 存活）→ 重入被拒
    $fh = fopen($log.'.lock', 'c');
    flock($fh, LOCK_EX);
    expect(fn () => $runner->startBackground($workspace->state()))->toThrow(RuntimeException::class, '正在进行中');
    expect($runner->launchCount)->toBe(1);

    // 释放锁（且无 pid 文件 → agentProcessAlive false）→ 放行
    flock($fh, LOCK_UN);
    fclose($fh);
    $runner->startBackground($workspace->state());
    expect($runner->launchCount)->toBe(2);
});

it('手动终止：整组灭杀判读进程树，采集中的地区标记已终止且可重新发起', function (): void {
    setupUpgradeEnv();
    $state = startUpgradeWithRegions();
    $workspace = app(UpgradeWorkspace::class);
    app(UpgradeFlowService::class)->makeCollectPackage($state); // 65/11 进入 collecting

    // 模拟运行中的进程树：外层 setsid（artisan 位，组长）内层再 setsid sleep（pi 位，另一组长）
    $log = $workspace->dir().'/agent-run.log';
    file_put_contents($log, '');
    $artisanPid = (int) trim((string) shell_exec('setsid bash -c "setsid sleep 300 & exec sleep 300" >/dev/null 2>&1 & echo $!'));
    file_put_contents($log.'.pid', (string) $artisanPid);
    usleep(200000);
    $childPids = array_values(array_filter(array_map('intval', preg_split('/\s+/', trim((string) shell_exec('pgrep -P '.$artisanPid))) ?: [])));

    expect($artisanPid)->toBeGreaterThan(0)->and($childPids)->not->toBeEmpty()
        ->and(app(CollectAgentRunner::class)->agentProcessAlive($log))->toBeTrue();

    $state = app(CollectAgentRunner::class)->terminate($workspace->state(), $log);

    // 等内核回收（zombie 消失前 /proc 仍在）
    $deadline = microtime(true) + 3;
    $allGone = fn (): bool => ! is_dir("/proc/{$artisanPid}")
        && array_filter($childPids, fn (int $p): bool => is_dir("/proc/{$p}")) === [];
    while (! $allGone() && microtime(true) < $deadline) {
        usleep(50000);
    }
    expect($allGone())->toBeTrue('artisan 与 pi 子进程应全部被灭杀');
    expect(app(CollectAgentRunner::class)->agentProcessAlive($log))->toBeFalse();

    // 采集中的地区标记已终止（≠failed，不消耗重试配额），日志留终止行
    foreach (['65', '11'] as $key) {
        expect($state['collection_state'][$key]['status'])->toBe('terminated');
    }
    expect((string) file_get_contents($log))->toContain('[终止]');
});

it('passed 地区也可重新发起 AI 判读（按钮常显，不区分状态）', function (): void {
    config()->set('cmf-area.upgrade.enabled', true);
    setupUpgradeEnv();
    actingAsTestUser();
    $state = startUpgradeWithRegions();
    $workspace = app(UpgradeWorkspace::class);

    // 置 65 为 passed（模拟一轮判读已校验通过）
    $state['collection_state']['65'] = ['status' => 'passed', 'retries' => 0, 'last_errors' => [], 'feedback' => null];
    $workspace->saveState($state);

    $launched = [];
    app()->instance(CollectAgentRunner::class, new class($workspace, app(UpgradeFlowService::class), $launched) extends CollectAgentRunner
    {
        public function __construct(
            UpgradeWorkspace $workspace,
            UpgradeFlowService $flow,
            public array &$launched,
        ) {
            parent::__construct($workspace, $flow);
        }

        protected function launchBackground(string $logFile, array $regionIds): void
        {
            $this->launched[] = $regionIds;
        }
    });

    $page = Livewire\Livewire::test(\Quansitech\Cmf\Area\Filament\Pages\AreaUpgradePage::class);
    $page->set('activeTab', 'collect')
        ->assertSee('重新判读')
        ->assertDontSee('AI 判读全部未通过地区')
        ->call('aiCollect', 65);

    expect($launched)->toBe([[65]]);
    expect($workspace->state()['agent_run']['regions'] ?? null)->toBe([65]);
});

it('判读超时：整组灭杀（含 agent 的工具子进程），错误标记为超时', function (): void {
    setupUpgradeEnv();
    $script = fakePiScript('slow');
    config()->set('cmf-area.upgrade.agent_command', $script);
    config()->set('cmf-area.upgrade.agent_timeout', 1);
    $state = startUpgradeWithRegions();

    $start = time();
    [, $errors] = app(CollectAgentRunner::class)->run($state, [65]);
    $elapsed = time() - $start;

    expect(implode("\n", $errors))->toContain('超时');
    // 3 轮各 1s 超时即杀（不等 fake 脚本的 60s 自然结束）
    expect($elapsed)->toBeLessThan(30);

    // 最后一轮的工具子进程（组内孤儿）也已被灭杀
    $childPid = (int) trim((string) @file_get_contents($script.'.childpid'));
    expect($childPid)->toBeGreaterThan(0);
    expect(is_dir('/proc/'.$childPid))->toBeFalse();
});

it('failed 地区重新发起判读：组包重置重试配额，新一轮给满 max_retries 次机会', function (): void {
    setupUpgradeEnv();
    config()->set('cmf-area.upgrade.agent_command', fakePiScript('bad'));
    $state = startUpgradeWithRegions();
    $flow = app(UpgradeFlowService::class);
    $runner = app(CollectAgentRunner::class);

    // 第一轮跑到 failed（bad 产物 3 轮全败）
    [$state] = $runner->run($state, [65]);
    expect($state['collection_state']['65']['status'])->toBe('failed');
    expect($state['collection_state']['65']['retries'])->toBe(3);

    // 重新发起：组包重置 retries 并清空上轮错误清单；新一轮第 1 次失败仍是 collecting（而非被历史 retries 立即判死）
    [$state2] = $runner->run($state, [65]);
    // bad 产物下新一轮也跑满 3 轮才 failed —— 证明每轮都真实执行了
    expect($state2['collection_state']['65']['status'])->toBe('failed');
    expect($state2['collection_state']['65']['retries'])->toBe(3);
    // 新一轮的错误清单是本轮产生的（组包时清空了上轮）
    expect($state2['collection_state']['65']['last_errors'])->not->toBeEmpty();
});
