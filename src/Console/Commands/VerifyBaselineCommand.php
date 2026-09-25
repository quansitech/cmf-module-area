<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Console\Commands;

use Illuminate\Console\Command;
use Quansitech\Cmf\Area\Services\BaselineReplay;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * area:verify-baseline — 基线重放校验（升级方案 §5 规矩 2）：
 * 初始基线依次应用所有留档 changes，必须等于当前基线 csv。
 * 发版 PR 跑一次，锁死"基线只由 patch-baseline 机器生成"的规矩。
 */
#[AsCommand(name: 'area:verify-baseline', description: '重放留档 changes 校验基线 csv 未被手改')]
class VerifyBaselineCommand extends Command
{
    protected $signature = 'area:verify-baseline
        {--data-dir= : 数据目录（默认模块 database/data）}';

    public function handle(BaselineReplay $replay): int
    {
        /** @var string|null $dataDir */
        $dataDir = $this->option('data-dir');

        try {
            $errors = $replay->verify($dataDir);
        } catch (\Throwable $e) {
            $this->components->error("重放校验失败：{$e->getMessage()}");

            return self::FAILURE;
        }

        if ($errors === []) {
            $this->components->info('重放校验通过：初始基线 + 历次留档 changes = 当前基线 csv。');

            return self::SUCCESS;
        }

        $this->components->error(sprintf('重放校验未通过（%d 条错误）：', count($errors)));
        $this->components->bulletList($errors);

        return self::FAILURE;
    }
}
