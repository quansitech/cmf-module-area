@php
    /** @var array<string, mixed> $item */
    $payload = is_array($item['payload'] ?? null) ? $item['payload'] : [];
    $evidencePool = is_array($this->stateData()['evidence_pool'] ?? null) ? $this->stateData()['evidence_pool'] : [];
    $isPending = ($item['review_status'] ?? null) === \Quansitech\Cmf\Area\Services\UpgradeWorkspace::REVIEW_PENDING;
    $generated = $this->isGenerated();
@endphp

<div class="au-card">
    <div class="au-card-head">
        <x-filament::badge color="gray">{{ ($item['kind'] ?? null) === 'edge' ? 'edge' : 'node' }}</x-filament::badge>
        @if (! empty($item['change_type']))
            <x-filament::badge color="info">{{ $item['change_type'] }}</x-filament::badge>
        @endif
        <span class="au-mono">{{ $this->itemLabel($item) }}</span>
        @if (($item['confidence'] ?? null) === 'low')
            <x-filament::badge color="danger">low</x-filament::badge>
        @endif
        @if (($payload['id_reuse'] ?? false) === true)
            <x-filament::badge color="warning">id_reuse</x-filament::badge>
        @endif
        <x-filament::badge :color="$statusColor[$item['review_status'] ?? ''] ?? 'gray'">{{ $statusLabel[$item['review_status'] ?? ''] ?? ($item['review_status'] ?? '-') }}</x-filament::badge>
        @if (($item['source'] ?? null) === 'manual')
            <x-filament::badge color="gray">人工</x-filament::badge>
        @endif

        <span class="au-spacer"></span>

        @if ($isPending && ! $generated)
            <x-filament::button size="xs" color="success" wire:click="approveItem({{ (int) $item['id'] }})">通过</x-filament::button>
            {{ ($this->rejectItemAction)(['item' => (int) $item['id']]) }}
            {{ ($this->editItemAction)(['item' => (int) $item['id']]) }}
        @elseif (! $generated && ($item['review_status'] ?? null) !== 'rejected')
            {{ ($this->editItemAction)(['item' => (int) $item['id']]) }}
        @endif
    </div>

    @if (! empty($payload['summary']))
        <div class="au-card-sub">{{ $payload['summary'] }}</div>
    @endif
    @if (! empty($payload['exceptions']))
        <div class="au-card-meta">
            例外：
            @foreach ($payload['exceptions'] as $ex)
                #{{ $ex['id'] ?? '?' }}（{{ $ex['reason'] ?? '' }}）@if (! $loop->last)；@endif
            @endforeach
        </div>
    @endif
    @if (! empty($payload['evidence']))
        <div class="au-card-meta">
            @foreach ($payload['evidence'] as $ref)
                @if (isset($evidencePool[$ref]))
                    <a href="{{ $evidencePool[$ref]['url'] }}" target="_blank" class="au-link">{{ $evidencePool[$ref]['title'] }}</a>
                @else
                    <span class="au-danger">证据缺失：{{ $ref }}</span>
                @endif
                @if (! $loop->last)&nbsp;&nbsp;@endif
            @endforeach
        </div>
    @endif
    @if (! empty($item['review_note']))
        <div class="au-card-meta au-warning">审核备注：{{ $item['review_note'] }}</div>
    @endif
</div>
