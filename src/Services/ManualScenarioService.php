<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Services;

use RuntimeException;

/**
 * 人工录入场景向导（docs/area-upgrade-manual-entry-wizard.md §3）：
 * 业务人员以"一件事"描述变化，本服务负责 场景 → v3 payload 的组装；
 * node/edge/retired/appeared 等数据模型词汇不出现在向导 UI（高级模式除外）。
 *
 * 侧向由场景固定：merge 原单位只选旧版、现单位只选新版；appear 只选新版；
 * retire 只选旧版；rename/reuse 选两版均在的单位，侧由校验器派生。
 */
class ManualScenarioService
{
    public const SCENARIO_MERGE = 'merge';

    public const SCENARIO_APPEAR = 'appear';

    public const SCENARIO_RETIRE = 'retire';

    public const SCENARIO_RENAME = 'rename';

    public const SCENARIO_REUSE = 'reuse';

    public const SCENARIO_COMPLEX = 'complex';

    /** 业务场景（非高级模式） */
    public const BUSINESS_SCENARIOS = [
        self::SCENARIO_MERGE,
        self::SCENARIO_APPEAR,
        self::SCENARIO_RETIRE,
        self::SCENARIO_RENAME,
        self::SCENARIO_REUSE,
    ];

    /**
     * 场景卡片（向导第一步选择）：key => 业务语言文案。
     *
     * @return array<string, array{label: string, description: string}>
     */
    public function cards(): array
    {
        return [
            self::SCENARIO_MERGE => [
                'label' => '撤并 / 换码',
                'description' => '旧单位变成新单位。如：龙田乡 → 龙田镇',
            ],
            self::SCENARIO_APPEAR => [
                'label' => '新设（无前身）',
                'description' => '全新设立，没有来源单位',
            ],
            self::SCENARIO_RETIRE => [
                'label' => '撤销（无承继）',
                'description' => '单位消亡且公告里没有明确归属。请先确认公告是否划归其他单位——有归属请选「撤并 / 换码」',
            ],
            self::SCENARIO_RENAME => [
                'label' => '改名 / 换隶属',
                'description' => '代码没变，名称或上级变了',
            ],
            self::SCENARIO_REUSE => [
                'label' => '代码复用',
                'description' => '旧代码被新单位启用',
            ],
            self::SCENARIO_COMPLEX => [
                'label' => '复杂情形',
                'description' => '一对多拆分、例外下级组合等，进入高级模式（专家兜底）',
            ],
        ];
    }

    /**
     * 场景表单数据 → v3 payload 清单 + 证据池条目 + 业务语言反馈。
     *
     * @param  array<string, mixed>  $data  向导表单数据
     * @param  array<int, array<string, mixed>>  $oldMap  旧基线 csv
     * @param  array<int, array<string, mixed>>  $newMap  新版 csv
     * @return array{payloads: list<array<string, mixed>>, evidence: array<string, array{title: string, url: string}>, feedback: string}
     */
    public function assemble(string $scenario, array $data, array $oldMap, array $newMap): array
    {
        [$evidenceKeys, $evidence] = $this->noticeEvidence($data);

        return match ($scenario) {
            self::SCENARIO_MERGE => $this->assembleMerge($data, $oldMap, $newMap, $evidenceKeys, $evidence),
            self::SCENARIO_APPEAR => $this->assembleAppear($data, $newMap, $evidenceKeys, $evidence),
            self::SCENARIO_RETIRE => $this->assembleRetire($data, $oldMap, $evidenceKeys, $evidence),
            self::SCENARIO_RENAME => $this->assembleRename($data, $oldMap, $newMap, $evidenceKeys, $evidence),
            self::SCENARIO_REUSE => $this->assembleReuse($data, $oldMap, $newMap, $evidenceKeys, $evidence),
            default => throw new RuntimeException("未知场景：{$scenario}"),
        };
    }

    /**
     * 撤并/换码：1 条 edge；填了例外下级时追加 retired 端点 node
     * （端点 node 是纯注脚，迁移产物与只写 edge 逐字节相同；例外只挂 node，retired 挂旧侧消失下级）。
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $oldMap
     * @param  array<int, array<string, mixed>>  $newMap
     * @param  list<string>  $evidenceKeys
     * @param  array<string, array{title: string, url: string}>  $evidence
     * @return array{payloads: list<array<string, mixed>>, evidence: array<string, array{title: string, url: string}>, feedback: string}
     */
    protected function assembleMerge(array $data, array $oldMap, array $newMap, array $evidenceKeys, array $evidence): array
    {
        $fromId = (int) ($data['from_id'] ?? 0);
        $toId = (int) ($data['to_id'] ?? 0);
        $fromName = $this->unitName($oldMap, $fromId, '原单位', '旧版');
        $toName = $this->unitName($newMap, $toId, '现单位', '新版');
        $summary = filled($data['summary'] ?? null) ? trim((string) $data['summary']) : "撤{$fromName}设{$toName}";

        $payloads = [array_filter([
            'kind' => 'edge',
            'from_id' => $fromId,
            'to_id' => $toId,
            'cross_level_reason' => filled($data['cross_level_reason'] ?? null) ? trim((string) $data['cross_level_reason']) : null,
            'summary' => $summary,
            'evidence' => $evidenceKeys,
            'confidence' => 'high',
        ], fn (mixed $v): bool => $v !== null)];

        $exceptions = $this->exceptions($data);
        if ($exceptions !== []) {
            $payloads[] = [
                'kind' => 'node',
                'id' => $fromId,
                'state' => ChangesGraph::STATE_RETIRED,
                'exceptions' => $exceptions,
                'summary' => $summary,
                'evidence' => $evidenceKeys,
                'confidence' => 'high',
            ];
        }

        return [
            'payloads' => $payloads,
            'evidence' => $evidence,
            'feedback' => "已录入：{$fromName} → {$toName}",
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $newMap
     * @param  list<string>  $evidenceKeys
     * @param  array<string, array{title: string, url: string}>  $evidence
     * @return array{payloads: list<array<string, mixed>>, evidence: array<string, array{title: string, url: string}>, feedback: string}
     */
    protected function assembleAppear(array $data, array $newMap, array $evidenceKeys, array $evidence): array
    {
        $id = (int) ($data['new_id'] ?? 0);
        $name = $this->unitName($newMap, $id, '新设单位', '新版');

        return [
            'payloads' => [[
                'kind' => 'node',
                'id' => $id,
                'state' => ChangesGraph::STATE_APPEARED,
                'summary' => filled($data['summary'] ?? null) ? trim((string) $data['summary']) : "新设{$name}",
                'evidence' => $evidenceKeys,
                'confidence' => 'high',
            ]],
            'evidence' => $evidence,
            'feedback' => "已录入：新设 {$name}",
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $oldMap
     * @param  list<string>  $evidenceKeys
     * @param  array<string, array{title: string, url: string}>  $evidence
     * @return array{payloads: list<array<string, mixed>>, evidence: array<string, array{title: string, url: string}>, feedback: string}
     */
    protected function assembleRetire(array $data, array $oldMap, array $evidenceKeys, array $evidence): array
    {
        $id = (int) ($data['old_id'] ?? 0);
        $name = $this->unitName($oldMap, $id, '被撤销单位', '旧版');

        return [
            'payloads' => [array_filter([
                'kind' => 'node',
                'id' => $id,
                'state' => ChangesGraph::STATE_RETIRED,
                'exceptions' => ($exceptions = $this->exceptions($data)) !== [] ? $exceptions : null,
                'summary' => filled($data['summary'] ?? null) ? trim((string) $data['summary']) : "撤销{$name}",
                'evidence' => $evidenceKeys,
                'confidence' => 'high',
            ], fn (mixed $v): bool => $v !== null)],
            'evidence' => $evidence,
            'feedback' => "已录入：撤销 {$name}（无承继）",
        ];
    }

    /**
     * 改名/换隶属：continued node；attributes 由"哪些变了"复选框翻译成字段名清单，
     * 值由机器从两版 csv 取（用户不填值）。
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $oldMap
     * @param  array<int, array<string, mixed>>  $newMap
     * @param  list<string>  $evidenceKeys
     * @param  array<string, array{title: string, url: string}>  $evidence
     * @return array{payloads: list<array<string, mixed>>, evidence: array<string, array{title: string, url: string}>, feedback: string}
     */
    protected function assembleRename(array $data, array $oldMap, array $newMap, array $evidenceKeys, array $evidence): array
    {
        $id = (int) ($data['rename_id'] ?? 0);
        $oldName = $this->unitName($oldMap, $id, '该单位', '旧版');
        $newName = $this->unitName($newMap, $id, '该单位', '新版');

        $attributes = array_values(array_filter(array_map('strval', (array) ($data['rename_attributes'] ?? []))));
        if ($attributes === []) {
            throw new RuntimeException('请勾选哪些信息发生了变化（名称 / 全称 / 上级）');
        }
        $labels = ['name' => '名称', 'ext_name' => '全称', 'pid' => '上级'];
        $changedText = implode('、', array_map(fn (string $f): string => $labels[$f] ?? $f, $attributes));
        $feedback = $oldName !== $newName
            ? "已录入：{$oldName} 更名为 {$newName}"
            : "已录入：{$newName}（{$changedText}变更）";

        return [
            'payloads' => [[
                'kind' => 'node',
                'id' => $id,
                'state' => ChangesGraph::STATE_CONTINUED,
                'attributes' => $attributes,
                'summary' => filled($data['summary'] ?? null) ? trim((string) $data['summary']) : "{$oldName}：{$changedText}变更",
                'evidence' => $evidenceKeys,
                'confidence' => 'high',
            ]],
            'evidence' => $evidence,
            'feedback' => $feedback,
        ];
    }

    /**
     * 代码复用：appeared node + id_reuse=true 自动带；summary 必填（I7 要求写明复用对应关系）。
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $oldMap
     * @param  array<int, array<string, mixed>>  $newMap
     * @param  list<string>  $evidenceKeys
     * @param  array<string, array{title: string, url: string}>  $evidence
     * @return array{payloads: list<array<string, mixed>>, evidence: array<string, array{title: string, url: string}>, feedback: string}
     */
    protected function assembleReuse(array $data, array $oldMap, array $newMap, array $evidenceKeys, array $evidence): array
    {
        $id = (int) ($data['reuse_id'] ?? 0);
        $this->unitName($oldMap, $id, '该代码的旧单位', '旧版');
        $newName = $this->unitName($newMap, $id, '启用该代码的新单位', '新版');

        if (! filled($data['summary'] ?? null) || trim((string) $data['summary']) === '') {
            throw new RuntimeException('代码复用必须填写变化说明：哪个旧单位腾出了该代码、旧单位去向');
        }

        return [
            'payloads' => [[
                'kind' => 'node',
                'id' => $id,
                'state' => ChangesGraph::STATE_APPEARED,
                'id_reuse' => true,
                'summary' => trim((string) $data['summary']),
                'evidence' => $evidenceKeys,
                'confidence' => 'high',
            ]],
            'evidence' => $evidence,
            'feedback' => "已录入：{$newName}启用旧代码{$id}",
        ];
    }

    /**
     * 政府公告（标题 + 链接）→ 证据池条目与引用 id。
     *
     * @param  array<string, mixed>  $data
     * @return array{0: list<string>, 1: array<string, array{title: string, url: string}>}
     */
    protected function noticeEvidence(array $data): array
    {
        $title = trim((string) ($data['notice_title'] ?? ''));
        $url = trim((string) ($data['notice_url'] ?? ''));
        if ($title === '' || $url === '') {
            throw new RuntimeException('请填写政府公告（标题 + 链接）');
        }

        $key = 'manual_'.substr(sha1($url.microtime()), 0, 8);

        return [[$key], [$key => ['title' => $title, 'url' => $url]]];
    }

    /**
     * 例外下级条目（{id, reason} 清单；空条目剔除）。
     *
     * @param  array<string, mixed>  $data
     * @return list<array{id: int, reason: string}>
     */
    protected function exceptions(array $data): array
    {
        $rows = array_filter(
            (array) ($data['exceptions'] ?? []),
            fn (mixed $e): bool => is_array($e) && filled($e['id'] ?? null) && filled($e['reason'] ?? null),
        );

        return array_values(array_map(
            fn (array $e): array => ['id' => (int) $e['id'], 'reason' => trim((string) $e['reason'])],
            $rows,
        ));
    }

    /**
     * 单位显示名（ext_name 优先）；id 不在预期一侧的 csv 里时抛出业务语言错误。
     *
     * @param  array<int, array<string, mixed>>  $map
     */
    protected function unitName(array $map, int $id, string $role, string $sideName): string
    {
        if ($id === 0 || ! isset($map[$id])) {
            throw new RuntimeException("{$role}不存在于{$sideName}数据中（id：".($id ?: '未选择').'），请重新选择');
        }

        return (string) ($map[$id]['ext_name'] ?? $map[$id]['name'] ?? $id);
    }
}
