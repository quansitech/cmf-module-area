<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Services;

use RuntimeException;

/**
 * 基线补丁（升级方案 §7）：把审定 changes 对应的结构操作打进基线 csv。
 *
 * 与 MigrationGenerator 构造 areas ops 同源——直接消费其 payload 的 areas 段，
 * 保证补丁后基线与迁移文件对同一份 changes 的行为一致：
 *  - insert（added）      → 加行（字段即 insert op 冻结的新版 csv 值）；
 *  - retire（removed）    → 删行（新安装本就不含历史废止行，与现状一致）；
 *  - rename / reparent    → 改对应字段；
 *  - archive（废止复用）  → 删旧行、不加 90{id} 合成行（90 段仅服务老项目历史回显）。
 * 未触及的行一动不动。
 *
 * 基线只能由本服务机器生成，禁止手改 csv（升级方案 §5 规矩 1）。
 */
class BaselinePatcher
{
    public function __construct(
        protected readonly MigrationGenerator $generator,
        protected readonly ImportService $import,
    ) {}

    /**
     * 旧基线 map + 审定 changes.json → 补丁后基线 map。
     *
     * @param  array<int, array<string, mixed>>  $oldMap
     * @param  array<string, mixed>  $changes  已通过校验的 changes.json（v3）
     * @param  array<int, array<string, mixed>>  $newMap  上游新版 csv（insert 字段来源）
     * @return array<int, array<string, mixed>>
     */
    public function patch(array $oldMap, array $changes, array $newMap): array
    {
        $payload = $this->generator->payload($changes, $oldMap, $newMap);

        return $this->applyPayload($oldMap, $payload);
    }

    /**
     * 把迁移 payload 的 areas 结构操作应用到基线 map。
     *
     * @param  array<int, array<string, mixed>>  $oldMap
     * @param  array<string, mixed>  $payload  MigrationGenerator::payload() 产物
     * @return array<int, array<string, mixed>>
     */
    public function applyPayload(array $oldMap, array $payload): array
    {
        $baseline = $oldMap;

        foreach ($payload['areas'] ?? [] as $op) {
            if (! is_array($op) || ! isset($op['op'], $op['id'])) {
                continue;
            }
            $id = (int) $op['id'];

            switch ($op['op']) {
                case 'insert':
                    $baseline[$id] = [
                        'id' => $id,
                        'pid' => (int) $op['pid'],
                        'deep' => (int) $op['deep'],
                        'name' => (string) $op['name'],
                        'pinyin_prefix' => (string) $op['pinyin_prefix'],
                        'pinyin' => (string) $op['pinyin'],
                        'ext_id' => (int) $op['ext_id'],
                        'ext_name' => (string) $op['ext_name'],
                    ];
                    break;

                case 'retire':
                case 'archive':
                    // archive：删旧行、不加 90{id} 合成行（升级方案 §7）
                    unset($baseline[$id]);
                    break;

                case 'rename':
                    if (! isset($baseline[$id])) {
                        throw new RuntimeException("rename 操作的 id {$id} 不在旧基线内");
                    }
                    foreach (['name', 'ext_name', 'pinyin_prefix', 'pinyin'] as $field) {
                        if (isset($op[$field])) {
                            $baseline[$id][$field] = (string) $op[$field];
                        }
                    }
                    break;

                case 'reparent':
                    if (! isset($baseline[$id])) {
                        throw new RuntimeException("reparent 操作的 id {$id} 不在旧基线内");
                    }
                    $baseline[$id]['pid'] = (int) $op['pid'];
                    break;

                default:
                    throw new RuntimeException('未知基线补丁操作：'.var_export($op['op'], true));
            }
        }

        ksort($baseline);

        return $baseline;
    }

    /**
     * 基线 map 落盘为上游 csv 格式（UTF-8 带 BOM、双引号限定符、id 升序）。
     *
     * @param  array<int, array<string, mixed>>  $map
     */
    public function writeCsv(array $map, string $path): void
    {
        $fh = fopen($path, 'w');
        if ($fh === false) {
            throw new RuntimeException("无法写入 csv：{$path}");
        }

        try {
            fwrite($fh, "\xEF\xBB\xBF");
            fputcsv($fh, ['id', 'pid', 'deep', 'name', 'pinyin_prefix', 'pinyin', 'ext_id', 'ext_name']);
            ksort($map);
            foreach ($map as $row) {
                fputcsv($fh, [
                    $row['id'], $row['pid'], $row['deep'], $row['name'],
                    $row['pinyin_prefix'], $row['pinyin'], $row['ext_id'], $row['ext_name'],
                ]);
            }
        } finally {
            fclose($fh);
        }
    }
}
