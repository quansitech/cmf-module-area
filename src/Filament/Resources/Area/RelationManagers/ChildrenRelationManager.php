<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Filament\Resources\Area\RelationManagers;

use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * 下级地区列表：查看页内逐级下钻（树形浏览）。
 */
class ChildrenRelationManager extends RelationManager
{
    protected static string $relationship = 'children';

    protected static ?string $title = '下级地区';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('ext_name')
            ->columns([
                TextColumn::make('id')->label('代码'),
                TextColumn::make('ext_name')->label('名称')->searchable(),
                TextColumn::make('deep')->label('层级')
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => ['省级', '市级', '区县级', '乡镇级'][$state] ?? (string) $state),
                TextColumn::make('status')->label('状态')
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => $state === 1 ? '正常' : '已撤销')
                    ->color(fn (int $state): string => $state === 1 ? 'success' : 'danger'),
            ])
            ->recordActions([ViewAction::make()]);
    }
}
