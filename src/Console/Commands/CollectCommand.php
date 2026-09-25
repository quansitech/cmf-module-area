<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Console\Commands;

use Illuminate\Console\Command;
use Quansitech\Cmf\Area\Services\UpgradeFlowService;
use Quansitech\Cmf\Area\Services\UpgradeWorkspace;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * area:collect — 升级采集驱动（升级方案 §11，B. 本地命令驱动）。
 *
 * 两种用法：
 *  - 无 --ingest：为选中地区组装采集任务包（diff + 新旧 csv + scope + prompt），
 *    维护者/AI agent 按包内 COLLECT_TASK.md 判读；
 *  - 带 --ingest={fragment}：回收 AI 产出的 changes 片段，机器 scoped 校验，
 *    全绿落工作区为待审核记录；不过则错误清单写回采集状态（重试上限见
 *    config cmf-area.upgrade.max_retries，超限转人工录入）。
 */
#[AsCommand(name: 'area:collect', description: '升级采集任务：组装任务包 / 回收判读产物')]
class CollectCommand extends Command
{
    protected $signature = 'area:collect
        {--ingest= : changes 片段路径（回收判读产物；不传则组装采集任务包）}
        {--run-agent : 直接执行 AI 判读全流程（组包→agent→回收），输出供页面轮询}
        {--region=* : 本次覆盖的地区 id（默认 = 全部未通过地区 / 采集中的地区）}';

    public function handle(UpgradeWorkspace $workspace, UpgradeFlowService $flow): int
    {
        if (! $workspace->exists()) {
            $this->components->error('升级工作区不存在（先在升级界面发起升级）');

            return self::FAILURE;
        }
        $state = $workspace->state();

        /** @var list<string> $regionOptions */
        $regionOptions = $this->option('region');
        $regionIds = array_values(array_map('intval', $regionOptions));

        if ($this->option('run-agent')) {
            return $this->runAgent($workspace, $state, $regionIds);
        }

        /** @var string|null $ingest */
        $ingest = $this->option('ingest');

        if ($ingest === null) {
            try {
                $dir = $flow->makeCollectPackage($state, $regionIds);
            } catch (\Throwable $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            $this->components->info("采集任务包已生成：{$dir}");
            $this->components->bulletList([
                "判读要求见 {$dir}/COLLECT_TASK.md（SOP 本体在 area/skill/SKILL.md）",
                "产出写为 {$dir}/changes_fragment.json 后回收：",
                "php artisan area:collect --ingest={$dir}/changes_fragment.json",
            ]);

            return self::SUCCESS;
        }

        if (! is_file($ingest)) {
            $this->components->error("changes 片段不存在：{$ingest}");

            return self::FAILURE;
        }

        [, $errors] = $flow->ingest($state, $ingest, $regionIds !== [] ? $regionIds : null);

        if ($errors === []) {
            $this->components->info('判读产物校验全绿，已落工作区为待审核记录。请到升级界面逐条定稿。');

            return self::SUCCESS;
        }

        $this->components->error(sprintf('判读产物校验未通过（%d 条错误，已写回采集状态喂回重试）：', count($errors)));
        $this->components->bulletList($errors);

        return self::FAILURE;
    }

    /**
     * --run-agent：AI 判读全流程（Web 后台进程模式）。输出走 stdout，
     * 由拉起方重定向到日志文件供页面轮询；__AGENT_RUN_START__/__AGENT_RUN_DONE__
     * 标记行用于页面判定进程起止。
     *
     * @param  array<string, mixed>  $state
     * @param  list<int>  $regionIds
     */
    protected function runAgent(UpgradeWorkspace $workspace, array $state, array $regionIds): int
    {
        $runner = app(\Quansitech\Cmf\Area\Services\CollectAgentRunner::class);
        if (! $runner->available()) {
            $this->components->error('未配置 AI agent（cmf-area.upgrade.agent_command）');

            return self::FAILURE;
        }

        $onOutput = function (string $type, string $buffer): void {
            $this->output->write($buffer);
        };

        $this->output->writeln('__AGENT_RUN_START__');
        try {
            [, $errors] = $runner->run($workspace->state(), $regionIds, $onOutput);
        } catch (\Throwable $e) {
            $this->output->writeln('[异常] '.$e->getMessage());
            $this->output->writeln('__AGENT_RUN_DONE__:exception');

            return self::FAILURE;
        }

        if ($errors === []) {
            $this->output->writeln('__AGENT_RUN_DONE__:ok');

            return self::SUCCESS;
        }

        $this->output->writeln(sprintf('__AGENT_RUN_DONE__:errors(%d)', count($errors)));

        return self::FAILURE;
    }
}
