<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Console\Commands;

use Illuminate\Console\Command;
use Quansitech\Cmf\Area\Services\ChangesValidator;
use Quansitech\Cmf\Area\Services\ImportService;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * area:check-changes — AI 产出物 changes.json（v3：node + flat edge）的机器兜底校验
 * （纯确定性，不联网、不做语义判断）。校验逻辑在 ChangesValidator 服务
 * （与升级批次 Web 界面共用），本命令只是薄壳。
 * --scope 传入地区 id 后 I5 覆盖率收缩为 scoped 模式（只要求选中地区事实 100% 认领），
 * 与批次采集/定稿的校验口径一致。
 */
#[AsCommand(name: 'area:check-changes', description: '校验 changes.json（schema + 逻辑）')]
class CheckChangesCommand extends Command
{
    protected $signature = 'area:check-changes
        {changes : changes.json 路径（v3 格式：schema_version=3）}
        {--diff= : diff.json 路径（逻辑层覆盖率校验用）}
        {--old= : 旧基线 csv（默认模块内置 database/data/ok_data_level4.csv）}
        {--new= : 新版 csv（id 存在性/图级校验用）}
        {--scope= : 选中地区 id（逗号分隔）；给定后覆盖率收缩为 scoped 模式}';

    public function handle(ImportService $import, ChangesValidator $validator): int
    {
        /** @var string $changesPath */
        $changesPath = $this->argument('changes');
        $changes = json_decode((string) file_get_contents($changesPath), true);

        if (! is_array($changes)) {
            $this->components->error('changes.json 不是合法 JSON');

            return self::FAILURE;
        }

        /** @var string|null $diffPath */
        $diffPath = $this->option('diff');
        /** @var string $oldCsv */
        $oldCsv = $this->option('old') ?: dirname(__DIR__, 3).'/database/data/ok_data_level4.csv';
        /** @var string|null $newCsv */
        $newCsv = $this->option('new');

        $diff = null;
        if ($diffPath !== null && is_file($diffPath)) {
            $decoded = json_decode((string) file_get_contents($diffPath), true);
            $diff = is_array($decoded) ? $decoded : null;
        }

        $oldMap = is_file($oldCsv) ? $import->loadCsvAsMap($oldCsv) : [];
        $newMap = $newCsv !== null && is_file($newCsv) ? $import->loadCsvAsMap($newCsv) : [];

        $scope = null;
        /** @var string|null $scopeOption */
        $scopeOption = $this->option('scope');
        if (is_string($scopeOption) && trim($scopeOption) !== '') {
            $scope = array_values(array_map('intval', array_filter(explode(',', $scopeOption))));
        }

        $errors = $validator->validate($changes, $diff, $oldMap, $newMap, $scope);

        if ($errors === []) {
            $this->components->info('校验通过：schema 与逻辑校验全部绿灯。'.($scope !== null ? '（scoped 模式：覆盖率仅要求选中地区）' : ''));

            return self::SUCCESS;
        }

        $this->components->error(sprintf('校验未通过（%d 条错误）：', count($errors)));
        $this->components->bulletList($errors);

        return self::FAILURE;
    }
}
