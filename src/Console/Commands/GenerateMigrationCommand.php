<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Console\Commands;

use Illuminate\Console\Command;
use Quansitech\Cmf\Area\Services\ImportService;
use Quansitech\Cmf\Area\Services\MigrationGenerator;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * area:generate-migration — changes.json → 薄壳迁移文件（冻结 payload）。
 * 只含区划变更+映射数据，不写死业务表名（延迟绑定，见开发方案 §10.2）。
 */
#[AsCommand(name: 'area:generate-migration', description: 'changes.json 生成区划升级迁移文件')]
class GenerateMigrationCommand extends Command
{
    protected $signature = 'area:generate-migration
        {changes : changes.json 路径（v3 格式，须先通过 area:check-changes 校验）}
        {--old= : 旧基线 csv（默认模块内置 database/data/ok_data_level4.csv）}
        {--new= : 新版 csv（insert 操作的全字段取值来源）}
        {--output-dir= : 迁移输出目录（默认模块 database/migrations/updates）}';

    public function handle(MigrationGenerator $generator, ImportService $import): int
    {
        /** @var string $changesPath */
        $changesPath = $this->argument('changes');
        $changes = json_decode((string) file_get_contents($changesPath), true);

        if (! is_array($changes) || ! isset($changes['version'], $changes['changes'])) {
            $this->components->error('changes.json 结构不合法，请先通过 area:check-changes 校验');

            return self::FAILURE;
        }

        if ((int) ($changes['schema_version'] ?? 0) !== 3) {
            $this->components->error('changes.json 不是 v3 格式（schema_version=3，node + flat edge + 证据池），请按 area/skill/changes.schema.json 重写并通过 area:check-changes 校验');

            return self::FAILURE;
        }

        /** @var string $oldCsv */
        $oldCsv = $this->option('old') ?: dirname(__DIR__, 3).'/database/data/ok_data_level4.csv';
        /** @var string|null $newCsv */
        $newCsv = $this->option('new');

        if ($newCsv === null || ! is_file($newCsv)) {
            $this->components->error('必须用 --new 指定新版 csv（insert 操作需取全字段）');

            return self::FAILURE;
        }

        $payload = $generator->payload(
            $changes,
            $import->loadCsvAsMap($oldCsv),
            $import->loadCsvAsMap($newCsv),
        );

        /** @var string $outputDir */
        $outputDir = $this->option('output-dir') ?: dirname(__DIR__, 3).'/database/migrations/updates';
        if (! is_dir($outputDir) && ! mkdir($outputDir, 0755, true)) {
            $this->components->error("无法创建输出目录：{$outputDir}");

            return self::FAILURE;
        }

        $fileName = $generator->migrationFileName((string) $changes['version']);
        $path = $outputDir.'/'.$fileName;
        file_put_contents($path, $generator->renderMigration($payload));

        $this->components->info("迁移文件已生成：{$path}");
        $this->components->twoColumnDetail('结构操作 areas', (string) count($payload['areas']));
        $this->components->twoColumnDetail('映射指令 mappings', (string) count($payload['mappings']));
        $this->components->twoColumnDetail('待人工 manual', (string) count($payload['manual']));
        $this->components->twoColumnDetail('变更档案 records', (string) count($payload['records']));
        $this->components->bulletList([
            '下一步：更新 database/data/ok_data_level4.csv 为新基线、config/cmf-area.php 的 data_version，',
            '连同 changes.json、迁移文件一并提交 PR（人工闸门，见 SKILL.md §人工审查）。',
        ]);

        return self::SUCCESS;
    }
}
