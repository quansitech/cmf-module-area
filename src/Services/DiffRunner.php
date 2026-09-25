<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Services;

/**
 * diff 运行器：加载新旧 csv → DiffService 比对 → diff.json 结构。
 * DiffCommand 与升级批次（建批次/重新 diff）共用；命令只是薄壳。
 */
class DiffRunner
{
    public function __construct(
        protected readonly ImportService $import,
        protected readonly DiffService $diffService,
    ) {}

    /**
     * 执行 diff 并产出 diff.json 结构（不落盘）。
     *
     * @param  string|null  $oldCsv  旧基线 csv（null = 模块内置基线）
     * @param  string|null  $fromVersion  null = 当前 config data_version
     * @param  string|null  $toVersion  null = 从新版 csv 文件名推断
     * @return array{payload: array<string, mixed>, diff: array<string, mixed>, old_map: array<int, array<string, mixed>>, new_map: array<int, array<string, mixed>>}
     */
    public function run(string $newCsv, ?string $oldCsv = null, ?string $fromVersion = null, ?string $toVersion = null): array
    {
        $oldCsv ??= dirname(__DIR__, 2).'/database/data/ok_data_level4.csv';

        $oldMap = $this->import->loadCsvAsMap($oldCsv);
        $newMap = $this->import->loadCsvAsMap($newCsv);

        $diff = $this->diffService->diff($oldMap, $newMap, $this->retiredMapIfAvailable());

        $payload = $this->diffService->toJsonStructure(
            $diff,
            $oldMap,
            $newMap,
            $fromVersion ?? (string) config('cmf-area.data_version'),
            $toVersion ?? $this->guessVersion($newCsv),
        );

        return ['payload' => $payload, 'diff' => $diff, 'old_map' => $oldMap, 'new_map' => $newMap];
    }

    /**
     * 执行 diff 并落盘 diff.json。
     *
     * @return array<string, mixed> diff.json 结构
     */
    public function runToFile(string $newCsv, string $output, ?string $oldCsv = null, ?string $fromVersion = null, ?string $toVersion = null): array
    {
        $result = $this->run($newCsv, $oldCsv, $fromVersion, $toVersion);
        file_put_contents($output, json_encode($result['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $result['payload'];
    }

    /**
     * 数据库可用时取历史废止行；未建库/未连接时返回 null（跳过代码重用检测）。
     *
     * @return array<int, mixed>|null
     */
    protected function retiredMapIfAvailable(): ?array
    {
        try {
            return $this->diffService->retiredAreasFromDb();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function guessVersion(string $path): string
    {
        if (preg_match('/(\d{4}\.\d{6}\.\d{6})/', basename($path), $m)) {
            return $m[1];
        }

        return 'unknown';
    }
}
