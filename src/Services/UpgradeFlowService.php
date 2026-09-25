<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Services;

use RuntimeException;

/**
 * 升级流程编排（升级方案 §2/§6 调整：全流程一次完成，无批次持久化）：
 * 发起升级（download+diff）、地区选择、采集任务包组装与产物回收、覆盖率记账。
 * 状态载体是 UpgradeWorkspace（文件态工作区），Web 界面与 area:collect 命令共用。
 *
 * 地区切片是工作管理单元：认领进度全局记账（§8.1），门禁按选中范围判定。
 */
class UpgradeFlowService
{
    public function __construct(
        protected readonly UpgradeWorkspace $workspace,
        protected readonly UpstreamService $upstream,
        protected readonly DiffRunner $diffRunner,
        protected readonly DiffService $diffService,
        protected readonly ImportService $import,
        protected readonly ChangesValidator $validator,
    ) {}

    /**
     * 发起升级（§6.1）：下载上游 csv + 自动 diff，重置工作区。
     *
     * @return array<string, mixed> 新工作区状态
     */
    public function start(string $targetUpstream): array
    {
        $csv = $this->upstream->download($targetUpstream);

        $this->workspace->reset();
        $state = $this->workspace->saveState([
            'target_upstream' => $targetUpstream,
            'from_version' => (string) config('cmf-area.data_version'),
            'status' => UpgradeWorkspace::STATUS_DRAFT,
            'assigned_version' => null,
            'new_csv_path' => $csv,
            'selected_regions' => [],
            'evidence_pool' => new \stdClass,
            'collection_state' => new \stdClass,
            'coverage_snapshot' => null,
            'finalize_result' => null,
            'created_at' => date('c'),
        ]);

        return $this->rediff($state);
    }

    /**
     * 重新 diff（概览 tab 的"重新 diff"动作；发起升级时自动执行一次）。
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function rediff(array $state): array
    {
        $newCsv = $state['new_csv_path'] ?? null;
        if (! is_string($newCsv) || ! is_file($newCsv)) {
            throw new RuntimeException('上游新版 csv 不存在，无法 diff（工作区产物缺失）');
        }

        $payload = $this->diffRunner->runToFile(
            $newCsv,
            $this->workspace->dir().'/diff.json',
            $this->dataDir().'/ok_data_level4.csv',
            (string) $state['from_version'],
            (string) $state['target_upstream'],
        );

        return $this->workspace->saveState([
            'diff_summary' => $this->summaryByProvince($payload),
            'status' => UpgradeWorkspace::STATUS_DIFF_READY,
        ]);
    }

    /**
     * 基线数据目录（csv / changes 留档 / 快照），可用 config 覆盖（测试用）。
     */
    public function dataDir(): string
    {
        return config('cmf-area.upgrade.data_dir') ?: dirname(__DIR__, 2).'/database/data';
    }

    /**
     * 加载工作区 diff.json（同请求内缓存：地区树/覆盖率/重点队列都会读）。
     *
     * @return array<string, mixed>
     */
    public function diff(): array
    {
        $path = $this->workspace->dir().'/diff.json';
        if (isset(self::$diffCache[$path])) {
            return self::$diffCache[$path];
        }
        if (! is_file($path)) {
            throw new RuntimeException('diff.json 不存在（请先发起升级）');
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException("diff.json 不是合法 JSON：{$path}");
        }

        return self::$diffCache[$path] = $decoded;
    }

    /** @var array<string, array<string, mixed>> 请求级静态缓存 */
    protected static array $diffCache = [];

    /**
     * 新旧两版 csv map（同请求内按 csv 路径缓存：地区树/覆盖率/校验都会用到，
     * 4 万行 csv 每次重解析要 ~0.4s，缓存后整页渲染只解析一次）。
     *
     * @param  array<string, mixed>  $state
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    public function maps(array $state): array
    {
        $newCsv = $state['new_csv_path'] ?? null;
        if (! is_string($newCsv) || ! is_file($newCsv)) {
            throw new RuntimeException('上游新版 csv 不存在（工作区产物缺失）');
        }

        $oldCsv = $this->dataDir().'/ok_data_level4.csv';
        $cacheKey = $oldCsv.'|'.$newCsv;
        if (isset(self::$mapsCache[$cacheKey])) {
            return self::$mapsCache[$cacheKey];
        }

        return self::$mapsCache[$cacheKey] = [
            $this->import->loadCsvAsMap($oldCsv),
            $this->import->loadCsvAsMap($newCsv),
        ];
    }

    /** @var array<string, array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}> 请求级静态缓存（FPM 每请求隔离） */
    protected static array $mapsCache = [];

    /**
     * 地区选择树（§6.2）：只含"子树内有 diff 事实"的省/市节点（deep 0/1），
     * 节点带事实计数（县级事实归并到市/省）。最小可选到市级。
     *
     * @param  array<string, mixed>  $state
     * @return list<array{id: int, name: string, deep: int, total: int, kinds: array<string, int>, children: list<array<string, mixed>>}>
     */
    public function regionTree(array $state): array
    {
        $diff = $this->diff();
        [$oldMap, $newMap] = $this->maps($state);

        // 事实计数：ancestor id => kinds 计数（新旧两侧都记账，§8.1 全局记账）
        /** @var array<int, array<string, int>> $counts */
        $counts = [];
        $kinds = ['added', 'removed', 'renamed', 'parent_changed', 'code_reuse_suspected'];
        foreach (($diff['provinces'] ?? []) as $groups) {
            foreach ($kinds as $kind) {
                foreach ($groups[$kind] ?? [] as $row) {
                    $id = (int) $row['id'];
                    foreach ($this->ancestorChain($id, $oldMap, $newMap) as $ancestorId) {
                        $counts[$ancestorId][$kind] = ($counts[$ancestorId][$kind] ?? 0) + 1;
                    }
                }
            }
        }

        // 组装树：只到市级（deep 0 → 1）；县级不建节点——其事实已通过
        // ancestorChain 归并进市/省计数，页面只展示省/市两级（最小可选到市级）
        $nodes = [];
        foreach ($counts as $id => $kindCounts) {
            $row = $newMap[$id] ?? $oldMap[$id] ?? null;
            if ($row === null || (int) $row['deep'] > 1) {
                continue;
            }
            $nodes[$id] = [
                'id' => $id,
                'name' => (string) ($row['ext_name'] ?? $row['name'] ?? $id),
                'deep' => (int) $row['deep'],
                'total' => array_sum($kindCounts),
                'kinds' => $kindCounts,
                'children' => [],
            ];
        }

        $tree = [];
        foreach ($nodes as $id => $node) {
            if ($node['deep'] > 0) {
                continue;
            }
            $tree[$id] = $node;
        }
        foreach ($nodes as $id => $node) {
            if ($node['deep'] !== 1) {
                continue;
            }
            $pid = $this->pidOf($id, $oldMap, $newMap);
            if ($pid !== null && isset($tree[$pid])) {
                $tree[$pid]['children'][$id] = $node;
            }
        }

        $sort = function (array $nodes) use (&$sort): array {
            uasort($nodes, fn (array $a, array $b): int => $b['total'] <=> $a['total'] ?: $a['id'] <=> $b['id']);

            return array_values(array_map(function (array $node) use (&$sort): array {
                $node['children'] = $sort($node['children']);

                return $node;
            }, $nodes));
        };

        return $sort($tree);
    }

    /**
     * 更新选中地区（§8.3：未定稿前可追加，覆盖率按并集重算）。
     *
     * @param  array<string, mixed>  $state
     * @param  list<int>  $regionIds
     * @return array<string, mixed>
     */
    public function updateRegions(array $state, array $regionIds): array
    {
        $this->assertNotGenerated($state);

        [, $newMap] = $this->maps($state);
        [$oldMap] = $this->maps($state);

        $regions = [];
        foreach (array_unique(array_map('intval', $regionIds)) as $id) {
            $row = $newMap[$id] ?? $oldMap[$id] ?? null;
            if ($row === null) {
                throw new RuntimeException("地区 id {$id} 在新旧两版 csv 中都不存在");
            }
            $regions[] = ['id' => $id, 'name' => (string) ($row['ext_name'] ?? $row['name'] ?? $id)];
        }

        return $this->workspace->saveState(['selected_regions' => $regions]);
    }

    /**
     * 覆盖率记账（§6.4 仪表盘 + §6.5 门禁）：
     * 选中范围内每条 diff 事实的认领状态（认领按审定口径与全量口径各算一份）。
     *
     * @param  array<string, mixed>  $state
     * @return array{regions: array<string, array{total: int, approved: int, claimed: int}>, total: int, approved: int, claimed: int, uncovered_final: list<array<string, mixed>>, uncovered_all: list<array<string, mixed>>}
     */
    public function coverage(array $state): array
    {
        $diff = $this->diff();
        [$oldMap, $newMap] = $this->maps($state);
        $regionIds = $this->selectedRegionIds($state);

        // 空选区不按全量统计：范围未圈定时覆盖率无意义，由页面引导先选地区
        if ($regionIds === []) {
            return [
                'regions' => [],
                'total' => 0,
                'claimed' => 0,
                'approved' => 0,
                'uncovered_final' => [],
                'uncovered_all' => [],
            ];
        }

        $items = $this->workspace->items();
        $changesApproved = $this->buildChanges($state, array_values(array_filter(
            $items,
            fn (array $i): bool => in_array($i['review_status'] ?? null, UpgradeWorkspace::REVIEW_FINALIZED_STATUSES, true),
        )));
        $changesAll = $this->buildChanges($state, array_values(array_filter(
            $items,
            fn (array $i): bool => ($i['review_status'] ?? null) !== UpgradeWorkspace::REVIEW_REJECTED,
        )));

        $evidence = is_array($state['evidence_pool'] ?? null) ? $state['evidence_pool'] : [];
        $graphApproved = new ChangesGraph($changesApproved['changes'], $oldMap, $newMap, $evidence);
        $graphAll = new ChangesGraph($changesAll['changes'], $oldMap, $newMap, $evidence);

        $uncoveredFinal = $this->validator->uncoveredFacts($graphApproved, $diff, $regionIds, $oldMap, $newMap);
        $uncoveredAll = $this->validator->uncoveredFacts($graphAll, $diff, $regionIds, $oldMap, $newMap);

        // 分地区统计
        $facts = $this->validator->diffFacts($diff);
        $byRegion = [];
        $total = 0;
        foreach ($facts as $id => $fact) {
            if (! $this->diffService->isInRegions($id, $regionIds, $oldMap, $newMap)) {
                continue;
            }
            $total++;
            $province = $fact['province'];
            $byRegion[$province]['total'] = ($byRegion[$province]['total'] ?? 0) + 1;
        }
        $claimedAll = $total - count($this->uniqueFactIds($uncoveredAll));
        $approved = $total - count($this->uniqueFactIds($uncoveredFinal));

        // uncovered 按省归扣
        $uncoveredByProvince = [];
        foreach ($uncoveredAll as $fact) {
            $uncoveredByProvince[$fact['province']][$fact['id']] = true;
        }
        $uncoveredFinalByProvince = [];
        foreach ($uncoveredFinal as $fact) {
            $uncoveredFinalByProvince[$fact['province']][$fact['id']] = true;
        }
        foreach ($byRegion as $province => &$stat) {
            $stat['claimed'] = $stat['total'] - count($uncoveredByProvince[$province] ?? []);
            $stat['approved'] = $stat['total'] - count($uncoveredFinalByProvince[$province] ?? []);
        }
        unset($stat);
        ksort($byRegion);

        $snapshot = [
            'regions' => $byRegion,
            'total' => $total,
            'claimed' => $claimedAll,
            'approved' => $approved,
            'uncovered_final' => $uncoveredFinal,
            'uncovered_all' => $uncoveredAll,
        ];

        $this->workspace->saveState(['coverage_snapshot' => $snapshot]);

        return $snapshot;
    }

    /**
     * 把判读记录组装成 v3 changes 结构（证据池取工作区级）。
     *
     * @param  array<string, mixed>  $state
     * @param  list<array<string, mixed>>|null  $items  null = 审定口径（approved/edited/manual）
     * @return array<string, mixed>
     */
    public function buildChanges(array $state, ?array $items = null, ?string $version = null): array
    {
        $items ??= array_values(array_filter(
            $this->workspace->items(),
            fn (array $i): bool => in_array($i['review_status'] ?? null, UpgradeWorkspace::REVIEW_FINALIZED_STATUSES, true),
        ));

        $changes = [];
        foreach ($items as $item) {
            $payload = is_array($item['payload'] ?? null) ? $item['payload'] : [];
            if (! array_key_exists('confidence', $payload) && is_string($item['confidence'] ?? null)) {
                $payload['confidence'] = $item['confidence'];
            }
            $changes[] = $payload;
        }

        $evidence = $state['evidence_pool'] ?? [];

        return [
            'schema_version' => 3,
            'version' => $version ?? ChangesValidator::incrementVersion((string) $state['from_version']),
            'from_version' => (string) $state['from_version'],
            'evidence' => $evidence === [] ? new \stdClass : $evidence,
            'changes' => $changes,
        ];
    }

    /**
     * 组装采集任务包（§6.3）：完整 diff + 完整新旧 csv + 选中地区清单 + 人类反馈，
     * 落到工作区 collect/ 目录，供维护者在仓库跑 area:collect / agent 处理。
     *
     * @param  array<string, mixed>  $state
     * @param  list<int>  $regionIds  本次采集的地区（空 = 全部未通过地区）
     * @return string 任务包目录
     */
    public function makeCollectPackage(array $state, array $regionIds = []): string
    {
        $this->assertNotGenerated($state);
        $selected = $this->selectedRegionIds($state);
        if ($selected === []) {
            throw new RuntimeException('尚未选择地区（先在"地区选择"tab 勾选）');
        }

        $collectState = is_array($state['collection_state'] ?? null) ? $state['collection_state'] : [];
        if ($regionIds === []) {
            // 默认收折叠后的顶层地区：父子同选时只跑一次父级判读（子树事实全覆盖）
            $regionIds = array_values(array_filter($this->collectRegionIds($state), function (int $id) use ($collectState): bool {
                $status = $collectState[(string) $id]['status'] ?? $collectState[$id]['status'] ?? null;

                return $status !== 'passed';
            }));
        }
        if ($regionIds === []) {
            throw new RuntimeException('所选地区均已采集通过，无待采集地区');
        }

        $dir = $this->workspace->dir().'/collect';
        if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
            throw new RuntimeException("无法创建目录：{$dir}");
        }

        [$oldMap, $newMap] = $this->maps($state);
        $diff = $this->diff();

        // 完整 diff + 完整 csv 都交给 AI（§8.1 跨边界上下文），scope 只是署名范围
        file_put_contents($dir.'/diff.json', json_encode($diff, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        copy($this->dataDir().'/ok_data_level4.csv', $dir.'/old.csv');
        copy((string) $state['new_csv_path'], $dir.'/new.csv');

        $regions = [];
        foreach ($regionIds as $id) {
            $row = $newMap[$id] ?? $oldMap[$id] ?? [];
            $entry = $collectState[(string) $id] ?? $collectState[$id] ?? [];
            $regions[] = [
                'id' => $id,
                'name' => (string) ($row['ext_name'] ?? $row['name'] ?? $id),
                'feedback' => $entry['feedback'] ?? null,
            ];
        }
        file_put_contents($dir.'/scope.json', json_encode([
            'from_version' => $state['from_version'],
            'target_upstream' => $state['target_upstream'],
            'regions' => $regions,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        file_put_contents($dir.'/COLLECT_TASK.md', $this->collectPrompt($state, $regions));

        foreach ($regionIds as $id) {
            $key = (string) $id;
            $existing = $collectState[$key] ?? [];
            $collectState[$key] = [
                'status' => 'collecting',
                // 新一轮判读发起即重置重试配额（failed 地区重新发起后给满 max_retries 次机会；
                // 若保留历史 retries，failed(retries=N) 地区下轮首次 ingest 失败会被立即判死）
                'retries' => 0,
                // 上一轮错误清单一并清空：重跑期间页面不再展示已失效的旧错误
                'last_errors' => [],
                'feedback' => $existing['feedback'] ?? null,
            ];
        }

        $this->workspace->saveState([
            'collection_state' => $collectState,
            'status' => UpgradeWorkspace::STATUS_COLLECTING,
        ]);

        return $dir;
    }

    /**
     * 回收 AI 判读产物（§6.3）：scoped 机器校验，全绿落库为 pending 记录；
     * 不过则错误清单记入采集状态（喂回 AI 重试），超过重试上限转人工。
     *
     * @param  array<string, mixed>  $state
     * @param  list<int>|null  $regionIds  本次覆盖的地区（null = 采集中的地区）
     * @return array{0: array<string, mixed>, 1: list<string>} [新状态, 校验错误清单（空 = 已落库）]
     */
    public function ingest(array $state, string $fragmentPath, ?array $regionIds = null): array
    {
        $fragment = json_decode((string) file_get_contents($fragmentPath), true);
        if (! is_array($fragment)) {
            return [$state, ['changes 片段不是合法 JSON']];
        }

        [$oldMap, $newMap] = $this->maps($state);
        $diff = $this->diff();

        // 版本字段由系统按契约补齐（AI 不需要关心版本分配规则，§3.3）
        $fragment['schema_version'] = 3;
        $fragment['from_version'] = (string) $state['from_version'];
        $fragment['version'] = ChangesValidator::incrementVersion((string) $state['from_version']);

        $selected = $this->selectedRegionIds($state);
        $collectState = is_array($state['collection_state'] ?? null) ? $state['collection_state'] : [];
        $regionIds ??= array_values(array_filter(
            $selected,
            fn (int $id): bool => ($collectState[(string) $id]['status'] ?? null) === 'collecting',
        ));
        if ($regionIds === []) {
            $regionIds = $selected;
        }

        // 本次替换口径（与成功路径同一谓词）：本次地区名下、AI 来源、未审定的记录
        // 会被片段整体替换——校验合并时必须同样剔除，否则重判同地区必撞 I1 同键重复
        $regionNames = $this->regionNamesOf($regionIds, $oldMap, $newMap);
        $isReplaceable = fn (array $item): bool => ($item['source'] ?? null) === UpgradeWorkspace::SOURCE_AI
            && ($item['review_status'] ?? null) === UpgradeWorkspace::REVIEW_PENDING
            && isset($regionNames[(string) ($item['region'] ?? '')]);

        // 合并"不会被替换"的已入库记录（认领全局记账，§8.1）后整图校验
        $existing = array_values(array_filter(
            $this->workspace->items(),
            fn (array $i): bool => ($i['review_status'] ?? null) !== UpgradeWorkspace::REVIEW_REJECTED && ! $isReplaceable($i),
        ));
        $fragmentItems = array_values(array_filter($fragment['changes'] ?? [], 'is_array'));

        // 幂等去重：与已入库记录同键同内容跳过（AI 重跑同结论是常态）；同键不同内容报冲突
        [$fragmentItems, $dedupeErrors] = $this->dedupeFragmentItems($fragmentItems, $existing, true);

        $merged = $fragment;
        $merged['changes'] = [
            ...array_map(fn (array $i): array => $i['payload'], $existing),
            ...$fragmentItems,
        ];
        $merged['evidence'] = [
            ...(is_array($state['evidence_pool'] ?? null) ? $state['evidence_pool'] : []),
            ...(is_array($fragment['evidence'] ?? null) ? $fragment['evidence'] : []),
        ];

        $errors = [
            ...$dedupeErrors,
            ...$this->validator->validate($merged, $diff, $oldMap, $newMap, $selected !== [] ? $selected : null),
        ];

        if ($errors !== []) {
            $maxRetries = (int) config('cmf-area.upgrade.max_retries', 3);
            foreach ($regionIds as $id) {
                $key = (string) $id;
                $existingEntry = $collectState[$key] ?? [];
                $retries = (int) ($existingEntry['retries'] ?? 0) + 1;
                $collectState[$key] = [
                    'status' => $retries >= $maxRetries ? 'failed' : 'collecting',
                    'retries' => $retries,
                    'last_errors' => $errors,
                    'feedback' => $existingEntry['feedback'] ?? null,
                ];
            }

            return [$this->workspace->saveState(['collection_state' => $collectState]), $errors];
        }

        // 全绿：替换这些地区的 AI 未审定记录（与校验合并同一口径），落库新片段
        $this->workspace->deleteItemsWhere($isReplaceable);

        $state = $this->workspace->saveState([
            'evidence_pool' => $merged['evidence'],
            'status' => UpgradeWorkspace::STATUS_REVIEWING,
        ]);

        $metas = $this->deriveMeta($state, $fragmentItems);
        foreach ($fragmentItems as $i => $item) {
            $this->workspace->addItem([
                'kind' => ($item['kind'] ?? null) === 'edge' ? 'edge' : 'node',
                'payload' => $item,
                'change_type' => $metas[$i]['change_type'] ?? null,
                'side' => $metas[$i]['side'] ?? null,
                'confidence' => isset($item['confidence']) && is_string($item['confidence']) ? $item['confidence'] : null,
                'review_status' => UpgradeWorkspace::REVIEW_PENDING,
                'source' => UpgradeWorkspace::SOURCE_AI,
                'region' => $metas[$i]['region'] ?? null,
            ]);
        }

        foreach ($regionIds as $id) {
            $key = (string) $id;
            $existingEntry = $collectState[$key] ?? [];
            $collectState[$key] = [
                'status' => 'passed',
                'retries' => (int) ($existingEntry['retries'] ?? 0),
                'last_errors' => [],
                'feedback' => null,
            ];
        }

        return [$this->workspace->saveState(['collection_state' => $collectState]), []];
    }

    /**
     * 本次回收地区对应的省份名集合（item.region 归属口径，§8.1）。
     *
     * @param  list<int>  $regionIds
     * @param  array<int, array<string, mixed>>  $oldMap
     * @param  array<int, array<string, mixed>>  $newMap
     * @return array<string, true>
     */
    protected function regionNamesOf(array $regionIds, array $oldMap, array $newMap): array
    {
        $names = [];
        foreach ($regionIds as $id) {
            $row = $newMap[$id] ?? $oldMap[$id] ?? [];
            $names[$this->provinceOfRow($row, $oldMap, $newMap)] = true;
        }

        return $names;
    }

    /**
     * 片段键（与校验器 I1 唯一键同口径）：node = (side 由 state 派生, id)，edge = (from, to)。
     *
     * @param  array<string, mixed>  $payload
     */
    protected function payloadKey(array $payload): string
    {
        if (($payload['kind'] ?? null) === 'edge') {
            return 'edge:'.(int) ($payload['from_id'] ?? 0).'→'.(int) ($payload['to_id'] ?? 0);
        }
        $side = ($payload['state'] ?? null) === ChangesGraph::STATE_APPEARED ? ChangesGraph::SIDE_NEW : ChangesGraph::SIDE_OLD;

        return 'node:'.$side.':'.(int) ($payload['id'] ?? 0);
    }

    /**
     * 内容规范化（键序无关）：递归 ksort 后比较 JSON，判定"同键同内容"幂等跳过。
     *
     * @param  array<string, mixed>  $payload
     */
    protected function canonicalPayload(array $payload): string
    {
        $normalize = function (mixed $v) use (&$normalize): mixed {
            if (is_array($v)) {
                if (array_is_list($v)) {
                    return array_map($normalize, $v);
                }
                ksort($v);

                return array_map($normalize, $v);
            }

            return $v;
        };

        return (string) json_encode($normalize($payload), JSON_UNESCAPED_UNICODE);
    }

    /**
     * 片段与已入库记录去重：同键同内容幂等跳过（AI 重跑同结论、人工已判同条是常态）；
     * 同键不同内容报冲突并指到已入库记录 id（该记录不由本次判读覆盖，审核页可编辑）。
     *
     * @param  list<array<string, mixed>>  $fragmentItems
     * @param  list<array<string, mixed>>  $existing  已入库记录（含 id/source/review_status/region）
     * @param  bool  $tolerateIdentical  true=同内容跳过不报错（AI 回收）；false=同内容也报重复（人工编辑防误重）
     * @return array{0: list<array<string, mixed>>, 1: list<string>} [过滤后片段, 冲突/重复错误清单]
     */
    protected function dedupeFragmentItems(array $fragmentItems, array $existing, bool $tolerateIdentical): array
    {
        $byKey = [];
        foreach ($existing as $item) {
            $payload = is_array($item['payload'] ?? null) ? $item['payload'] : null;
            if ($payload !== null) {
                $byKey[$this->payloadKey($payload)] = $item;
            }
        }

        $kept = [];
        $errors = [];
        foreach ($fragmentItems as $payload) {
            $key = $this->payloadKey($payload);
            $stored = $byKey[$key] ?? null;
            if ($stored === null) {
                $kept[] = $payload;
                continue;
            }
            $identical = $this->canonicalPayload($stored['payload']) === $this->canonicalPayload($payload);
            if ($identical && $tolerateIdentical) {
                continue;
            }
            $label = $this->storedItemLabel($stored);
            $errors[] = $identical
                ? "（{$key}）与已入库记录 #{$stored['id']}（{$label}）完全重复——该记录已在待审库中，无需重复提交"
                : "（{$key}）与已入库记录 #{$stored['id']}（{$label}）内容冲突——已入库记录不由本次判读覆盖，确需修正请到审核页面编辑该记录";
        }

        return [$kept, $errors];
    }

    /**
     * 已入库记录的可读标签（来源/审核状态/归属地区）。
     *
     * @param  array<string, mixed>  $item
     */
    protected function storedItemLabel(array $item): string
    {
        $src = ($item['source'] ?? null) === UpgradeWorkspace::SOURCE_MANUAL ? '人工录入' : 'AI';
        $status = (string) ($item['review_status'] ?? '-');
        $region = (string) ($item['region'] ?? '');

        return trim("{$src} {$status} {$region}");
    }

    /**
     * 判读 prompt 用的已入库记录摘要：只列"不会被本次回收替换"的记录
     * （人工/已审定/已编辑/其他地区 AI 记录——撞键即冲突）；本次地区内 AI 未审定记录
     * 会被整体替换，不必列出。
     *
     * @param  array<string, mixed>  $state
     * @param  list<int>  $regionIds
     */
    public function storedItemsDigest(array $state, array $regionIds): ?string
    {
        [$oldMap, $newMap] = $this->maps($state);
        $regionNames = $this->regionNamesOf($regionIds, $oldMap, $newMap);

        $lines = [];
        foreach ($this->workspace->items() as $item) {
            if (($item['review_status'] ?? null) === UpgradeWorkspace::REVIEW_REJECTED) {
                continue;
            }
            $replaceable = ($item['source'] ?? null) === UpgradeWorkspace::SOURCE_AI
                && ($item['review_status'] ?? null) === UpgradeWorkspace::REVIEW_PENDING
                && isset($regionNames[(string) ($item['region'] ?? '')]);
            if ($replaceable) {
                continue;
            }
            $payload = is_array($item['payload'] ?? null) ? $item['payload'] : [];
            $lines[] = '- #'.(int) ($item['id'] ?? 0).' '.$this->payloadKey($payload).'（'.$this->storedItemLabel($item).'）';
            if (count($lines) >= 20) {
                $lines[] = '- ……（其余略）';
                break;
            }
        }
        if ($lines === []) {
            return null;
        }

        return "已入库且不会被本次回收替换的记录（撞键即冲突报错，勿重复提交；确需修正由人工在审核页面处理）：\n".implode("\n", $lines);
    }

    /**
     * 疑似代码重用 id 清单（重点队列用，§8.2）。
     *
     * @return array<int, true>
     */
    public function codeReuseIds(): array
    {
        $ids = [];
        foreach (($this->diff()['provinces'] ?? []) as $groups) {
            foreach ($groups['code_reuse_suspected'] ?? [] as $row) {
                $ids[(int) $row['id']] = true;
            }
        }

        return $ids;
    }

    /**
     * 编辑/手工录入的保存时实时校验（§6.4）：合并现有未驳回记录后整图校验，
     * 但跳过覆盖率（覆盖率是升级级门禁，单条落库不该被无关未认领事实阻断）。
     *
     * @param  array<string, mixed>  $state
     * @param  list<array<string, mixed>>  $extraPayloads  新增/编辑后的 v3 记录
     * @param  int|null  $excludeItemId  编辑场景：被编辑的记录不计入合并
     * @param  array<string, mixed>|null  $extraEvidence  随记录新增的证据池条目
     * @return list<string> 错误清单（空 = 通过）
     */
    public function validateItemsWith(array $state, array $extraPayloads, ?int $excludeItemId = null, ?array $extraEvidence = null): array
    {
        $diff = $this->diff();
        [$oldMap, $newMap] = $this->maps($state);

        $items = array_values(array_filter(
            $this->workspace->items(),
            fn (array $i): bool => ($i['review_status'] ?? null) !== UpgradeWorkspace::REVIEW_REJECTED
                && (int) ($i['id'] ?? 0) !== $excludeItemId,
        ));

        // 撞键明示：与已入库记录同键时给出来源指引（完全重复/内容冲突），而非笼统 I1
        [$extraPayloads, $dedupeErrors] = $this->dedupeFragmentItems($extraPayloads, $items, false);

        $changes = [
            'schema_version' => 3,
            'version' => ChangesValidator::incrementVersion((string) $state['from_version']),
            'from_version' => (string) $state['from_version'],
            'evidence' => [
                ...(is_array($state['evidence_pool'] ?? null) ? $state['evidence_pool'] : []),
                ...($extraEvidence ?? []),
            ],
            'changes' => [
                ...array_map(fn (array $i): array => $i['payload'], $items),
                ...$extraPayloads,
            ],
        ];

        $scope = $this->selectedRegionIds($state);

        return [
            ...$dedupeErrors,
            ...$this->validator->validate($changes, $diff, $oldMap, $newMap, $scope !== [] ? $scope : null, skipCoverage: true),
        ];
    }

    /**
     * 合并证据池条目（手工录入/编辑随记录新增的证据）。
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $entries
     * @return array<string, mixed>
     */
    public function mergeEvidence(array $state, array $entries): array
    {
        if ($entries === []) {
            return $state;
        }

        return $this->workspace->saveState([
            'evidence_pool' => [
                ...(is_array($state['evidence_pool'] ?? null) ? $state['evidence_pool'] : []),
                ...$entries,
            ],
        ]);
    }

    /**
     * 为新增/编辑的 v3 记录派生展示元信息（change_type/side/归属地区），
     * 与 ChangesGraph 同一套派生逻辑；合并现有未驳回记录后整图派生。
     *
     * @param  array<string, mixed>  $state
     * @param  list<array<string, mixed>>  $payloads
     * @return list<array{change_type: string|null, side: string|null, region: string|null}>
     */
    public function deriveMeta(array $state, array $payloads): array
    {
        [$oldMap, $newMap] = $this->maps($state);
        $existing = array_map(
            fn (array $i): array => $i['payload'],
            array_filter(
                $this->workspace->items(),
                fn (array $i): bool => ($i['review_status'] ?? null) !== UpgradeWorkspace::REVIEW_REJECTED,
            ),
        );

        $evidence = is_array($state['evidence_pool'] ?? null) ? $state['evidence_pool'] : [];
        $graph = new ChangesGraph([...$existing, ...$payloads], $oldMap, $newMap, $evidence);

        $nodesByKey = [];
        foreach ($graph->nodes() as $node) {
            $nodesByKey[$node['side'].':'.$node['id']] = $node;
        }
        $edgesByKey = [];
        foreach ($graph->edges() as $edge) {
            $edgesByKey[$edge['from'].'→'.$edge['to']] = $edge;
        }

        return array_map(function (array $payload) use ($graph, $nodesByKey, $edgesByKey, $oldMap, $newMap): array {
            if (($payload['kind'] ?? null) === 'edge') {
                $edge = $edgesByKey[((int) ($payload['from_id'] ?? 0)).'→'.((int) ($payload['to_id'] ?? 0))] ?? null;

                return [
                    'change_type' => $edge !== null ? $graph->edgeChangeType($edge) : null,
                    'side' => null,
                    'region' => $this->itemRegion($payload, $oldMap, $newMap),
                ];
            }

            $itemState = (string) ($payload['state'] ?? '');
            $side = $itemState === ChangesGraph::STATE_APPEARED ? ChangesGraph::SIDE_NEW : ChangesGraph::SIDE_OLD;
            $node = $nodesByKey[$side.':'.((int) ($payload['id'] ?? 0))] ?? null;

            return [
                'change_type' => $node !== null ? $graph->nodeChangeType($node) : null,
                'side' => $side,
                'region' => $this->itemRegion($payload, $oldMap, $newMap),
            ];
        }, $payloads);
    }

    /**
     * 选中地区 id 清单。
     *
     * @param  array<string, mixed>  $state
     * @return list<int>
     */
    public function selectedRegionIds(array $state): array
    {
        return array_values(array_map(
            fn (array $r): int => (int) $r['id'],
            is_array($state['selected_regions'] ?? null) ? $state['selected_regions'] : [],
        ));
    }

    /**
     * 采集任务地区（顶层折叠）：选中地区中剔除"祖先同选"的下级。
     * 页面 syncParent 保证省在选中集 ⇔ 其全部市在选中集，故剔除下级不改变子树并集；
     * 同一行政行为只发起一次判读，杜绝直辖市等场景（省 31 与市 3101 同选）的重叠范围重复判读。
     *
     * @param  array<string, mixed>  $state
     * @return list<int>
     */
    public function collectRegionIds(array $state): array
    {
        $selected = $this->selectedRegionIds($state);
        if (count($selected) < 2) {
            return $selected;
        }

        [$oldMap, $newMap] = $this->maps($state);

        return array_values(array_filter($selected, function (int $id) use ($selected, $oldMap, $newMap): bool {
            foreach ($selected as $other) {
                if ($other !== $id && $this->diffService->isInRegions($id, [$other], $oldMap, $newMap)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * 驳回记录：备注随地区回采集队列（§6.4）。
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function rejectItem(array $state, int $itemId, string $note): array
    {
        $this->assertNotGenerated($state);

        $item = null;
        foreach ($this->workspace->items() as $row) {
            if ((int) ($row['id'] ?? 0) === $itemId) {
                $item = $row;

                break;
            }
        }
        if ($item === null) {
            throw new RuntimeException("判读记录不存在：{$itemId}");
        }

        $this->workspace->updateItem($itemId, [
            'review_status' => UpgradeWorkspace::REVIEW_REJECTED,
            'review_note' => $note,
        ]);

        $collectState = is_array($state['collection_state'] ?? null) ? $state['collection_state'] : [];
        [, $newMap] = $this->maps($state);
        [$oldMap] = $this->maps($state);
        foreach ((is_array($state['selected_regions'] ?? null) ? $state['selected_regions'] : []) as $region) {
            $id = (int) $region['id'];
            $row = $newMap[$id] ?? $oldMap[$id] ?? [];
            $province = $this->provinceOfRow($row, $oldMap, $newMap);
            if ($province === (string) ($item['region'] ?? '')) {
                $collectState[(string) $id] = [
                    'status' => 'rejected',
                    'retries' => 0,
                    'last_errors' => [],
                    'feedback' => $note,
                ];
            }
        }

        return $this->workspace->saveState(['collection_state' => $collectState]);
    }

    /**
     * 采集任务说明（喂给 AI 的 prompt 头，判读 SOP 本体在 skill）。
     *
     * @param  array<string, mixed>  $state
     * @param  list<array{id: int, name: string, feedback: string|null}>  $regions
     */
    protected function collectPrompt(array $state, array $regions): string
    {
        $lines = [
            '# 区划升级采集任务',
            '',
            "- 基线版本：{$state['from_version']}（旧基线 csv：old.csv）",
            "- 上游版本：{$state['target_upstream']}（新版 csv：new.csv）",
            '- diff 事实清单：diff.json（完整版；你只需为下列选中地区的事实署名判读，跨边界边允许写）',
            '',
            '## 选中地区',
        ];
        foreach ($regions as $region) {
            $lines[] = "- {$region['name']}（{$region['id']}）";
            if (is_string($region['feedback']) && $region['feedback'] !== '') {
                $lines[] = "  - 人类反馈（上轮驳回/审核意见，须逐条回应）：{$region['feedback']}";
            }
        }
        $collectDir = $this->workspace->dir().'/collect';
        $lines = [...$lines, '',
            '## 判读要求',
            '',
            '1. 严格按 area/skill/SKILL.md 的判读 SOP 与 changes.schema.json（v3）产出 changes 片段：只含本次选中地区的 node/edge 与新增证据池条目；',
            '2. version/from_version 字段可留占位（系统回收时按契约补齐）；',
            '3. 每条判定必须联网取证、附证据池引用；查不到标 confidence: low；',
            '4. 产出写回本目录 changes_fragment.json，随后运行：',
            "   php artisan area:collect --ingest={$collectDir}/changes_fragment.json",
            '5. 机器 scoped 校验不过时错误清单会写回采集状态，修正后重新 --ingest（重试上限 '.(int) config('cmf-area.upgrade.max_retries', 3).' 次，超限转人工录入）。',
            '',
        ];

        return implode("\n", $lines);
    }

    /**
     * item 归属地区（省级名称，分组展示/替换用）。
     *
     * @param  array<string, mixed>  $item  v3 记录
     * @param  array<int, array<string, mixed>>  $oldMap
     * @param  array<int, array<string, mixed>>  $newMap
     */
    protected function itemRegion(array $item, array $oldMap, array $newMap): ?string
    {
        $id = (int) (($item['kind'] ?? null) === 'edge' ? ($item['from_id'] ?? 0) : ($item['id'] ?? 0));
        if ($id === 0) {
            return null;
        }

        return $this->diffService->provinceOf($id, $newMap)
            ?? $this->diffService->provinceOf($id, $oldMap);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, array<string, mixed>>  $oldMap
     * @param  array<int, array<string, mixed>>  $newMap
     */
    protected function provinceOfRow(array $row, array $oldMap, array $newMap): string
    {
        $id = (int) ($row['id'] ?? 0);

        return $this->diffService->provinceOf($id, $newMap)
            ?? $this->diffService->provinceOf($id, $oldMap)
            ?? (string) ($row['ext_name'] ?? $id);
    }

    /**
     * id 的祖先链（含自身，新侧优先旧侧兜底；跨边界事实两侧都记，§8.1）。
     *
     * @param  array<int, array<string, mixed>>  $oldMap
     * @param  array<int, array<string, mixed>>  $newMap
     * @return list<int>
     */
    protected function ancestorChain(int $id, array $oldMap, array $newMap): array
    {
        $chain = [];
        foreach ([$newMap, $oldMap] as $map) {
            $guard = 0;
            $current = $id;
            while ($guard++ < 8 && isset($map[$current])) {
                if (! in_array($current, $chain, true)) {
                    $chain[] = $current;
                }
                $parent = (int) $map[$current]['pid'];
                if ($parent === 0 || $parent === $current) {
                    break;
                }
                $current = $parent;
            }
        }

        return $chain;
    }

    /**
     * @param  array<int, array<string, mixed>>  $oldMap
     * @param  array<int, array<string, mixed>>  $newMap
     */
    protected function pidOf(int $id, array $oldMap, array $newMap): ?int
    {
        $row = $newMap[$id] ?? $oldMap[$id] ?? null;

        return $row === null ? null : (int) $row['pid'];
    }

    /**
     * @param  array<string, mixed>  $payload  diff.json 结构
     * @return array<string, array<string, int>>
     */
    protected function summaryByProvince(array $payload): array
    {
        $summary = [];
        foreach (($payload['provinces'] ?? []) as $province => $groups) {
            $summary[$province] = [
                'added' => count($groups['added'] ?? []),
                'removed' => count($groups['removed'] ?? []),
                'renamed' => count($groups['renamed'] ?? []),
                'parent_changed' => count($groups['parent_changed'] ?? []),
                'code_reuse_suspected' => count($groups['code_reuse_suspected'] ?? []),
            ];
        }

        return $summary;
    }

    /**
     * @param  list<array<string, mixed>>  $uncovered
     * @return array<int, true>
     */
    protected function uniqueFactIds(array $uncovered): array
    {
        $ids = [];
        foreach ($uncovered as $fact) {
            $ids[(int) $fact['id']] = true;
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected function assertNotGenerated(array $state): void
    {
        if (($state['status'] ?? null) === UpgradeWorkspace::STATUS_GENERATED) {
            throw new RuntimeException('本次升级已定稿，不能再修改');
        }
    }
}
