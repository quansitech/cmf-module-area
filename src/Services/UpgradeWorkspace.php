<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Services;

use RuntimeException;

/**
 * 升级工作区（文件态，升级方案 §2 调整：升级全流程一次完成，不持久化批次）。
 *
 * 一次"基线 vs 上游某版"的升级 = 一个工作目录（默认 storage/app/cmf-area/upgrade/）：
 *  - workspace.json：工作区状态（版本、状态机、选中地区、证据池、采集状态、
 *    覆盖率快照、定稿结果）；
 *  - items.json：判读记录数组（一条 = 一条 v3 node/edge + 审核状态）；
 *  - diff.json / collect/：diff 产物与采集任务包（本来就在文件系统上）。
 *
 * 定稿后工作区即完成使命（产物已落包内），可整体归档或清空；
 * 未处理地区下次发起升级时由 diff 自然重现，无需保留批次。
 */
class UpgradeWorkspace
{
    /** 状态机：draft → diff_ready → collecting → reviewing → generated / failed */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_DIFF_READY = 'diff_ready';

    public const STATUS_COLLECTING = 'collecting';

    public const STATUS_REVIEWING = 'reviewing';

    public const STATUS_GENERATED = 'generated';

    public const STATUS_FAILED = 'failed';

    /** 判读记录审核状态 */
    public const REVIEW_PENDING = 'pending';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_REJECTED = 'rejected';

    public const REVIEW_EDITED = 'edited';

    public const REVIEW_MANUAL = 'manual';

    /** 定稿时计入合并的审核状态 */
    public const REVIEW_FINALIZED_STATUSES = [
        self::REVIEW_APPROVED,
        self::REVIEW_EDITED,
        self::REVIEW_MANUAL,
    ];

    public const SOURCE_AI = 'ai';

    public const SOURCE_MANUAL = 'manual';

    /**
     * 工作区目录。
     */
    public function dir(): string
    {
        $dir = config('cmf-area.upgrade.work_dir') ?: storage_path('app/cmf-area/upgrade');
        if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
            throw new RuntimeException("无法创建工作区目录：{$dir}");
        }

        return $dir;
    }

    /**
     * 工作区是否已初始化（已发起升级）。
     */
    public function exists(): bool
    {
        return is_file($this->dir().'/workspace.json');
    }

    /**
     * 读取工作区状态。
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        $path = $this->dir().'/workspace.json';
        if (! is_file($path)) {
            throw new RuntimeException('升级工作区不存在（先在"概览"发起升级）');
        }

        $state = json_decode((string) file_get_contents($path), true);
        if (! is_array($state)) {
            throw new RuntimeException("workspace.json 不是合法 JSON：{$path}");
        }

        return $state + [
            'assigned_version' => null,
            'selected_regions' => [],
            'evidence_pool' => [],
            'collection_state' => [],
            'coverage_snapshot' => null,
            'finalize_result' => null,
        ];
    }

    /**
     * 写入/更新工作区状态（合并写）。
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed> 写入后的完整状态
     */
    public function saveState(array $state): array
    {
        $current = $this->exists() ? $this->state() : [];
        $merged = [...$current, ...$state];

        $path = $this->dir().'/workspace.json';
        if (file_put_contents($path, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n") === false) {
            throw new RuntimeException("无法写入：{$path}");
        }

        return $merged;
    }

    /**
     * 重置工作区（发起新升级前清空旧状态与判读记录）。
     */
    public function reset(): void
    {
        foreach (['workspace.json', 'items.json'] as $file) {
            $path = $this->dir().'/'.$file;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * 全部判读记录。
     *
     * @return list<array<string, mixed>>
     */
    public function items(): array
    {
        $path = $this->dir().'/items.json';
        if (! is_file($path)) {
            return [];
        }

        $items = json_decode((string) file_get_contents($path), true);

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findItem(int $id): ?array
    {
        foreach ($this->items() as $item) {
            if ((int) ($item['id'] ?? 0) === $id) {
                return $item;
            }
        }

        return null;
    }

    /**
     * 新增判读记录（id 自增）。
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed> 落库后的记录
     */
    public function addItem(array $item): array
    {
        $items = $this->items();
        $item['id'] = collect($items)->max('id') !== null ? (int) collect($items)->max('id') + 1 : 1;
        $item += [
            'review_status' => self::REVIEW_PENDING,
            'source' => self::SOURCE_AI,
            'confidence' => null,
            'review_note' => null,
            'region' => null,
            'change_type' => null,
            'side' => null,
        ];
        $items[] = $item;
        $this->saveItems($items);

        return $item;
    }

    /**
     * 更新一条判读记录。
     *
     * @param  array<string, mixed>  $attrs
     */
    public function updateItem(int $id, array $attrs): void
    {
        $items = $this->items();
        foreach ($items as $i => $item) {
            if ((int) ($item['id'] ?? 0) === $id) {
                $items[$i] = [...$item, ...$attrs];
                $this->saveItems($items);

                return;
            }
        }

        throw new RuntimeException("判读记录不存在：{$id}");
    }

    /**
     * 按条件删除判读记录（采集重做时替换该地区未审定记录）。
     */
    public function deleteItemsWhere(callable $predicate): void
    {
        $this->saveItems(array_values(array_filter($this->items(), fn (array $item): bool => ! $predicate($item))));
    }

    /**
     * 全量覆写判读记录。
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function saveItems(array $items): void
    {
        $path = $this->dir().'/items.json';
        if (file_put_contents($path, json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n") === false) {
            throw new RuntimeException("无法写入：{$path}");
        }
    }
}
