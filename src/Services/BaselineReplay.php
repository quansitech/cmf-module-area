<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Services;

use RuntimeException;

/**
 * 基线重放校验（升级方案 §5 规矩 2）：「初始基线 + 历次留档 changes」
 * 依次应用补丁后必须等于当前基线 csv。
 *
 * 重放链载体：
 *  - database/data/baselines/baseline_{version}.csv：每次定稿落盘的基线快照
 *    （含定稿前的 from_version 快照——首个快照即初始基线）；
 *  - database/data/changes_{version}.json：每次定稿的 changes 留档
 *    （from_version 字段把链条串起来）。
 *
 * 每步重放用上一步快照为旧基线、本步快照为"新版 csv 字段来源"：
 * 补丁写盘 insert/rename/reparent 的字段值本就取自上游新版 csv，
 * 而本步快照里这些行的值与补丁写入值逐字节相同，故重放是精确等价的。
 */
class BaselineReplay
{
    public function __construct(
        protected readonly BaselinePatcher $patcher,
        protected readonly ImportService $import,
    ) {}

    /**
     * 重放校验。
     *
     * @param  string|null  $dataDir  null = 模块 database/data
     * @return list<string> 错误清单（空 = 通过）；无留档时返回空并视为通过
     */
    public function verify(?string $dataDir = null): array
    {
        $dataDir ??= config('cmf-area.upgrade.data_dir') ?: dirname(__DIR__, 2).'/database/data';
        $errors = [];

        $archives = $this->loadArchives($dataDir);
        if ($archives === []) {
            return []; // 无留档：未发过版，当前基线即初始基线
        }

        $chain = $this->chainOrder($archives);
        if ($chain === null) {
            return ['changes 留档的版本链不完整（from_version 无法串成单链），无法重放'];
        }

        $snapshotsDir = $dataDir.'/baselines';

        foreach ($chain as $changes) {
            $version = (string) $changes['version'];
            $fromVersion = (string) ($changes['from_version'] ?? '');
            $fromSnapshot = $snapshotsDir."/baseline_{$fromVersion}.csv";
            $toSnapshot = $snapshotsDir."/baseline_{$version}.csv";

            foreach ([$fromSnapshot, $toSnapshot] as $snapshot) {
                if (! is_file($snapshot)) {
                    $errors[] = "缺少基线快照：{$snapshot}（定稿流程会同时落盘 from/to 两个快照）";
                }
            }
            if ($errors !== []) {
                return $errors;
            }

            $oldMap = $this->import->loadCsvAsMap($fromSnapshot);
            $toMap = $this->import->loadCsvAsMap($toSnapshot);

            try {
                // 本步快照同时充当"上游新版 csv"的字段来源（见类注释）
                $replayed = $this->patcher->patch($oldMap, $changes, $toMap);
            } catch (\Throwable $e) {
                $errors[] = "重放 changes_{$version}.json 失败：{$e->getMessage()}";

                return $errors;
            }

            if ($replayed != $toMap) {
                $errors[] = "重放不一致：baseline_{$fromVersion}.csv + changes_{$version}.json ≠ baseline_{$version}.csv（留档与基线对不上）";

                return $errors;
            }
        }

        // 链尾快照必须等于当前基线 csv
        $last = end($chain);
        $lastVersion = (string) $last['version'];
        $currentCsv = $dataDir.'/ok_data_level4.csv';
        if (! is_file($currentCsv)) {
            $errors[] = "当前基线 csv 不存在：{$currentCsv}";

            return $errors;
        }
        $current = $this->import->loadCsvAsMap($currentCsv);
        $lastSnapshot = $this->import->loadCsvAsMap($snapshotsDir."/baseline_{$lastVersion}.csv");
        if ($current != $lastSnapshot) {
            $errors[] = "重放链尾（baseline_{$lastVersion}.csv）≠ 当前基线 ok_data_level4.csv：基线被绕开 patch-baseline 改动过";
        }

        return $errors;
    }

    /**
     * 加载 database/data/changes_*.json 留档。
     *
     * @return array<string, array<string, mixed>> version => changes
     */
    protected function loadArchives(string $dataDir): array
    {
        $archives = [];
        foreach (glob($dataDir.'/changes_*.json') ?: [] as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (! is_array($decoded) || ! isset($decoded['version'])) {
                throw new RuntimeException("changes 留档不是合法 JSON 或缺 version：{$file}");
            }
            $archives[(string) $decoded['version']] = $decoded;
        }

        return $archives;
    }

    /**
     * 把留档按 from_version → version 串成单链（根 = 不是任何留档 version 的 from_version）。
     *
     * @param  array<string, array<string, mixed>>  $archives
     * @return list<array<string, mixed>>|null 链序留档；链断裂/成环返回 null
     */
    protected function chainOrder(array $archives): ?array
    {
        $byFrom = [];
        foreach ($archives as $version => $changes) {
            $from = (string) ($changes['from_version'] ?? '');
            if ($from === '' || isset($byFrom[$from])) {
                return null;
            }
            $byFrom[$from] = $changes;
        }

        // 根：不是任何留档 version 的 from_version
        $roots = array_diff(array_keys($byFrom), array_keys($archives));
        if (count($roots) !== 1) {
            return null;
        }

        $chain = [];
        $cursor = reset($roots);
        $guard = 0;
        while (isset($byFrom[$cursor])) {
            if ($guard++ > count($byFrom)) {
                return null; // 成环
            }
            $changes = $byFrom[$cursor];
            $chain[] = $changes;
            $cursor = (string) $changes['version'];
        }

        return count($chain) === count($archives) ? $chain : null;
    }
}
