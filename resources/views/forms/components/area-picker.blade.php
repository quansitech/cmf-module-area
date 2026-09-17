@php
    /** @var \Quansitech\Cmf\Area\Filament\Forms\Components\AreaPicker $field */
    $statePath = $getStatePath();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        class="cmf-area-picker"
        data-cmf-area-picker
        x-data
        wire:ignore
        x-init="window.cmfAreaPickerInit && window.cmfAreaPickerInit($el)"
        data-state-path="{{ $statePath }}"
        data-children-url="{{ route('cmf-area.children', ['id' => '__ID__']) }}"
        data-root-url="{{ route('cmf-area.children') }}"
        data-path-url="{{ route('cmf-area.path', ['id' => '__ID__']) }}"
    >
        <div class="flex flex-wrap items-center gap-2" data-cmf-area-selects></div>
        <div class="mt-1 text-xs text-gray-500" data-cmf-area-current></div>
    </div>
</x-dynamic-component>
