<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Filament\Resources\Area\Pages;

use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Quansitech\Cmf\Area\Filament\Resources\Area\AreaResource;
use Quansitech\Cmf\Area\Models\Area;

class ViewArea extends ViewRecord
{
    protected static string $resource = AreaResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('id')->label('代码'),
            TextEntry::make('ext_name')->label('完整名'),
            TextEntry::make('name')->label('精简名'),
            TextEntry::make('deep')->label('层级')
                ->formatStateUsing(fn (int $state): string => ['省级', '市级', '区县级', '乡镇级'][$state] ?? (string) $state),
            TextEntry::make('parent.ext_name')->label('上级地区')->placeholder('-'),
            TextEntry::make('ext_id')->label('原始编号'),
            TextEntry::make('pinyin')->label('拼音'),
            TextEntry::make('status')->label('状态')
                ->formatStateUsing(fn (int $state): string => $state === 1 ? '正常' : '已撤销'),
            TextEntry::make('successor.ext_name')->label('承继地区')->placeholder('-'),
            TextEntry::make('full_path')->label('全路径')
                ->state(fn (Area $record): string => $record->fullName())
                ->columnSpanFull(),
        ]);
    }
}
