<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Services;

use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * AI 判读执行器（升级方案 §11-A：Web 一键拉起本地 agent CLI，默认 pi）。
 *
 * 闭环：makeCollectPackage 组包 → 在任务包目录拉起 pi（print 模式，一次性会话）
 * → pi 按 COLLECT_TASK.md 判读并写出 changes_fragment.json → ingest 机器校验回收。
 * 校验不过时把错误清单拼进下一轮 prompt 重试，重试上限见
 * config cmf-area.upgrade.max_retries（ingest 内部记账，超限地区标 failed 转人工）。
 *
 * AI 产物永远不过信任闸：无论 pi 输出什么，都必须经 ingest 的 scoped
 * 机器校验才落工作区，人工审核闸门保留在迁移生成之前。
 */
class CollectAgentRunner
{
    public function __construct(
        protected readonly UpgradeWorkspace $workspace,
        protected readonly UpgradeFlowService $flow,
    ) {}

    /**
     * 同步执行一轮完整的"组包 → pi 判读 → 回收"（含错误重试）。
     *
     * @param  array<string, mixed>  $state
     * @param  list<int>  $regionIds  本次采集的地区（空 = 全部未通过地区）
     * @param  callable|null  $onOutput  输出回调（签名同 Symfony Process：($type, $buffer)），
     *                                    判读进度与 pi 输出实时透传（页面轮询日志用）
     * @return array{0: array<string, mixed>, 1: list<string>} [新状态, 错误清单（空 = 全绿落库）]
     */
    public function run(array $state, array $regionIds = [], ?callable $onOutput = null): array
    {
        $emit = $onOutput ?? fn (): null => null;
        $dir = $this->flow->makeCollectPackage($state, $regionIds);
        $emit('out', "[判读] 任务包已生成：{$dir}\n");

        // 组包后重读：regionIds 为空时 ingest 按 collection_state 里 collecting 的地区判定
        $state = $this->workspace->state();
        $regionIds = $this->collectingRegionIds($state);
        $emit('out', '[判读] 本次覆盖地区：'.implode(', ', $regionIds)."\n");

        $maxRetries = max(1, (int) config('cmf-area.upgrade.max_retries', 3));
        $fragment = $dir.'/changes_fragment.json';
        if (is_file($fragment)) {
            unlink($fragment);
        }

        $errors = [];
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $emit('out', "[判读] 第 {$attempt}/{$maxRetries} 轮，拉起 agent 判读…\n");
            // 每轮重算：已入库且不会被替换的记录清单喂给 AI，撞键即冲突、勿重复提交
            $digest = $this->flow->storedItemsDigest($this->workspace->state(), $regionIds);
            $processError = $this->runPi($dir, $errors, $onOutput, $state['agent_model'] ?? null, $digest);

            if ($processError !== null || ! is_file($fragment)) {
                $errors = [$processError ?? 'AI 未产出 changes_fragment.json（pi 进程已退出但任务包目录内没有产物）'];
                $emit('out', "[错误] {$errors[0]}\n");
                $state = $this->writeBackErrors($state, $regionIds, $errors, $attempt, $maxRetries);
                if ($attempt < $maxRetries && ! $this->anyFailed($state, $regionIds)) {
                    continue;
                }

                return [$state, $errors];
            }

            [$state, $errors] = $this->flow->ingest($state, $fragment, $regionIds);
            if ($errors === []) {
                $emit('out', "[校验] 判读产物机器校验全绿，已落库为待审核记录。\n");

                return [$state, []];
            }

            // 错误清单已由 ingest 写回采集状态；地区超限标 failed 时停止重试
            $emit('out', sprintf("[校验] 产物校验未通过（%d 条错误）：%s\n", count($errors), implode('；', array_slice($errors, 0, 5))));
            unlink($fragment);
            if ($this->anyFailed($state, $regionIds)) {
                return [$state, $errors];
            }
        }

        return [$state, $errors];
    }

    /**
     * 命令在当前进程环境中是否可解析（command -v 语义，走真实 PATH 查找）。
     */
    protected function commandResolvable(string $command): bool
    {
        $process = Process::fromShellCommandline('command -v -- '.escapeshellarg($command).' >/dev/null 2>&1');
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * 子进程环境：getenv() 读 putenv 进程环境（不受 web 请求生命周期影响），
     * PATH 缺失时补默认值兜底。
     *
     * @return array<string, string>
     */
    protected function processEnv(): array
    {
        $env = getenv();
        $env = is_array($env) ? $env : [];
        if (trim((string) ($env['PATH'] ?? '')) === '') {
            $env['PATH'] = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
        }

        return $env;
    }

    /**
     * 可选模型清单（界面下拉用）：跑 agent --list-models 解析，
     * 结果缓存到工作区 agent-models.json（6h），失败返回空数组。
     *
     * @return array<string, string> "provider/model" => 展示名
     */
    public function availableModels(): array
    {
        $cacheFile = $this->workspace->dir().'/agent-models.json';
        if (is_file($cacheFile) && time() - (int) filemtime($cacheFile) < 21600) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $command = trim((string) config('cmf-area.upgrade.agent_command', 'pi'));
        if ($command === '' || (! str_contains($command, '/') && ! $this->commandResolvable($command))) {
            return [];
        }

        $process = new Process([$command, '--list-models'], null, $this->processEnv(), null, 30);
        try {
            $process->run();
        } catch (\Throwable) {
            return [];
        }
        if (! $process->isSuccessful()) {
            return [];
        }

        $models = $this->parseModelList($process->getOutput());
        if ($models !== []) {
            file_put_contents($cacheFile, json_encode($models, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return $models;
    }

    /**
     * 提取判读日志的展示内容（页面输出面板用，返回 HTML，调用方直接输出）：
     *  - AI 正式回复（text_delta 流式拼接）原文展示
     *  - AI 思考过程（thinking_delta 拼接）渲染为淡化段落（单段截断防刷屏）
     *  - 工具调用/结果（tool_execution_start/end）转成「执行命令/读取文件/完成/失败」可读行
     *  - 非 JSON 行（runner 系统行 [判读]/[校验]/[错误]、纯文本输出）原样保留
     *  - __AGENT_RUN_*__ 协议行与其余协议事件（message_start/turn_start/toolcall_delta 等）不展示
     */
    public function extractDisplayText(string $log): string
    {
        $out = [];
        $thinking = '';
        $text = '';
        $flush = function () use (&$out, &$thinking, &$text): void {
            if (trim($thinking) !== '') {
                $out[] = '<span class="au-log-think">'.e(Str::limit(trim($thinking), 1500)).'</span>';
            }
            if (trim($text) !== '') {
                $out[] = '<span class="au-log-text">'.e(trim($text)).'</span>';
            }
            $thinking = '';
            $text = '';
        };

        foreach (preg_split('/\r?\n/', $log) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '__AGENT_RUN_')) {
                continue;
            }
            $event = json_decode($line, true);
            if (! is_array($event)) {
                $flush();
                $out[] = '<span class="au-log-sys">'.e($line).'</span>';

                continue;
            }
            $ame = $event['assistantMessageEvent'] ?? null;
            if (is_array($ame) && ($ame['type'] ?? null) === 'text_delta' && isset($ame['delta'])) {
                $text .= (string) $ame['delta'];

                continue;
            }
            if (is_array($ame) && ($ame['type'] ?? null) === 'thinking_delta' && isset($ame['delta'])) {
                $thinking .= (string) $ame['delta'];

                continue;
            }
            $type = (string) ($event['type'] ?? '');
            if ($type === 'tool_execution_start') {
                $flush();
                $out[] = '<span class="au-log-tool">▶ '.e($this->describeToolCall(
                    (string) ($event['toolName'] ?? ''),
                    is_array($event['args'] ?? null) ? $event['args'] : []
                )).'</span>';
            } elseif ($type === 'tool_execution_end') {
                $flush();
                $isError = (bool) ($event['isError'] ?? false);
                $summary = $isError ? '：'.$this->summarizeToolResult($event['result'] ?? null) : '';
                $out[] = '<span class="'.($isError ? 'au-log-err' : 'au-log-ok').'">'
                    .($isError ? '✗ 执行失败' : '✓ 执行完成').e($summary).'</span>';
            }
        }
        $flush();

        return implode("\n", $out);
    }

    /**
     * 把一次工具调用描述成一行人话（命令/路径截断防刷屏）。
     *
     * @param  array<string, mixed>  $args
     */
    protected function describeToolCall(string $toolName, array $args): string
    {
        $arg = fn (string $key): string => is_string($args[$key] ?? null) ? $args[$key] : '';

        return match ($toolName) {
            'bash' => '执行命令：'.Str::limit((string) preg_replace('/\s+/', ' ', $arg('command')), 300),
            'read' => '读取文件：'.$arg('path'),
            'write' => '写入文件：'.$arg('path'),
            'edit' => '编辑文件：'.$arg('path'),
            'ls' => '列出目录：'.($arg('path') !== '' ? $arg('path') : '.'),
            'glob' => '查找文件：'.($arg('pattern') !== '' ? $arg('pattern') : $arg('path')),
            'grep' => '搜索内容：'.Str::limit($arg('pattern'), 80),
            default => '调用工具 '.$toolName.($args !== [] ? '：'.Str::limit((string) json_encode($args, JSON_UNESCAPED_UNICODE), 200) : ''),
        };
    }

    /**
     * 工具失败结果摘要（取首段文本，压空白并截断）。
     */
    protected function summarizeToolResult(mixed $result): string
    {
        $content = is_array($result) ? ($result['content'] ?? null) : null;
        if (is_array($content)) {
            foreach ($content as $block) {
                if (is_array($block) && is_string($block['text'] ?? null) && trim($block['text']) !== '') {
                    return Str::limit((string) preg_replace('/\s+/', ' ', trim($block['text'])), 200);
                }
            }
        }

        return is_string($result) ? Str::limit($result, 200) : '';
    }

    /**
     * 解析 pi --list-models 的表格输出（跳过表头，取 provider/model/context 列）。
     *
     * @return array<string, string>
     */
    public function parseModelList(string $output): array
    {
        $models = [];
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'provider')) {
                continue;
            }
            $cols = preg_split('/\s+/', $line) ?: [];
            if (count($cols) < 2) {
                continue;
            }
            $value = $cols[0].'/'.$cols[1];
            $context = $cols[2] ?? '';
            $models[$value] = $context !== '' ? "{$value}（{$context}）" : $value;
        }

        return $models;
    }

    /**
     * agent 是否可用（配置了命令；不探测二进制是否存在，失败在 run 时暴露）。
     */
    public function available(): bool
    {
        return trim((string) config('cmf-area.upgrade.agent_command', 'pi')) !== '';
    }

    /**
     * 后台拉起 AI 判读（不走队列/worker）：nohup 起独立 artisan 进程跑
     * area:collect --run-agent，stdout/stderr 重定向到工作区日志文件，
     * 页面轮询日志展示进度。判读状态写入 state['agent_run']。
     *
     * @param  array<string, mixed>  $state
     * @param  list<int>  $regionIds  本次采集的地区（空 = 全部未通过地区）
     * @return array<string, mixed> 新状态
     */
    public function startBackground(array $state, array $regionIds = []): array
    {
        $run = is_array($state['agent_run'] ?? null) ? $state['agent_run'] : null;
        if ($run !== null) {
            $log = (string) ($run['log'] ?? '');
            if ($this->agentLocked($log) || $this->agentProcessAlive($log)) {
                throw new RuntimeException('AI 判读正在进行中，请勿重复发起');
            }
        }

        $logFile = $this->workspace->dir().'/agent-run.log';
        file_put_contents($logFile, '');

        $this->launchBackground($logFile, $regionIds);

        $state['agent_run'] = [
            'started_at' => date('c'),
            'log' => $logFile,
            'regions' => array_values($regionIds),
        ];

        return $this->workspace->saveState($state);
    }

    /**
     * 读日志文件内容（页面轮询 + 重入检测用）。默认全量读——判读输出
     * 要求原样呈现，不做截断；特大文件可传 $bytes 只读尾部。
     */
    public function logTail(string $file, int $bytes = 0): string
    {
        if (! is_file($file)) {
            return '';
        }
        if ($bytes > 0) {
            $size = filesize($file);
            if ($size === false || $size === 0) {
                return '';
            }
            $fh = fopen($file, 'r');
            if ($fh === false) {
                return '';
            }
            fseek($fh, max(0, $size - $bytes));
            $data = (string) stream_get_contents($fh);
            fclose($fh);

            return $data;
        }
        $data = @file_get_contents($file);

        return $data === false ? '' : $data;
    }

    /**
     * 判读后台进程是否仍在运行：pid 文件（launcher 落盘）指向的进程存活，
     * 且日志尚未出现 DONE 标记。进程意外死亡（kill/崩溃/OOM）时视为未运行，
     * 允许重新发起，不会被残留的"进行中"状态永久卡死。
     */
    public function agentProcessAlive(string $logFile): bool
    {
        if ($logFile === '' || ! is_file($logFile)) {
            return false;
        }
        $pidFile = $logFile.'.pid';
        if (! is_file($pidFile)) {
            return false;
        }
        $pid = (int) trim((string) file_get_contents($pidFile));

        return $pid > 0
            && is_dir('/proc/'.$pid)
            && ! str_contains($this->logTail($logFile), '__AGENT_RUN_DONE__');
    }

    /**
     * 判读锁是否被持有（launcher 的 flock，内核级、随进程生命周期）：
     * 并发点击/多入口触发判读时的可靠互斥判定（pid 文件有时序与复用缺陷）。
     */
    public function agentLocked(string $logFile): bool
    {
        if ($logFile === '') {
            return false;
        }
        $fh = @fopen($logFile.'.lock', 'c');
        if ($fh === false) {
            return false;
        }
        $locked = ! flock($fh, LOCK_EX | LOCK_NB);
        if (! $locked) {
            flock($fh, LOCK_UN);
        }
        fclose($fh);

        return $locked;
    }

    /**
     * 实际拉起后台进程（独立成方法便于测试替换）。
     *
     * @param  list<int>  $regionIds
     */
    protected function launchBackground(string $logFile, array $regionIds): void
    {
        // 经 bin/launch-background.sh 拉起：脚本先关闭从 PHP 父进程继承的
        // 多余 fd（管道写端），再 setsid 脱离会话——否则本方法（请求内同步等
        // sh 退出 + 管道 EOF）会被后台孙进程持有的泄漏 fd 永久阻塞
        $launcher = dirname(__DIR__, 2).'/bin/launch-background.sh';
        $argv = array_merge(
            [PHP_BINARY, '-d', 'display_startup_errors=0', base_path('artisan'), 'area:collect', '--run-agent'],
            array_map(fn (int $id): string => '--region='. $id, $regionIds),
        );
        $cmd = implode(' ', array_map('escapeshellarg', [$launcher, $logFile, ...$argv])).' &';
        // 显式传完整进程环境：web 请求内 Symfony 默认 env 会拿 getenv() 与
        // $_SERVER（请求变量）求交集，PATH / CMF_AREA_UPGRADE_* 等全部丢失，
        // 后台 artisan 将在残缺环境中运行（pi 及其工具调用需要 PATH）
        $env = getenv();
        Process::fromShellCommandline($cmd, base_path(), is_array($env) && $env !== [] ? $env : null)->run();
    }

    /**
     * 拉起 pi 完成任务包目录内的判读任务。
     *
     * pi 用 print 模式一次性会话：--no-session 不留会话、-p 输出完即退出；
     * cwd 即任务包目录，COLLECT_TASK.md / diff.json / old.csv / new.csv / scope.json
     * 都是相对路径可读；产物约定写回同目录 changes_fragment.json。
     *
     * @param  list<string>  $previousErrors  上一轮机器校验错误（重试时拼进 prompt 喂回）
     * @param  callable|null  $onOutput  输出回调（pi 的 stdout/stderr 实时透传）
     * @param  string|null  $agentModel  工作区选中的判读模型（优先于 config agent_model）
     * @param  string|null  $storedDigest  已入库且不可替换记录摘要（撞键预警，见 storedItemsDigest）
     * @return string|null 进程级错误消息（null = 进程正常或产物已写出）
     */
    protected function runPi(string $dir, array $previousErrors = [], ?callable $onOutput = null, ?string $agentModel = null, ?string $storedDigest = null): ?string
    {
        // 启动即校验命令可用性：相对命令名在 web 请求内 PATH 解析不稳定
        // （内置 server/PHP-FPM 环境变量随请求生命周期变化），直接拒绝并
        // 引导配绝对路径，避免白跑三轮重试
        $command = trim((string) config('cmf-area.upgrade.agent_command', 'pi'));
        if ($command === '') {
            throw new RuntimeException('未配置 cmf-area.upgrade.agent_command');
        }
        if (! str_contains($command, '/') && ! $this->commandResolvable($command)) {
            throw new RuntimeException(
                "agent 命令「{$command}」在当前进程 PATH 中找不到。"
                .'请把 cmf-area.upgrade.agent_command 配为 agent 可执行文件的绝对路径（env CMF_AREA_UPGRADE_AGENT_COMMAND）'
            );
        }

        $prompt = <<<'PROMPT'
阅读 ./COLLECT_TASK.md 完成区划升级判读任务。
- 判读数据：./diff.json（diff 事实清单）、./old.csv（旧基线）、./new.csv（新版）、./scope.json（本次选中地区与人类反馈）；
- 产出：把 changes 片段写入 ./changes_fragment.json（只含本次选中地区的 node/edge 与新增证据池条目）；
- 写完文件即结束，不要等待确认、不要执行 COLLECT_TASK.md 里的 artisan 回收命令（由系统统一回收校验）。
- 你的 fragment 回收时与已入库待审记录合并整图校验：本次地区内 AI 未审定的旧记录会被你的新产物**整体替换**（同键重写是安全的）；人工录入/已审定/其他地区的记录不会被替换，撞键即冲突报错。
PROMPT;
        if ($storedDigest !== null && $storedDigest !== '') {
            $prompt .= "\n\n".$storedDigest;
        }
        if ($previousErrors !== []) {
            $prompt .= "\n\n上一轮你的产物机器校验未通过，错误清单如下，请逐条修正后重新产出：\n- "
                .implode("\n- ", array_slice($previousErrors, 0, 50));
        }

        /** @var list<string> $args */
        $args = config('cmf-area.upgrade.agent_args', ['-p', '--no-session']);
        $timeout = (int) config('cmf-area.upgrade.agent_timeout', 1800);

        // 模型指定（pi --model，支持 provider/id 与 :thinking 档）：
        // 工作区选中值（界面下拉）优先，其次 config/env
        $model = $agentModel ?? config('cmf-area.upgrade.agent_model');
        if (is_string($model) && trim($model) !== '') {
            $args = [...$args, '--model', trim($model)];
        }
        // 判读 skill 挂载（pi --skill）：默认挂 area/skill 判读 SOP，
        // agent cwd 是任务包目录（看不到仓库文件），SOP 须显式喂给
        /** @var list<string> $skills */
        $skills = config('cmf-area.upgrade.agent_skills', []);
        foreach ($skills as $skill) {
            if (is_string($skill) && $skill !== '' && is_dir($skill)) {
                $args = [...$args, '--skill', $skill];
            }
        }

        // 显式传进程级环境：web 请求内 Symfony 默认 env 会拿 getenv() 与
        // $_SERVER（请求变量）求交集导致 PATH 丢失。
        // setsid 让 agent 自成进程组：超时时 Symfony 只杀直接子进程，
        // agent 的工具子进程（bash/curl 等）会成孤儿残留——按组补杀（见 catch）。
        $process = new Process(
            ['setsid', $command, ...$args, $prompt],
            $dir,
            $this->processEnv(),
            null,
            $timeout > 0 ? $timeout : null,
        );
        try {
            $process->start();
            $pid = $process->getPid(); // 进程存活期间取（停止后 getPid 返回 null）
            $process->wait($onOutput);
        } catch (ProcessTimedOutException) {
            $this->killProcessGroup($pid ?? null);

            return "pi 判读超时（{$timeout}s）已终止（含残留工具子进程）";
        } catch (\Throwable $e) {
            return 'pi 判读进程启动失败：'.$e->getMessage();
        }

        // 进程失败不直接判死：产物可能已写出（以 fragment 是否存在为准，见 run()）
        if (! $process->isSuccessful() && ! is_file($dir.'/changes_fragment.json')) {
            $output = trim($process->getErrorOutput()) !== '' ? trim($process->getErrorOutput()) : trim($process->getOutput());

            $message = 'pi 判读进程异常退出（exit '.$process->getExitCode().'）：'.mb_substr($output, -1000);
            if ($process->getExitCode() === 127) {
                $message .= '（agent 命令未找到：请把 cmf-area.upgrade.agent_command 配为 agent 可执行文件的绝对路径）';
            }

            return $message;
        }

        return null;
    }

    /**
     * 按进程组灭杀 agent 整棵树（负 pid = 整个进程组）。
     * agent 经 setsid 拉起后自身即组长，组内含其 fork 的工具子进程。
     */
    protected function killProcessGroup(?int $pid): void
    {
        if ($pid === null || $pid <= 0 || ! function_exists('posix_kill')) {
            return;
        }
        @posix_kill(-$pid, 9);
    }

    /**
     * 手动终止进行中的后台判读：灭杀整棵进程树（artisan 与其 setsid 子进程分属
     * 两个进程组，先灭子组再灭父组；launcher 的 flock 随进程死亡由内核释放），
     * 采集中的地区标记 terminated（可重新发起判读），已落库待审核记录不动。
     *
     * @param  array<string, mixed>  $state
     * @param  string  $logFile  agent_run.log 路径（pid 文件为其 .pid 后缀）
     * @return array<string, mixed> 新状态
     */
    public function terminate(array $state, string $logFile): array
    {
        $pidFile = $logFile !== '' ? $logFile.'.pid' : '';
        $pid = $pidFile !== '' && is_file($pidFile) ? (int) trim((string) file_get_contents($pidFile)) : 0;

        if ($pid > 0 && is_dir('/proc/'.$pid) && function_exists('posix_kill')) {
            // pi 由 artisan 以 setsid 拉起、自成组长，不在 artisan 组内：按子进程逐个灭组
            foreach ($this->childPidsOf($pid) as $childPid) {
                @posix_kill(-$childPid, 9);
            }
            // artisan 经 launch-background.sh setsid，自身即组长：灭其组（含未 setsid 的成员）
            @posix_kill(-$pid, 9);
            @posix_kill($pid, 9); // 兜底：环境不支持组杀时直杀
        }

        if ($logFile !== '' && is_file($logFile)) {
            file_put_contents($logFile, "[终止] 用户手动终止本次判读。\n", FILE_APPEND);
        }

        $collectState = is_array($state['collection_state'] ?? null) ? $state['collection_state'] : [];
        foreach ($collectState as $key => $entry) {
            if (($entry['status'] ?? null) === 'collecting') {
                $collectState[$key] = [
                    'status' => 'terminated',
                    'retries' => (int) ($entry['retries'] ?? 0),
                    'last_errors' => [],
                    'feedback' => $entry['feedback'] ?? null,
                ];
            }
        }

        return $this->workspace->saveState(['collection_state' => $collectState]);
    }

    /**
     * 某进程的直接子进程 pid 清单（/proc 扫描，Linux；读不到返回空数组）。
     *
     * @return list<int>
     */
    protected function childPidsOf(int $pid): array
    {
        $pids = [];
        foreach (glob('/proc/[0-9]*/stat') ?: [] as $statFile) {
            $stat = @file_get_contents($statFile);
            if ($stat === false) {
                continue;
            }
            // stat 格式 "pid (comm) state ppid ..."，comm 可含空格与括号：取最后一个 ) 之后
            $close = strrpos($stat, ')');
            if ($close === false) {
                continue;
            }
            $rest = preg_split('/\s+/', substr($stat, $close + 2)) ?: [];
            if ((int) ($rest[1] ?? 0) === $pid) {
                $pids[] = (int) basename(dirname($statFile));
            }
        }

        return $pids;
    }

    /**
     * 采集中的地区 id 清单。
     *
     * @param  array<string, mixed>  $state
     * @return list<int>
     */
    protected function collectingRegionIds(array $state): array
    {
        $collectState = is_array($state['collection_state'] ?? null) ? $state['collection_state'] : [];

        return array_values(array_filter(array_map(
            fn (string $key): ?int => ($collectState[$key]['status'] ?? null) === 'collecting' ? (int) $key : null,
            array_keys($collectState),
        )));
    }

    /**
     * 进程级错误写回采集状态（ingest 路径之外的失败：未产出 / 进程异常）。
     *
     * @param  array<string, mixed>  $state
     * @param  list<int>  $regionIds
     * @param  list<string>  $errors
     * @return array<string, mixed>
     */
    protected function writeBackErrors(array $state, array $regionIds, array $errors, int $attempt, int $maxRetries): array
    {
        $collectState = is_array($state['collection_state'] ?? null) ? $state['collection_state'] : [];
        foreach ($regionIds as $id) {
            $key = (string) $id;
            $existing = $collectState[$key] ?? [];
            $collectState[$key] = [
                'status' => $attempt >= $maxRetries ? 'failed' : 'collecting',
                'retries' => $attempt,
                'last_errors' => $errors,
                'feedback' => $existing['feedback'] ?? null,
            ];
        }

        return $this->workspace->saveState(['collection_state' => $collectState]);
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<int>  $regionIds
     */
    protected function anyFailed(array $state, array $regionIds): bool
    {
        $collectState = is_array($state['collection_state'] ?? null) ? $state['collection_state'] : [];
        foreach ($regionIds as $id) {
            if (($collectState[(string) $id]['status'] ?? null) === 'failed') {
                return true;
            }
        }

        return false;
    }
}
