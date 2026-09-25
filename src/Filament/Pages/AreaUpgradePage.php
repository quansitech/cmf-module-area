<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step as WizardStep;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Quansitech\Cmf\Area\Services\ChangesGraph;
use Quansitech\Cmf\Area\Services\CollectAgentRunner;
use Quansitech\Cmf\Area\Services\DiffService;
use Quansitech\Cmf\Area\Services\ManualScenarioService;
use Quansitech\Cmf\Area\Services\UpgradeFinalizeService;
use Quansitech\Cmf\Area\Services\UpgradeFlowService;
use Quansitech\Cmf\Area\Services\UpgradeWorkspace;
use Quansitech\Cmf\Area\Services\UpstreamService;
use UnitEnum;

/**
 * 区划数据升级向导（升级方案 §10 调整：全流程一次完成，无批次持久化）：
 * 概览（发起升级）/ 地区选择 / 采集状态 / 审核 / 定稿 五 tab。
 * 状态载体是文件态工作区（UpgradeWorkspace），人工闸门在迁移生成之前。
 */
class AreaUpgradePage extends Page
{
    protected static ?string $slug = 'area-upgrade';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static ?string $navigationLabel = '区划升级';

    protected static ?string $title = '区划数据升级';

    protected string $view = 'cmf-area::filament.pages.area-upgrade';

    public string $activeTab = 'overview';

    /** @var list<int> 地区选择 tab 的勾选状态 */
    public array $regionSelection = [];

    /** @var string|null 判读模型（界面下拉选中值，落工作区 state 供后台进程读取；空 = 默认） */
    public ?string $agentModel = null;

    public function mount(): void
    {
        if ($this->workspace()->exists()) {
            $this->regionSelection = $this->flow()->selectedRegionIds($this->stateData());
            $model = $this->stateData()['agent_model'] ?? null;
            $this->agentModel = is_string($model) && $model !== '' ? $model : null;
        }
    }

    /**
     * 判读模型下拉变更即保存：后台判读进程是独立 PHP 进程，
     * 选中值必须落 workspace.json（runPi 读 state['agent_model'] 拼 --model）。
     */
    public function updatedAgentModel(?string $value): void
    {
        if (! $this->workspace()->exists()) {
            return;
        }
        $this->workspace()->saveState(['agent_model' => $value !== null && $value !== '' ? $value : null]);
        $this->flushCaches();
    }

    /**
     * 判读模型下拉选项（agent --list-models，工作区缓存；空值 = 默认模型）。
     *
     * @return array<string, string>
     */
    public function agentModelOptions(): array
    {
        return app(CollectAgentRunner::class)->availableModels();
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('filament-shield::filament-shield.nav.group') === 'filament-shield::filament-shield.nav.group'
            ? '系统'
            : __('filament-shield::filament-shield.nav.group');
    }

    /**
     * 维护者工具开关（升级方案 §12）：默认关，业务项目后台一律不可见。
     */
    public static function canAccess(): bool
    {
        return (bool) config('cmf-area.upgrade.enabled', false);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return (bool) config('cmf-area.upgrade.enabled', false);
    }

    protected function workspace(): UpgradeWorkspace
    {
        return app(UpgradeWorkspace::class);
    }

    protected function flow(): UpgradeFlowService
    {
        return app(UpgradeFlowService::class);
    }

    // ── 数据供给（blade 取用，单次请求内缓存）──

    /** @var array<string, mixed>|null */
    protected ?array $stateCache = null;

    protected bool $stateLoaded = false;

    /** @return array<string, mixed>|null 工作区状态（未发起升级为 null） */
    public function stateData(): ?array
    {
        if (! $this->stateLoaded) {
            $this->stateCache = $this->workspace()->exists() ? $this->workspace()->state() : null;
            $this->stateLoaded = true;
        }

        return $this->stateCache;
    }

    /** @var array<string, mixed>|null */
    protected ?array $regionTreeCache = null;

    /** @return list<array<string, mixed>> */
    public function regionTreeData(): array
    {
        if ($this->regionTreeCache !== null) {
            return $this->regionTreeCache;
        }
        $state = $this->stateData();

        return $this->regionTreeCache = $state === null ? [] : $this->flow()->regionTree($state);
    }

    /** @var array<string, mixed>|null */
    protected ?array $coverageCache = null;

    /** @return array<string, mixed> */
    public function coverageData(): array
    {
        if ($this->coverageCache !== null) {
            return $this->coverageCache;
        }
        $state = $this->stateData();
        if ($state === null) {
            return $this->coverageCache = ['regions' => [], 'total' => 0, 'claimed' => 0, 'approved' => 0, 'uncovered_final' => [], 'uncovered_all' => []];
        }

        try {
            return $this->coverageCache = $this->flow()->coverage($state);
        } catch (\Throwable) {
            return $this->coverageCache = ['regions' => [], 'total' => 0, 'claimed' => 0, 'approved' => 0, 'uncovered_final' => [], 'uncovered_all' => []];
        }
    }

    /** @return array<int, true> */
    public function codeReuseIdsData(): array
    {
        try {
            return $this->stateData() === null ? [] : $this->flow()->codeReuseIds();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<array<string, mixed>> 判读记录（pending 在前） */
    public function itemsData(): array
    {
        $items = $this->workspace()->items();
        usort($items, function (array $a, array $b): int {
            $pending = (($a['review_status'] ?? '') === 'pending' ? 0 : 1) <=> (($b['review_status'] ?? '') === 'pending' ? 0 : 1);

            return $pending !== 0 ? $pending : ((int) $a['id'] <=> (int) $b['id']);
        });

        return $items;
    }

    /** @return array<string, list<array<string, mixed>>> 按归属地区分组 */
    public function itemsByRegionData(): array
    {
        $groups = [];
        foreach ($this->itemsData() as $item) {
            $groups[(string) ($item['region'] ?? '（未归属）')][] = $item;
        }

        return $groups;
    }

    /** @return list<array<string, mixed>> 重点队列（§6.4：低置信 / id 复用 / 疑似代码重用） */
    public function focusItemsData(): array
    {
        $reuseIds = $this->codeReuseIdsData();

        return array_values(array_filter($this->itemsData(), function (array $item) use ($reuseIds): bool {
            if (($item['review_status'] ?? null) !== UpgradeWorkspace::REVIEW_PENDING) {
                return false;
            }
            $payload = is_array($item['payload'] ?? null) ? $item['payload'] : [];

            return ($item['confidence'] ?? null) === 'low'
                || ($payload['id_reuse'] ?? false) === true
                || isset($reuseIds[(int) ($payload['id'] ?? 0)])
                || isset($reuseIds[(int) ($payload['to_id'] ?? 0)])
                || isset($reuseIds[(int) ($payload['from_id'] ?? 0)]);
        }));
    }

    /**
     * 记录的可读标签（列表展示用）。
     *
     * @param  array<string, mixed>  $item
     */
    public function itemLabel(array $item): string
    {
        $payload = is_array($item['payload'] ?? null) ? $item['payload'] : [];

        if (($item['kind'] ?? null) === 'edge') {
            return ($payload['from_id'] ?? '?').' → '.($payload['to_id'] ?? '?');
        }

        return ($payload['state'] ?? 'node').' #'.($payload['id'] ?? '?');
    }

    // ── 概览 tab ──

    public function startUpgradeAction(): Action
    {
        return Action::make('startUpgrade')
            ->label('发起升级')
            ->color('primary')
            ->schema(function (): array {
                $options = $this->targetUpgradeOptions();

                if ($options === []) {
                    return [
                        Placeholder::make('target_upstream_none')
                            ->label('可升级的上游版本')
                            ->content('当前基线 '.config('cmf-area.data_version').' 与上游对比后无更高版本可升级'),
                    ];
                }

                return [
                    Select::make('target_upstream')
                        ->label('可升级的上游版本')
                        ->options($options)
                        ->searchable()
                        ->native(false)
                        ->required()
                        ->helperText('已自动比对当前基线与上游 Release，仅列出更高版本；发起时下载上游 csv 并 diff（可能需要几十秒）'),
                ];
            })
            ->action(function (array $data): void {
                $target = (string) ($data['target_upstream'] ?? '');
                if ($target === '' || ! array_key_exists($target, $this->targetUpgradeOptions())) {
                    Notification::make()->title('没有可升级的上游版本')->warning()->send();
                    $this->halt();
                }

                try {
                    $this->flow()->start($target);
                    $this->flushCaches();
                    Notification::make()->title('升级已发起，diff 完成')->success()->send();
                } catch (\Throwable $e) {
                    Notification::make()->title('发起升级失败')->body($e->getMessage())->danger()->persistent()->send();
                    $this->halt();
                }
            });
    }

    /**
     * 可升级的上游版本选项（value/label 均为 tag；新→旧）：仅列出严格高于当前基线
     * data_version（忽略 +N 自增后缀）的上游 Release。
     *
     * @return array<string, string>
     */
    public function targetUpgradeOptions(): array
    {
        $current = Str::before((string) config('cmf-area.data_version'), '+');

        return collect(app(UpstreamService::class)->versions())
            ->filter(fn (string $tag): bool => $tag > $current)
            ->mapWithKeys(fn (string $tag): array => [$tag => $tag])
            ->all();
    }

    public function rediff(): void
    {
        try {
            $this->flow()->rediff($this->stateData() ?? []);
            $this->flushCaches();
            Notification::make()->title('重新 diff 完成')->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('diff 失败')->body($e->getMessage())->danger()->send();
        }
    }

    public function resetUpgrade(): void
    {
        $this->workspace()->reset();
        $this->regionSelection = [];
        $this->flushCaches();
        $this->activeTab = 'overview';
        Notification::make()->title('工作区已清空，可重新发起升级')->success()->send();
    }

    // ── 地区选择 tab ──

    public function toggleRegion(int $id): void
    {
        $tree = $this->regionTreeData();
        $node = $this->findRegionNode($tree, $id);
        if ($node === null) {
            return;
        }

        // 最小可选到市级：县级（deep 2）行没有 checkbox，这里兜底忽略
        if ((int) ($node['deep'] ?? 0) > 1) {
            return;
        }

        $this->applyRegionCascade($node, ! in_array($id, $this->regionSelection, true));
        $this->syncParentRegionSelection($tree);
    }

    public function selectAllRegions(): void
    {
        $ids = [];
        $walk = function (array $nodes) use (&$walk, &$ids): void {
            foreach ($nodes as $node) {
                if ((int) ($node['deep'] ?? 0) <= 1) {
                    $ids[] = (int) $node['id'];
                }
                $walk(is_array($node['children'] ?? null) ? $node['children'] : []);
            }
        };
        $walk($this->regionTreeData());
        $this->regionSelection = $ids;
    }

    public function clearRegions(): void
    {
        $this->regionSelection = [];
    }

    private function findRegionNode(array $nodes, int $id): ?array
    {
        foreach ($nodes as $node) {
            if ((int) $node['id'] === $id) {
                return $node;
            }
            $found = $this->findRegionNode(is_array($node['children'] ?? null) ? $node['children'] : [], $id);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * 向下级联：勾选/取消节点时同步子树，但只到市级（deep 1）——县级不可选，
     * 市/省 id 本身已通过 isInRegions 子树语义覆盖其县事实。
     */
    private function applyRegionCascade(array $node, bool $checked): void
    {
        $id = (int) $node['id'];
        if ((int) ($node['deep'] ?? 0) <= 1) {
            if ($checked) {
                if (! in_array($id, $this->regionSelection, true)) {
                    $this->regionSelection[] = $id;
                }
            } else {
                $this->regionSelection = array_values(array_filter($this->regionSelection, fn (int $r): bool => $r !== $id));
            }
        }

        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            $this->applyRegionCascade($child, $checked);
        }
    }

    /**
     * 向上同步（后序）：父节点选中 ⇔ 直接子节点（市级及以上）全部选中。
     */
    private function syncParentRegionSelection(array $nodes): void
    {
        foreach ($nodes as $node) {
            $children = array_values(array_filter(
                is_array($node['children'] ?? null) ? $node['children'] : [],
                fn (array $c): bool => (int) ($c['deep'] ?? 0) <= 1,
            ));

            $this->syncParentRegionSelection(is_array($node['children'] ?? null) ? $node['children'] : []);

            if ($children === []) {
                continue;
            }

            $id = (int) $node['id'];
            $allChildrenSelected = true;
            foreach ($children as $child) {
                if (! in_array((int) $child['id'], $this->regionSelection, true)) {
                    $allChildrenSelected = false;

                    break;
                }
            }

            if ($allChildrenSelected && ! in_array($id, $this->regionSelection, true)) {
                $this->regionSelection[] = $id;
            } elseif (! $allChildrenSelected && in_array($id, $this->regionSelection, true)) {
                $this->regionSelection = array_values(array_filter($this->regionSelection, fn (int $r): bool => $r !== $id));
            }
        }
    }

    public function saveRegions(): void
    {
        try {
            $this->flow()->updateRegions($this->stateData() ?? [], $this->regionSelection);
            $this->flushCaches();
            if ($this->regionSelection === []) {
                Notification::make()->title('地区选择已清空，本次不处理任何地区')->warning()->send();
            } else {
                Notification::make()->title('地区选择已保存，覆盖率已按并集重算')->success()->send();
            }
        } catch (\Throwable $e) {
            Notification::make()->title('保存失败')->body($e->getMessage())->danger()->send();
        }
    }

    // ── 采集状态 tab ──

    /**
     * AI 判读（升级方案 §11-A）：后台拉起独立 artisan 进程（area:collect --run-agent），
     * 由 CollectAgentRunner 在工作区任务包目录拉起 pi 完成判读并机器校验回收；
     * 进程输出写入工作区 agent-run.log，页面轮询展示，无需队列 worker。
     */
    public function aiCollect(int $regionId): void
    {
        $runner = app(CollectAgentRunner::class);
        if (! $runner->available()) {
            Notification::make()->title('未配置 AI agent（cmf-area.upgrade.agent_command）')->danger()->send();

            return;
        }

        try {
            $runner->startBackground($this->stateData() ?? [], [$regionId]);
            $this->flushCaches();
            Notification::make()->title('AI 判读已启动')->body('输出实时刷新于下方「判读输出」面板。')->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('启动失败')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * 手动终止进行中的 AI 判读：整组灭杀 agent 进程树，
     * 采集中的地区标记「已终止」可重新发起；已落库待审核记录不动。
     */
    public function stopAiCollect(): void
    {
        $run = $this->stateData()['agent_run'] ?? null;
        $log = is_array($run) ? (string) ($run['log'] ?? '') : '';

        app(CollectAgentRunner::class)->terminate($this->stateData() ?? [], $log);
        $this->flushCaches();

        Notification::make()->title('已终止本次判读，可重新发起')->warning()->send();
    }

    /**
     * 判读输出面板数据：运行状态 + 展示文本（只含系统行与 AI 回复文本，
     * JSON 事件流的 thinking/工具调用等细节不展示）。
     *
     * @return array{started_at: string, regions: list<int>, running: bool, log_tail: string}|null
     */
    public function agentRunInfo(): ?array
    {
        $run = $this->stateData()['agent_run'] ?? null;
        if (! is_array($run)) {
            return null;
        }

        $runner = app(CollectAgentRunner::class);
        $log = is_string($run['log'] ?? null) ? $run['log'] : '';
        $raw = $log !== '' ? $runner->logTail($log) : '';

        return [
            'started_at' => (string) ($run['started_at'] ?? ''),
            'regions' => array_values(array_map('intval', (array) ($run['regions'] ?? []))),
            'running' => $runner->agentLocked($log) || $runner->agentProcessAlive($log),
            'log_tail' => $runner->extractDisplayText($raw),
        ];
    }

    public function agentAvailable(): bool
    {
        return app(CollectAgentRunner::class)->available();
    }

    /**
     * 采集状态行（顶层折叠后的选中地区）：父子同选（如直辖市 31 与 3101）只出顶层行，
     * 每行一次判读覆盖整棵子树，杜绝重叠范围重复判读及同省 pending 记录互相冲掉。
     *
     * @return list<array{id: int, name: string}>
     */
    public function collectRegionRows(): array
    {
        $state = $this->stateData();
        if ($state === null) {
            return [];
        }

        $top = array_flip($this->flow()->collectRegionIds($state));

        return array_values(array_filter(
            is_array($state['selected_regions'] ?? null) ? $state['selected_regions'] : [],
            fn (array $r): bool => isset($top[(int) ($r['id'] ?? 0)]),
        ));
    }

    public function isGenerated(): bool
    {
        return ($this->stateData()['status'] ?? null) === UpgradeWorkspace::STATUS_GENERATED;
    }

    /**
     * 生成门禁是否满足（概览/定稿共用同一判定）：已选地区，且范围内有事实并全部审定认领。
     */
    public function gatePassed(): bool
    {
        if ($this->regionSelection === []) {
            return false;
        }

        $coverage = $this->coverageData();

        return $coverage['total'] > 0 && $coverage['approved'] >= $coverage['total'];
    }

    // ── 审核 tab ──

    public function approveItem(int $itemId): void
    {
        $this->guardNotGenerated();
        $this->workspace()->updateItem($itemId, [
            'review_status' => UpgradeWorkspace::REVIEW_APPROVED,
            'review_note' => null,
        ]);
        $this->flushCaches();
    }

    /**
     * 批量通过（§6.4）：高置信下级边一键批过。
     */
    public function approveBulk(): void
    {
        $this->guardNotGenerated();
        $count = 0;
        foreach ($this->workspace()->items() as $item) {
            if (($item['review_status'] ?? null) === UpgradeWorkspace::REVIEW_PENDING
                && ($item['kind'] ?? null) === 'edge'
                && ($item['confidence'] ?? null) === 'high') {
                $this->workspace()->updateItem((int) $item['id'], ['review_status' => UpgradeWorkspace::REVIEW_APPROVED]);
                $count++;
            }
        }

        $this->flushCaches();
        Notification::make()->title("已批量通过 {$count} 条高置信边")->success()->send();
    }

    public function rejectItemAction(): Action
    {
        return Action::make('rejectItem')
            ->label('驳回')
            ->color('danger')
            ->schema([
                Textarea::make('note')
                    ->label('驳回备注（随地区回采集队列，喂给 AI 重做）')
                    ->required(),
            ])
            ->action(function (array $arguments, array $data): void {
                try {
                    $this->flow()->rejectItem($this->stateData() ?? [], (int) ($arguments['item'] ?? 0), (string) $data['note']);
                    $this->flushCaches();
                    Notification::make()->title('已驳回，地区已回采集队列')->warning()->send();
                } catch (\Throwable $e) {
                    Notification::make()->title('驳回失败')->body($e->getMessage())->danger()->send();
                }
            });
    }

    public function editItemAction(): Action
    {
        return Action::make('editItem')
            ->label('编辑')
            ->color('gray')
            ->fillForm(function (array $arguments): array {
                $item = $this->workspace()->findItem((int) ($arguments['item'] ?? 0));

                return $item !== null ? $this->payloadToForm(is_array($item['payload'] ?? null) ? $item['payload'] : []) : [];
            })
            ->schema(fn (array $arguments): array => $this->itemFormSchema($this->itemKind($arguments)))
            ->action(function (array $arguments, array $data): void {
                $this->guardNotGenerated();
                $item = $this->workspace()->findItem((int) ($arguments['item'] ?? 0));
                if ($item === null) {
                    return;
                }
                $state = $this->stateData() ?? [];
                $kind = (string) ($item['kind'] ?? 'node');
                [$payload, $evidence] = $this->formToPayload($data, $kind);

                $errors = $this->flow()->validateItemsWith($state, [$payload], (int) $item['id'], $evidence);
                if ($errors !== []) {
                    Notification::make()
                        ->title('校验未通过，未保存')
                        ->body(implode("\n", array_slice($errors, 0, 10)))
                        ->danger()
                        ->persistent()
                        ->send();
                    $this->halt();
                }

                $state = $this->flow()->mergeEvidence($state, $evidence);
                $meta = $this->flow()->deriveMeta($state, [$payload])[0] ?? [];
                $this->workspace()->updateItem((int) $item['id'], [
                    'payload' => $payload,
                    'change_type' => $meta['change_type'] ?? ($item['change_type'] ?? null),
                    'side' => $meta['side'] ?? ($item['side'] ?? null),
                    'confidence' => $data['confidence'] ?? null,
                    'region' => $meta['region'] ?? ($item['region'] ?? null),
                    'review_status' => UpgradeWorkspace::REVIEW_EDITED,
                ]);

                $this->flushCaches();
                Notification::make()->title('已保存（标 edited）')->success()->send();
            });
    }

    /**
     * 人工录入场景向导（docs/area-upgrade-manual-entry-wizard.md §3）：
     * 第一步选场景（业务语言卡片），第二步填详情（字段随场景显隐）；
     * 场景 → payload 的组装由 ManualScenarioService 完成，录入产物与 AI 产物
     * 同构同权（同走 validateItemsWith 校验、同落待审、同被审核）；
     * 「复杂情形」进入高级模式（原 node/edge 裸表单，专家兜底）。
     */
    public function manualItemAction(): Action
    {
        $cards = $this->scenarios()->cards();

        return Action::make('manualItem')
            ->label('人工录入')
            ->color('warning')
            ->modalWidth('3xl')
            ->modalSubmitActionLabel('录入')
            ->steps([
                WizardStep::make('发生了什么事')
                    ->schema([
                        Radio::make('scenario')
                            ->label('选择本次变化的场景')
                            ->options(array_map(fn (array $c): string => $c['label'], $cards))
                            ->descriptions(array_map(fn (array $c): string => $c['description'], $cards))
                            ->columns(2)
                            ->required(),
                    ]),
                WizardStep::make('填写详情')
                    ->schema($this->wizardDetailSchema()),
            ])
            ->action(function (array $data): void {
                $this->guardNotGenerated();
                $scenario = (string) ($data['scenario'] ?? '');
                $state = $this->stateData() ?? [];

                try {
                    if ($scenario === ManualScenarioService::SCENARIO_COMPLEX) {
                        $kind = (string) data_get($data, 'complex.complex_kind', 'node');
                        [$payload, $evidence] = $this->formToPayload((array) data_get($data, "complex.{$kind}", []), $kind);
                        $payloads = [$payload];
                        $feedback = $this->payloadFeedback($payload, $state);
                    } else {
                        [$oldMap, $newMap] = $this->flow()->maps($state);
                        ['payloads' => $payloads, 'evidence' => $evidence, 'feedback' => $feedback]
                            = $this->scenarios()->assemble($scenario, $data, $oldMap, $newMap);
                    }
                } catch (\Throwable $e) {
                    Notification::make()->title($e->getMessage())->danger()->persistent()->send();
                    $this->halt();
                }

                $errors = $this->flow()->validateItemsWith($state, $payloads, null, $evidence);
                if ($errors !== []) {
                    Notification::make()
                        ->title('校验未通过，未保存')
                        ->body(implode("\n", array_slice($errors, 0, 10)))
                        ->danger()
                        ->persistent()
                        ->send();
                    $this->halt();
                }

                $state = $this->flow()->mergeEvidence($state, $evidence);
                $metas = $this->flow()->deriveMeta($state, $payloads);
                foreach ($payloads as $i => $payload) {
                    $this->workspace()->addItem([
                        'kind' => ($payload['kind'] ?? null) === 'edge' ? 'edge' : 'node',
                        'payload' => $payload,
                        'change_type' => $metas[$i]['change_type'] ?? null,
                        'side' => $metas[$i]['side'] ?? null,
                        'confidence' => isset($payload['confidence']) && is_string($payload['confidence'])
                            ? $payload['confidence']
                            : ($data['confidence'] ?? null),
                        'review_status' => UpgradeWorkspace::REVIEW_PENDING,
                        'source' => UpgradeWorkspace::SOURCE_MANUAL,
                        'region' => $metas[$i]['region'] ?? null,
                    ]);
                }

                $this->flushCaches();
                Notification::make()->title($feedback)->success()->send();
            });
    }

    protected function scenarios(): ManualScenarioService
    {
        return app(ManualScenarioService::class);
    }

    /**
     * 向导第二步：字段随第一步所选场景显隐（隐藏字段不参与提交，不会填反侧向）。
     *
     * @return list<Component>
     */
    protected function wizardDetailSchema(): array
    {
        $business = ManualScenarioService::BUSINESS_SCENARIOS;
        $isScenario = fn (string ...$scenarios) => fn (Get $get): bool => in_array($get('scenario'), $scenarios, true);
        $isComplex = $isScenario(ManualScenarioService::SCENARIO_COMPLEX);

        return [
            $this->unitSelect('from_id', '原单位', 'old', ManualScenarioService::SCENARIO_MERGE)
                ->live()
                ->afterStateUpdated(fn (Set $set, Get $get) => $this->prefillMergeSummary($set, $get)),
            $this->unitSelect('to_id', '现单位', 'new', ManualScenarioService::SCENARIO_MERGE)
                ->live()
                ->afterStateUpdated(fn (Set $set, Get $get) => $this->prefillMergeSummary($set, $get)),
            TextInput::make('cross_level_reason')
                ->label('跨层原因（原单位与现单位层级不同时必填）')
                ->visible($isScenario(ManualScenarioService::SCENARIO_MERGE)),
            $this->unitSelect('new_id', '新设单位', 'new', ManualScenarioService::SCENARIO_APPEAR),
            $this->unitSelect('old_id', '被撤销单位', 'old_only', ManualScenarioService::SCENARIO_RETIRE),
            $this->unitSelect('rename_id', '发生变化的单位', 'both', ManualScenarioService::SCENARIO_RENAME),
            CheckboxList::make('rename_attributes')
                ->label('哪些信息发生了变化')
                ->options(['name' => '名称', 'ext_name' => '全称', 'pid' => '上级'])
                ->required()
                ->visible($isScenario(ManualScenarioService::SCENARIO_RENAME)),
            $this->unitSelect('reuse_id', '被复用的代码（新单位）', 'both', ManualScenarioService::SCENARIO_REUSE),
            Checkbox::make('scope_selected')
                ->label('仅显示「地区选择」中已勾选地区内的单位')
                ->live()
                ->visible(fn (Get $get): bool => $this->regionSelection !== [] && in_array($get('scenario'), $business, true)),
            Textarea::make('summary')
                ->label('变化说明')
                ->rows(2)
                ->placeholder('留空时由系统按单位名称自动生成')
                ->helperText(fn (Get $get): ?string => $get('scenario') === ManualScenarioService::SCENARIO_REUSE
                    ? '必填：写明哪个旧单位腾出了该代码、旧单位去向'
                    : null)
                ->required(fn (Get $get): bool => $get('scenario') === ManualScenarioService::SCENARIO_REUSE)
                ->visible($isScenario(...$business)),
            TextInput::make('notice_title')
                ->label('政府公告标题')
                ->required()
                ->visible($isScenario(...$business)),
            TextInput::make('notice_url')
                ->label('政府公告链接')
                ->url()
                ->required()
                ->visible($isScenario(...$business)),
            Section::make('高级选项')
                ->schema([
                    Repeater::make('exceptions')
                        ->label('例外下级（撤并：旧侧消失下级；撤销：本节点旧侧消失下级；须只在本侧存在）')
                        ->schema([
                            TextInput::make('id')->label('下级 id')->numeric()->required(),
                            TextInput::make('reason')->label('原因')->required(),
                        ])
                        ->columns(2)
                        ->defaultItems(0),
                ])
                ->collapsed()
                ->visible($isScenario(ManualScenarioService::SCENARIO_MERGE, ManualScenarioService::SCENARIO_RETIRE)),
            // 复杂情形（高级模式）：node/edge 两族字段各放进独立 statePath 容器。
            // 否则它们与业务场景字段（from_id/to_id/summary/exceptions 等）同键——
            // 同键组件会渲染出重复的 wire:partial 标记，Livewire 局部 morph 直接抛错
            // （表现为 repeater 的 Add 按钮点击无反应）。
            Group::make()
                ->statePath('complex')
                ->visible($isComplex)
                ->schema([
                    Radio::make('complex_kind')
                        ->label('记录类型')
                        ->options(['node' => '单位状态（node）', 'edge' => '对应关系（edge）'])
                        ->default('node')
                        ->live(),
                    Group::make()
                        ->statePath('node')
                        ->visible(fn (Get $get): bool => ($get('complex_kind') ?? 'node') === 'node')
                        ->schema($this->itemFormSchema('node')),
                    Group::make()
                        ->statePath('edge')
                        ->visible(fn (Get $get): bool => $get('complex_kind') === 'edge')
                        ->schema($this->itemFormSchema('edge')),
                ]),
        ];
    }

    /**
     * 单位选择器：搜索下拉，数据源 = 新旧 csv map（4 万行不全量预载，搜索时过滤），
     * 显示 ext_name（id）；可按「地区选择」已勾选地区过滤。
     */
    protected function unitSelect(string $name, string $label, string $pool, string $scenario): Select
    {
        return Select::make($name)
            ->label($label)
            ->searchable()
            ->native(false)
            ->getSearchResultsUsing(fn (Get $get, string $search): array => $this->unitOptions($pool, $search, (bool) $get('scope_selected')))
            ->getOptionLabelUsing(fn (mixed $value): ?string => $this->unitLabel($pool, $value))
            ->required()
            ->visible(fn (Get $get): bool => $get('scenario') === $scenario);
    }

    /**
     * @return array<int, string>
     */
    protected function unitOptions(string $pool, string $search, bool $scopeSelected): array
    {
        $state = $this->stateData();
        if ($state === null) {
            return [];
        }
        [$oldMap, $newMap] = $this->flow()->maps($state);
        $map = match ($pool) {
            'old', 'old_only' => $oldMap,
            'new' => $newMap,
            default => array_intersect_key($newMap, $oldMap),
        };
        $regionIds = $scopeSelected ? $this->regionSelection : [];
        $search = trim($search);

        $options = [];
        foreach ($map as $id => $row) {
            if ($pool === 'old_only' && isset($newMap[$id])) {
                continue;
            }
            if ($regionIds !== [] && ! app(DiffService::class)->isInRegions((int) $id, $regionIds, $oldMap, $newMap)) {
                continue;
            }
            $label = (string) ($row['ext_name'] ?? $row['name'] ?? $id);
            if ($search !== ''
                && ! str_contains((string) $id, $search)
                && ! str_contains($label, $search)
                && ! str_contains((string) ($row['name'] ?? ''), $search)) {
                continue;
            }
            $options[(int) $id] = "{$label}（{$id}）";
            if (count($options) >= 50) {
                break;
            }
        }

        return $options;
    }

    protected function unitLabel(string $pool, mixed $value): ?string
    {
        $id = (int) $value;
        $state = $this->stateData();
        if ($id === 0 || $state === null) {
            return null;
        }
        [$oldMap, $newMap] = $this->flow()->maps($state);
        // 侧向由场景固定：old_only（撤销）要求旧有新无，both（改名/复用）要求两版均在，
        // 选项与回显标签同口径，填反侧时选择器视为无效值
        $row = match ($pool) {
            'old' => $oldMap[$id] ?? null,
            'old_only' => isset($newMap[$id]) ? null : ($oldMap[$id] ?? null),
            'new' => $newMap[$id] ?? null,
            default => isset($oldMap[$id], $newMap[$id]) ? $newMap[$id] : null,
        };
        if ($row === null) {
            return null;
        }
        $label = (string) ($row['ext_name'] ?? $row['name'] ?? $id);

        return "{$label}（{$id}）";
    }

    /**
     * merge 场景预填变化说明（"撤{旧名}设{新名}"，可改）：仅在用户未填写时回填。
     */
    protected function prefillMergeSummary(Set $set, Get $get): void
    {
        if (filled($get('summary'))) {
            return;
        }
        $fromId = (int) $get('from_id');
        $toId = (int) $get('to_id');
        $state = $this->stateData();
        if ($fromId === 0 || $toId === 0 || $state === null) {
            return;
        }
        [$oldMap, $newMap] = $this->flow()->maps($state);
        $fromName = $oldMap[$fromId]['ext_name'] ?? $oldMap[$fromId]['name'] ?? null;
        $toName = $newMap[$toId]['ext_name'] ?? $newMap[$toId]['name'] ?? null;
        if ($fromName === null || $toName === null) {
            return;
        }
        $set('summary', "撤{$fromName}设{$toName}");
    }

    /**
     * 高级模式反馈也说业务话（不说"edge 已保存"）。
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $state
     */
    protected function payloadFeedback(array $payload, array $state): string
    {
        try {
            [$oldMap, $newMap] = $this->flow()->maps($state);
        } catch (\Throwable) {
            return '已录入';
        }
        $name = fn (array $map, int $id): string => (string) ($map[$id]['ext_name'] ?? $map[$id]['name'] ?? $id);

        if (($payload['kind'] ?? null) === 'edge') {
            return '已录入：'.$name($oldMap, (int) ($payload['from_id'] ?? 0)).' → '.$name($newMap, (int) ($payload['to_id'] ?? 0));
        }

        $itemState = (string) ($payload['state'] ?? '');
        $stateLabel = [
            ChangesGraph::STATE_APPEARED => '新设',
            ChangesGraph::STATE_RETIRED => '撤销',
            ChangesGraph::STATE_CONTINUED => '变更',
        ][$itemState] ?? '单位状态';
        $map = $itemState === ChangesGraph::STATE_APPEARED ? $newMap : $oldMap;

        return "已录入：{$stateLabel} ".$name($map, (int) ($payload['id'] ?? 0));
    }

    // ── 定稿 tab ──

    public function finalize(): void
    {
        if ($this->regionSelection === []) {
            Notification::make()->title('请先在「地区选择」勾选本次升级的地区范围')->danger()->send();

            return;
        }

        if (! $this->gatePassed()) {
            Notification::make()->title('生成门禁未通过：范围内事实未全部审定认领')->danger()->send();

            return;
        }

        try {
            [, $result] = app(UpgradeFinalizeService::class)->finalize($this->stateData() ?? []);
            $this->flushCaches();
            Notification::make()
                ->title("定稿完成：{$result['assigned_version']}")
                ->body(($result['aligned'] ? '已自动对齐上游版本号。' : '部分地区发版（自有版本号）。').'PR 清单见定稿 tab，请人工接管 git。')
                ->success()
                ->persistent()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()->title('定稿失败')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    // ── 表单组装（人工录入器 / 编辑器共用，§6.4）──

    /** @return list<\Filament\Schemas\Components\Component> */
    protected function itemFormSchema(string $kind): array
    {
        $common = [
            Select::make('confidence')
                ->label('置信度')
                ->options(['high' => 'high', 'low' => 'low（进重点队列）'])
                ->default('high')
                ->required(),
            Textarea::make('summary')->label('判定摘要')->rows(3),
            Repeater::make('new_evidence')
                ->label('新增证据（写入工作区证据池；引用 id 自动生成）')
                ->schema([
                    TextInput::make('title')->label('标题')->required(),
                    TextInput::make('url')->label('URL')->url()->required(),
                ])
                ->columns(2)
                ->defaultItems(0),
            TagsInput::make('evidence_keys')
                ->label('证据引用 id 清单（含上方新增条目生成后回填的 id；node 至少一条）')
                ->placeholder('回车添加'),
        ];

        if ($kind === 'edge') {
            return [
                TextInput::make('from_id')->label('from_id（旧版 id）')->numeric()->required(),
                TextInput::make('to_id')->label('to_id（新版 id）')->numeric()->required(),
                TextInput::make('cross_level_reason')->label('跨层原因（两端 deep 不同必填）'),
                ...$common,
            ];
        }

        return [
            TextInput::make('id')->label('单位 id')->numeric()->required(),
            Radio::make('state')
                ->label('存续状态')
                ->options([
                    ChangesGraph::STATE_APPEARED => 'appeared（新设/复用，新侧）',
                    ChangesGraph::STATE_RETIRED => 'retired（撤销，旧侧）',
                    ChangesGraph::STATE_CONTINUED => 'continued（存续，属性变化）',
                ])
                ->required(),
            Select::make('attributes')
                ->label('属性变化字段（continued 必填）')
                ->multiple()
                ->options(array_combine(ChangesGraph::ATTRIBUTE_KEYS, ChangesGraph::ATTRIBUTE_KEYS)),
            Toggle::make('id_reuse')->label('id 复用（appeared 且同 id 换单位时开启；summary 须写明复用对应关系）'),
            Repeater::make('exceptions')
                ->label('例外下级（只挂本节点同侧子树）')
                ->schema([
                    TextInput::make('id')->label('id')->numeric()->required(),
                    TextInput::make('reason')->label('原因')->required(),
                ])
                ->columns(2)
                ->defaultItems(0),
            ...$common,
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, array{title: string, url: string}>}
     */
    protected function formToPayload(array $data, string $kind): array
    {
        $evidence = [];
        $evidenceKeys = array_values(array_filter(array_map('strval', $data['evidence_keys'] ?? [])));
        foreach (($data['new_evidence'] ?? []) as $entry) {
            if (! is_array($entry) || empty($entry['title']) || empty($entry['url'])) {
                continue;
            }
            $key = 'manual_'.substr(sha1($entry['url'].microtime()), 0, 8);
            $evidence[$key] = ['title' => (string) $entry['title'], 'url' => (string) $entry['url']];
            $evidenceKeys[] = $key;
        }

        if ($kind === 'edge') {
            $payload = array_filter([
                'kind' => 'edge',
                'from_id' => (int) $data['from_id'],
                'to_id' => (int) $data['to_id'],
                'cross_level_reason' => filled($data['cross_level_reason'] ?? null) ? (string) $data['cross_level_reason'] : null,
                'summary' => filled($data['summary'] ?? null) ? (string) $data['summary'] : null,
                'evidence' => $evidenceKeys !== [] ? $evidenceKeys : null,
                'confidence' => (string) ($data['confidence'] ?? 'high'),
            ], fn (mixed $v): bool => $v !== null);

            return [$payload, $evidence];
        }

        $payload = array_filter([
            'kind' => 'node',
            'id' => (int) $data['id'],
            'state' => (string) $data['state'],
            'attributes' => ! empty($data['attributes']) ? array_values($data['attributes']) : null,
            'id_reuse' => ($data['id_reuse'] ?? false) ? true : null,
            'exceptions' => ! empty($data['exceptions'])
                ? array_values(array_map(fn (array $e): array => ['id' => (int) $e['id'], 'reason' => (string) $e['reason']], $data['exceptions']))
                : null,
            'summary' => filled($data['summary'] ?? null) ? (string) $data['summary'] : null,
            'evidence' => $evidenceKeys !== [] ? $evidenceKeys : null,
            'confidence' => (string) ($data['confidence'] ?? 'high'),
        ], fn (mixed $v): bool => $v !== null);

        return [$payload, $evidence];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function payloadToForm(array $payload): array
    {
        return [
            'id' => $payload['id'] ?? null,
            'state' => $payload['state'] ?? null,
            'attributes' => $payload['attributes'] ?? [],
            'id_reuse' => $payload['id_reuse'] ?? false,
            'exceptions' => $payload['exceptions'] ?? [],
            'from_id' => $payload['from_id'] ?? null,
            'to_id' => $payload['to_id'] ?? null,
            'cross_level_reason' => $payload['cross_level_reason'] ?? null,
            'summary' => $payload['summary'] ?? null,
            'confidence' => $payload['confidence'] ?? 'high',
            'evidence_keys' => $payload['evidence'] ?? [],
            'new_evidence' => [],
        ];
    }

    /** @param  array<string, mixed>  $arguments */
    protected function itemKind(array $arguments): string
    {
        $item = $this->workspace()->findItem((int) ($arguments['item'] ?? 0));

        return is_array($item) ? (string) ($item['kind'] ?? 'node') : 'node';
    }

    protected function guardNotGenerated(): void
    {
        if ($this->isGenerated()) {
            Notification::make()->title('本次升级已定稿，不能再修改')->danger()->send();
            $this->halt();
        }
    }

    protected function flushCaches(): void
    {
        $this->stateLoaded = false;
        $this->stateCache = null;
        $this->regionTreeCache = null;
        $this->coverageCache = null;
    }
}
