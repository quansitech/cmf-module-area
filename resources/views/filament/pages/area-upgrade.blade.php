@php
    /** @var \Quansitech\Cmf\Area\Filament\Pages\AreaUpgradePage $this */
    $state = $this->stateData();
    $generated = $this->isGenerated();
    $tabs = [
        'overview' => '概览',
        'regions' => '地区选择',
        'collect' => '采集状态',
        'review' => '审核',
        'finalize' => '定稿',
    ];
    $statusLabel = [
        'pending' => '待审核', 'approved' => '已通过', 'rejected' => '已驳回',
        'edited' => '已编辑', 'manual' => '人工录入',
    ];
    $statusColor = [
        'pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger',
        'edited' => 'info', 'manual' => 'gray',
    ];
@endphp

<x-filament-panels::page>
    {{-- 自包含样式：Filament 预编译 CSS 只含 fi-* 组件类，本页自定义的 Tailwind 工具类不生效，
         故用 au-* 语义类 + Filament 主题 CSS 变量（自动适配暗色） --}}
    <style>
        .au-tabs { display: flex; flex-wrap: wrap; gap: .25rem; border-bottom: 1px solid var(--gray-200); margin-bottom: 1rem; }
        .au-tab { padding: .5rem .9rem; font-size: .875rem; font-weight: 500; color: var(--gray-500); background: none; border: 0; border-bottom: 2px solid transparent; cursor: pointer; margin-bottom: -1px; }
        .au-tab:hover { color: var(--gray-700); }
        .au-tab.is-active { color: var(--primary-600); border-bottom-color: var(--primary-500); }

        .au-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; font-size: .875rem; }
        @media (min-width: 768px) { .au-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
        .au-label { color: var(--gray-500); font-size: .75rem; margin-bottom: .2rem; }
        .au-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        .au-log .au-log-think { color: var(--gray-400); font-style: italic; }
        .au-log .au-log-text { color: inherit; }
        .au-log .au-log-tool { color: var(--primary-600); font-weight: 600; }
        .au-log .au-log-ok { color: var(--success-600); }
        .au-log .au-log-err { color: var(--danger-600); }
        .au-log .au-log-sys { color: var(--gray-500); }
        .au-muted { color: var(--gray-500); }
        .au-sub { color: var(--gray-600); font-size: .875rem; }
        .au-danger { color: var(--danger-600); }
        .au-success { color: var(--success-600); }
        .au-warning { color: var(--warning-600); }
        .au-link { color: var(--primary-600); text-decoration: underline; }
        .au-hint { font-size: .875rem; color: var(--gray-500); }

        .au-stack > * + * { margin-top: .5rem; }
        .au-actions { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; margin-top: 1rem; }

        .au-table-wrap { overflow-x: auto; }
        .au-table { width: 100%; font-size: .875rem; border-collapse: collapse; }
        .au-table th { text-align: left; font-weight: 600; font-size: .75rem; color: var(--gray-500); padding: .55rem .75rem; background: var(--gray-50); border-bottom: 1px solid var(--gray-200); white-space: nowrap; }
        .au-table td { padding: .5rem .75rem; border-bottom: 1px solid var(--gray-100); vertical-align: top; }
        .au-table tbody tr:hover td { background: var(--gray-50); }
        .au-table .is-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .au-table .is-w { min-width: 7rem; word-break: break-all; }

        .au-card { border: 1px solid var(--gray-200); border-radius: .75rem; padding: .75rem 1rem; font-size: .875rem; }
        .au-card-head { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
        .au-card-head .au-spacer { flex: 1 1 auto; min-width: .5rem; }
        .au-card-sub { margin-top: .4rem; color: var(--gray-600); }
        .au-card-meta { margin-top: .3rem; font-size: .75rem; color: var(--gray-500); }

        .au-cover { display: grid; grid-template-columns: minmax(7rem, 10rem) minmax(0, 1fr) auto; gap: .75rem; align-items: center; font-size: .875rem; padding: .3rem 0; }
        .au-cover-name { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .au-bar { height: .5rem; background: var(--gray-100); border-radius: 999px; overflow: hidden; }
        .au-bar > i { display: block; height: 100%; background: var(--success-500); border-radius: 999px; }
        .au-cover-stat { color: var(--gray-500); font-size: .8125rem; white-space: nowrap; font-variant-numeric: tabular-nums; }

        .au-tree { font-size: .875rem; }
        .au-node-row { display: flex; align-items: center; gap: .6rem; padding: .35rem .6rem; border-radius: .5rem; cursor: pointer; user-select: none; }
        .au-node-row:hover { background: var(--gray-50); }
        summary.au-node-row { list-style: none; }
        summary.au-node-row::-webkit-details-marker { display: none; }
        .au-chevron { width: 0; height: 0; flex: none; border-left: 5px solid var(--gray-400); border-top: 4px solid transparent; border-bottom: 4px solid transparent; transition: transform .15s; }
        details[open] > summary > .au-chevron { transform: rotate(90deg); }
        .au-chevron-space { width: 5px; flex: none; }
        .au-cb { width: 1rem; height: 1rem; flex: none; display: inline-flex; align-items: center; justify-content: center; border: 1.5px solid var(--gray-400); border-radius: .25rem; background: transparent; cursor: pointer; }
        .au-cb.is-checked { background: var(--primary-500); border-color: var(--primary-500); }
        .au-cb.is-checked::after { content: ''; width: .55rem; height: .3rem; border-left: 2px solid #fff; border-bottom: 2px solid #fff; transform: rotate(-45deg) translate(0.5px, -1px); }
        .au-cb.is-partial { background: var(--primary-500); border-color: var(--primary-500); }
        .au-cb.is-partial::after { content: ''; width: .5rem; height: 2px; background: #fff; }
        .au-node-row.is-root .au-node-name { font-weight: 600; }
        .au-node-row.is-checked { background: color-mix(in srgb, var(--primary-500) 10%, transparent); }
        .au-node-row.is-checked:hover { background: color-mix(in srgb, var(--primary-500) 16%, transparent); }
        .au-node-row.is-partial { background: color-mix(in srgb, var(--primary-500) 4%, transparent); }
        .au-node-row.is-leaf { cursor: default; }
        .au-node-box { width: 1rem; height: 1rem; margin: 0; flex: none; }
        .au-children { margin-left: 1.5rem; }
        .au-tree-busy { pointer-events: none; opacity: .7; }
        .au-node-name { flex: 0 1 auto; min-width: 0; }
        .au-node-meta { margin-left: auto; display: flex; align-items: center; gap: .3rem; flex-wrap: wrap; justify-content: flex-end; }
        .au-node-total { font-size: .75rem; color: var(--gray-500); white-space: nowrap; font-variant-numeric: tabular-nums; }
        .au-chip { display: inline-flex; align-items: center; font-size: .6875rem; line-height: 1; padding: .2rem .45rem; border-radius: 999px; background: var(--gray-100); color: var(--gray-600); white-space: nowrap; }

        .au-list { margin: 0; padding-left: 1.25rem; list-style: disc; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .75rem; line-height: 1.7; word-break: break-all; }

        .au-stat { font-size: 1.125rem; font-weight: 700; }
    </style>

    @if ($state === null)
        <x-filament::section heading="发起升级">
            <p class="au-hint" style="margin-bottom: 1rem;">
                尚无进行中的升级工作区。发起升级会下载指定上游版本的 csv 并与当前基线 diff，
                全流程（地区选择 → AI 判读 → 审核 → 定稿）在本页面一次完成，不持久化批次。
            </p>
            {{ $this->startUpgradeAction }}
        </x-filament::section>
    @else
        {{-- 标签页 --}}
        <div class="au-tabs">
            @foreach ($tabs as $key => $label)
                <button
                    type="button"
                    wire:click="$set('activeTab', '{{ $key }}')"
                    @class(['au-tab', 'is-active' => $activeTab === $key])
                >{{ $label }}</button>
            @endforeach
        </div>

        {{-- ═══ 概览 ═══ --}}
        @if ($activeTab === 'overview')
            <x-filament::section heading="版本信息">
                <div class="au-grid">
                    <div>
                        <div class="au-label">基线版本（from）</div>
                        <div class="au-mono">{{ $state['from_version'] ?? '-' }}</div>
                    </div>
                    <div>
                        <div class="au-label">对比的上游版本</div>
                        <div class="au-mono">{{ $state['target_upstream'] ?? '-' }}</div>
                    </div>
                    <div>
                        <div class="au-label">定稿版本</div>
                        <div class="au-mono">{{ $state['assigned_version'] ?? '未定稿' }}</div>
                    </div>
                    <div>
                        <div class="au-label">生成门禁（范围内全审定）</div>
                        @if ($this->regionSelection === [])
                            <div class="au-stat au-muted" style="font-size: .875rem;">未选地区</div>
                        @else
                            <div class="au-stat {{ $this->gatePassed() ? 'au-success' : 'au-danger' }}">
                                {{ $this->gatePassed() ? '通过' : '未通过' }}
                            </div>
                        @endif
                    </div>
                    <div>
                        <div class="au-label">状态</div>
                        <div class="au-mono">{{ $state['status'] ?? '-' }}</div>
                    </div>
                </div>
                <div class="au-actions" style="margin-top: 1.25rem;">
                    <x-filament::button color="gray" wire:click="rediff" wire:loading.attr="disabled" :disabled="$generated">
                        重新 diff
                    </x-filament::button>
                    <x-filament::button
                        color="danger"
                        wire:click="resetUpgrade"
                        wire:confirm="确认清空工作区？未落盘的判读记录与采集状态都会丢失（已定稿产物不受影响）。"
                    >清空工作区重新发起</x-filament::button>
                </div>
            </x-filament::section>

            <x-filament::section heading="diff 分组摘要（基线 vs 上游的事实清单）">
                <div class="au-table-wrap">
                    <table class="au-table">
                        <thead>
                            <tr>
                                <th>地区</th>
                                <th class="is-num">新增</th>
                                <th class="is-num">删除</th>
                                <th class="is-num">更名</th>
                                <th class="is-num">换父</th>
                                <th class="is-num">疑似代码重用</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse (($state['diff_summary'] ?? []) as $province => $counts)
                                <tr>
                                    <td>{{ $province }}</td>
                                    <td class="is-num">{{ $counts['added'] }}</td>
                                    <td class="is-num">{{ $counts['removed'] }}</td>
                                    <td class="is-num">{{ $counts['renamed'] }}</td>
                                    <td class="is-num">{{ $counts['parent_changed'] }}</td>
                                    <td class="is-num {{ $counts['code_reuse_suspected'] > 0 ? 'au-danger' : '' }}" @style(['font-weight: 700' => $counts['code_reuse_suspected'] > 0])>{{ $counts['code_reuse_suspected'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="au-muted">无 diff 事实</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="au-actions">
                    <x-filament::button color="primary" wire:click="$set('activeTab', 'regions')">
                        前往地区选择 →
                    </x-filament::button>
                    <span class="au-hint">在"地区选择"勾选本次要升级的地区后保存</span>
                </div>
            </x-filament::section>
        @endif

        {{-- ═══ 地区选择 ═══ --}}
        @if ($activeTab === 'regions')
            <x-filament::section heading="选择本次处理的地区（省 / 市两级；最小可选到市级，县级事实已归并进上级计数）">
                <div class="au-actions" style="margin-bottom: 0.75rem;">
                    <x-filament::button wire:click="saveRegions" :disabled="$generated">保存地区选择</x-filament::button>
                    <x-filament::button size="sm" color="gray" wire:click="selectAllRegions" :disabled="$generated">全选本次有变更的地区</x-filament::button>
                    <x-filament::button size="sm" color="gray" wire:click="clearRegions" :disabled="$generated">清空选择</x-filament::button>
                    <span class="au-hint">已选 {{ count($regionSelection) }} 个地区</span>
                </div>
                @if ($regionSelection === [])
                    <p class="au-hint" style="margin-bottom: 0.75rem; color: var(--warning-500);">尚未选择任何地区——采集与定稿都会按"未选地区"被拒绝。</p>
                @endif
                {{-- 请求进行中禁用整棵树，避免连点产生基于过期快照的并发 toggle --}}
                <div class="au-tree au-stack" wire:loading.class="au-tree-busy" wire:target="toggleRegion">
                    @forelse ($this->regionTreeData() as $node)
                        @include('cmf-area::filament.pages.partials.region-node', ['node' => $node, 'level' => 0])
                    @empty
                        <p class="au-hint">没有可展示的地区（diff 为空或未发起升级）。</p>
                    @endforelse
                </div>
            </x-filament::section>
        @endif

        {{-- ═══ 采集状态 ═══ --}}
        @if ($activeTab === 'collect')
            <div wire:poll.10s>
                <x-filament::section heading="AI 判读（本地命令回收：php artisan area:collect --ingest=...）">
                    @if ($this->agentAvailable())
                        <div style="display: flex; align-items: center; gap: .5rem; margin-bottom: .75rem; flex-wrap: wrap;">
                            <label class="au-label" style="margin: 0;" for="au-agent-model">判读模型</label>
                            <select id="au-agent-model" wire:model.live="agentModel"
                                    style="font-size: .8125rem; padding: .3rem .5rem; border: 1px solid rgba(0,0,0,.15); border-radius: .375rem; background: transparent; max-width: 22rem;">
                                <option value="">默认（config/env 或 pi 自身配置）</option>
                                @foreach ($this->agentModelOptions() as $value => $label)
                                    <option value="{{ $value }}" @selected($agentModel === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <span class="au-muted" style="font-size: .75rem;">选中后拉起 pi 时显式 --model 指定</span>
                        </div>
                    @endif
                    @php $collectState = is_array($state['collection_state'] ?? null) ? $state['collection_state'] : []; @endphp
                    <div class="au-table-wrap">
                        <table class="au-table">
                            <thead>
                                <tr>
                                    <th>地区</th>
                                    <th>状态</th>
                                    <th class="is-num">重试</th>
                                    <th>最近错误 / 反馈</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($this->collectRegionRows() as $region)
                                    @php
                                        $s = $collectState[(string) $region['id']] ?? [];
                                        $sStatus = $s['status'] ?? 'pending';
                                        $sLabel = ['pending' => '待采集', 'collecting' => '采集中', 'passed' => '校验通过', 'failed' => '转人工', 'rejected' => '已驳回待重做', 'terminated' => '已终止'][$sStatus] ?? $sStatus;
                                    @endphp
                                    <tr>
                                        <td class="is-w">{{ $region['name'] }}（{{ $region['id'] }}）</td>
                                        <td>{{ $sLabel }}</td>
                                        <td class="is-num">{{ $s['retries'] ?? 0 }}</td>
                                        <td class="is-w">
                                            @if (! empty($s['feedback']))
                                                <div class="au-warning">反馈：{{ $s['feedback'] }}</div>
                                            @endif
                                            @foreach (array_slice($s['last_errors'] ?? [], 0, 3) as $err)
                                                <div class="au-danger" style="font-size: .75rem;">{{ $err }}</div>
                                            @endforeach
                                        </td>
                                        <td style="text-align: right; white-space: nowrap;">
                                            @if (! $generated && $this->agentAvailable())
                                                <x-filament::button size="xs" wire:click="aiCollect({{ $region['id'] }})" wire:loading.attr="disabled">
                                                    {{ $sStatus === 'passed' ? '重新判读' : 'AI 判读' }}
                                                </x-filament::button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="au-muted">尚未选择地区（先到"地区选择"tab 勾选）</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>

                @php $agentRun = $this->agentRunInfo(); @endphp
                @if ($agentRun !== null)
                <x-filament::section :heading="$agentRun['running'] ? '判读输出（进行中…）' : '判读输出（已结束）'">
                    @if ($agentRun['started_at'] !== '')
                        <p class="au-muted" style="font-size: .75rem;">启动于 {{ $agentRun['started_at'] }}</p>
                    @endif
                    @if ($agentRun['running'] && ! $generated)
                        <div style="margin-bottom: .5rem;">
                            <x-filament::button size="xs" color="danger" wire:click="stopAiCollect"
                                                wire:confirm="确认终止本次判读？agent 进程会被整组灭杀，采集中的地区标记为已终止。">
                                终止判读
                            </x-filament::button>
                        </div>
                    @endif
                        <pre class="au-mono au-log" style="max-height: 18rem; overflow: auto; white-space: pre-wrap; word-break: break-all; font-size: .75rem; margin: 0;">@if ($agentRun['log_tail'] !== ''){!! $agentRun['log_tail'] !!}@else（暂无输出，agent 启动中…）@endif</pre>
                        @if (! $agentRun['running'])
                            <p class="au-muted" style="font-size: .75rem;">判读进程已结束，结果见「审核」tab；任何地区都可再次发起判读。</p>
                        @endif
                    </x-filament::section>
                @endif
            </div>
        @endif

        {{-- ═══ 审核 ═══ --}}
        @if ($activeTab === 'review')
            @php $coverage = $this->coverageData(); @endphp
            <x-filament::section heading="覆盖率仪表盘（审定口径 / 含未审）">
                @if ($this->regionSelection === [])
                    <p class="au-muted">尚未选择升级地区——请先在「地区选择」勾选本次升级的范围，覆盖率与生成门禁按选中范围统计。</p>
                @else
                <div class="au-grid">
                    <div>
                        <div class="au-label">范围内事实</div>
                        <div class="au-stat">{{ $coverage['total'] }}</div>
                    </div>
                    <div>
                        <div class="au-label">已认领</div>
                        <div class="au-stat">{{ $coverage['claimed'] }}</div>
                    </div>
                    <div>
                        <div class="au-label">已审定认领</div>
                        <div class="au-stat au-success">{{ $coverage['approved'] }}</div>
                    </div>
                    <div>
                        <div class="au-label">生成门禁</div>
                        <div class="au-stat {{ $this->gatePassed() ? 'au-success' : 'au-danger' }}">
                            {{ $this->gatePassed() ? '通过' : '未通过' }}
                        </div>
                    </div>
                </div>
                <div class="au-stack" style="margin-top: 1rem;">
                    @foreach ($coverage['regions'] as $province => $stat)
                        <div class="au-cover">
                            <span class="au-cover-name" title="{{ $province }}">{{ $province }}</span>
                            <div class="au-bar">
                                <i style="width: {{ $stat['total'] > 0 ? (int) ($stat['approved'] * 100 / $stat['total']) : 0 }}%"></i>
                            </div>
                            <span class="au-cover-stat">{{ $stat['approved'] }}/{{ $stat['total'] }}（含未审 {{ $stat['claimed'] }}）</span>
                        </div>
                    @endforeach
                </div>
                @if (! $generated)
                    <div class="au-actions">
                        {{ $this->manualItemAction }}
                        <x-filament::button color="gray" wire:click="approveBulk">批量通过高置信边</x-filament::button>
                    </div>
                @endif
                @endif
            </x-filament::section>

            @php $focusItems = $this->focusItemsData(); @endphp
            @if (count($focusItems) > 0)
                <x-filament::section heading="重点队列（低置信 / id 复用 / 疑似代码重用）" icon="heroicon-o-exclamation-triangle">
                    <div class="au-stack">
                        @foreach ($focusItems as $item)
                            @include('cmf-area::filament.pages.partials.item-card', ['item' => $item, 'statusLabel' => $statusLabel, 'statusColor' => $statusColor])
                        @endforeach
                    </div>
                </x-filament::section>
            @endif

            @foreach ($this->itemsByRegionData() as $region => $items)
                <x-filament::section :heading="$region">
                    <div class="au-stack">
                        @foreach ($items as $item)
                            @include('cmf-area::filament.pages.partials.item-card', ['item' => $item, 'statusLabel' => $statusLabel, 'statusColor' => $statusColor])
                        @endforeach
                    </div>
                </x-filament::section>
            @endforeach

            @if (count($this->itemsData()) === 0)
                <x-filament::section>
                    <p class="au-hint">暂无判读记录。到"采集状态"tab 发起 AI 判读，也可用"人工录入"直接补录。</p>
                </x-filament::section>
            @endif
        @endif

        {{-- ═══ 定稿 ═══ --}}
        @if ($activeTab === 'finalize')
            @php $coverage = $this->coverageData(); @endphp
            <x-filament::section heading="生成门禁与定稿（升级方案 §6.5）">
                @if ($generated)
                    <div class="au-stack" style="font-size: .875rem;">
                        <p>已定稿：版本号 <span class="au-mono" style="font-weight: 700;">{{ $state['assigned_version'] ?? '-' }}</span>
                            （{{ ($state['finalize_result']['aligned'] ?? false) ? '已自动对齐上游' : '部分地区发版（自有版本号）' }}）</p>
                        <p class="au-muted">PR 清单（请人工接管 git 提交）：</p>
                        <ul class="au-list">
                            @foreach (($state['finalize_result']['files'] ?? []) as $file)
                                <li>{{ $file }}</li>
                            @endforeach
                        </ul>
                    </div>
                @else
                    <div class="au-stack" style="font-size: .875rem;">
                        <p>定稿动作（一键）：合并审定记录 → scoped 校验 → 试算补丁基线 → 全量 diff 定版本号 → 生成迁移 → 基线补丁落盘 → 自证校验。</p>
                        @if ($this->regionSelection === [])
                            <p class="au-danger">尚未选择升级地区——请先在「地区选择」勾选本次升级的范围后再定稿。</p>
                        @else
                        <p>门禁 = 选中范围 100% 覆盖（审定口径 approved/edited/manual；pending/rejected 不计入）：当前 {{ $coverage['approved'] }} / {{ $coverage['total'] }}。</p>
                        @if ($coverage['uncovered_final'] !== [])
                            <div class="au-danger" style="font-size: .75rem;">
                                <div class="au-stack">
                                    @foreach (array_slice($coverage['uncovered_final'], 0, 10) as $fact)
                                        <div>未认领：{{ $fact['label'] }}</div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        @endif
                        <x-filament::button
                            color="success"
                            wire:click="finalize"
                            wire:confirm="确认定稿？将改写基线 csv、config 版本号并生成迁移文件。"
                            :disabled="! $this->gatePassed()"
                        >一键定稿</x-filament::button>
                        <p class="au-muted" style="font-size: .75rem;">未选中地区的事实留作待办，下次发起升级时由 diff 自然重现。</p>
                    </div>
                @endif
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
