<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Filament\Resources\Area;

use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Override;
use Quansitech\Cmf\Area\Filament\Resources\Area\Pages\ListAreas;
use Quansitech\Cmf\Area\Filament\Resources\Area\Pages\ViewArea;
use Quansitech\Cmf\Area\Filament\Resources\Area\RelationManagers\ChildrenRelationManager;
use Quansitech\Cmf\Area\Models\Area;
use UnitEnum;

/**
 * 区划数据浏览：默认列出省级，逐级点入查看下级（树形浏览）。
 * 只读 Resource（数据由迁移维护，后台不提供编辑）。
 */
class AreaResource extends Resource
{
    protected static ?string $model = Area::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $recordTitleAttribute = 'ext_name';

    protected static ?string $modelLabel = '行政区划';

    protected static ?string $pluralModelLabel = '行政区划';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('filament-shield::filament-shield.nav.group') === 'filament-shield::filament-shield.nav.group'
            ? '系统'
            : __('filament-shield::filament-shield.nav.group');
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('ext_name')
            ->columns(static::getTableColumns())
            ->filters(static::getTableFilters())
            ->recordActions([ViewAction::make()]);
    }

    /**
     * @return list<Column>
     */
    protected static function getTableColumns(): array
    {
        return [
            TextColumn::make('id')->label('代码')->sortable(),
            TextColumn::make('ext_name')->label('名称')->searchable(),
            TextColumn::make('name')->label('精简名')->searchable(),
            TextColumn::make('deep')->label('层级')
                ->badge()
                ->formatStateUsing(fn (int $state): string => ['省级', '市级', '区县级', '乡镇级'][$state] ?? (string) $state)
                ->color(fn (int $state): string => ['gray', 'info', 'success', 'warning'][$state] ?? 'gray'),
            TextColumn::make('ext_id')->label('原始编号')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('pinyin')->label('拼音')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('status')->label('状态')
                ->badge()
                ->formatStateUsing(fn (int $state): string => $state === 1 ? '正常' : '已撤销')
                ->color(fn (int $state): string => $state === 1 ? 'success' : 'danger'),
            TextColumn::make('successor.ext_name')->label('承继地区')->placeholder('-'),
        ];
    }

    /**
     * @return list<SelectFilter|TernaryFilter>
     */
    protected static function getTableFilters(): array
    {
        return [
            SelectFilter::make('deep')
                ->label('层级')
                ->options([0 => '省级', 1 => '市级', 2 => '区县级', 3 => '乡镇级'])
                // 默认只展示省级；用户改选其他层级即覆盖（筛选器默认值，可被覆盖）
                ->default(0)
                ->query(fn (Builder $query, array $data): Builder => $query->where('deep', (int) $data['value'])),
            TernaryFilter::make('status')
                ->label('状态')
                ->trueLabel('正常')
                ->falseLabel('已撤销')
                ->queries(
                    true: fn (Builder $query): Builder => $query->where('status', 1),
                    false: fn (Builder $query): Builder => $query->where('status', 0),
                ),
        ];
    }

    #[Override]
    public static function getRelations(): array
    {
        return [
            ChildrenRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAreas::route('/'),
            'view' => ViewArea::route('/{record}'),
        ];
    }

    /**
     * 全局搜索可直接命中任意层级（覆盖默认的 deep=0 限制）。
     */
    #[Override]
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->withoutGlobalScopes()->where('status', 1);
    }
}
