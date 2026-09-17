<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Console\Commands;

use Illuminate\Console\Command;
use Quansitech\Cmf\Area\Services\ReferenceCollector;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * area:sync-references — 部署时运行：以已注册 ServiceProvider 为锚点
 * 自动发现扫描范围，收集全部模型的 areaReferences() 声明，幂等落库。
 */
#[AsCommand(name: 'area:sync-references', description: '扫描收集模型地区引用声明并落库')]
class SyncReferencesCommand extends Command
{
    protected $signature = 'area:sync-references';

    public function handle(ReferenceCollector $collector): int
    {
        try {
            $result = $collector->sync();
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($result['packages'] as $package => $count) {
            $this->components->twoColumnDetail($package, "{$count} 条声明");
        }

        $this->components->info("引用登记已同步：共 {$result['synced']} 条（幂等 upsert）。");

        return self::SUCCESS;
    }
}
