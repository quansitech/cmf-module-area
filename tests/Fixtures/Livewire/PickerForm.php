<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Tests\Fixtures\Livewire;

use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Livewire\Component;
use Quansitech\Cmf\Area\Filament\Forms\Components\AreaPicker;
use Quansitech\Cmf\Area\Tests\Fixtures\Biz\Models\Order;

/**
 * 测试用表单组件：绑定模型的 AreaPicker 表单。
 */
class PickerForm extends Component implements HasForms
{
    use InteractsWithForms;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var class-string */
    public string $modelClass = Order::class;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                AreaPicker::make('region_id')->withNameSnapshot('region_name'),
            ])
            ->statePath('data')
            ->model($this->modelClass);
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
