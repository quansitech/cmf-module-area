@php
    /** @var array<string, mixed> $node */
    /** @var int $level */
    $checked = in_array($node['id'], $this->regionSelection, true);
    $selectable = $level <= 1; // 最小可选到市级（deep 1），县级仅展示
    $hasChildren = $node['children'] !== [];
    // 部分选中（仅省级可能：市级的下级是县、县级不可选）：自身未选但有可选下级被选中
    $partial = ! $checked && $hasChildren && collect($node['children'])->contains(
        fn (array $c): bool => in_array((int) $c['id'], $this->regionSelection, true),
    );
    $kindLabel = [
        'added' => '增', 'removed' => '撤', 'renamed' => '更名',
        'parent_changed' => '换父', 'code_reuse_suspected' => '复用?',
    ];
@endphp

{{--
  展开/收起用原生 <details>（点击行切换、零请求），与勾选完全解耦：
  点勾选方块 = wire:click 勾选（.prevent 阻止 details 折叠）；点行其余位置 = 折叠/展开。
  wire:ignore.self：open 状态由用户控制，Livewire morph 不覆盖。
--}}
@if ($hasChildren)
    <details class="au-node" wire:ignore.self @if ($checked || $partial) open @endif>
        <summary @class(['au-node-row', 'is-root' => $level === 0, 'is-checked' => $checked, 'is-partial' => $partial])>
            <span class="au-chevron"></span>
            @if ($selectable)
                <span
                    @class(['au-cb', 'is-checked' => $checked, 'is-partial' => $partial])
                    wire:click.prevent="toggleRegion({{ $node['id'] }})"
                    role="checkbox"
                    aria-checked="{{ $partial ? 'mixed' : ($checked ? 'true' : 'false') }}"
                ></span>
            @endif
            <span class="au-node-name" title="{{ $node['name'] }}">{{ $node['name'] }}</span>
            <span class="au-node-meta">
                <span class="au-node-total">{{ $node['total'] }} 条</span>
                @foreach ($node['kinds'] as $kind => $count)
                    <span class="au-chip">{{ $kindLabel[$kind] ?? $kind }} {{ $count }}</span>
                @endforeach
            </span>
        </summary>
        <div class="au-children">
            @foreach ($node['children'] as $child)
                @include('cmf-area::filament.pages.partials.region-node', ['node' => $child, 'level' => $level + 1])
            @endforeach
        </div>
    </details>
@else
    <div class="au-node">
        <div @class(['au-node-row', 'is-leaf', 'is-checked' => $checked])>
            <span class="au-chevron-space"></span>
            @if ($selectable)
                <span
                    @class(['au-cb', 'is-checked' => $checked])
                    wire:click.prevent="toggleRegion({{ $node['id'] }})"
                    role="checkbox"
                    aria-checked="{{ $checked ? 'true' : 'false' }}"
                ></span>
            @else
                <span class="au-node-box"></span>
            @endif
            <span class="au-node-name" title="{{ $node['name'] }}">{{ $node['name'] }}</span>
            <span class="au-node-meta">
                <span class="au-node-total">{{ $node['total'] }} 条</span>
                @foreach ($node['kinds'] as $kind => $count)
                    <span class="au-chip">{{ $kindLabel[$kind] ?? $kind }} {{ $count }}</span>
                @endforeach
            </span>
        </div>
    </div>
@endif
