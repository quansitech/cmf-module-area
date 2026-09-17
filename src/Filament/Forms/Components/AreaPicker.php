<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Filament\Forms\Components;

use Closure;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;
use Quansitech\Cmf\Area\Exceptions\AreaReferenceNotRegisteredException;
use Quansitech\Cmf\Area\Models\Area;
use Quansitech\Cmf\Area\Services\ReferenceCollector;

/**
 * 行政区划级联选择组件：省 → 市 → 区 → 镇，任意层级可选中。
 *
 *  - 注册强制校验（第 1 层）：字段未在目标模型 areaReferences() 声明时
 *    抛 AreaReferenceNotRegisteredException（含可粘贴的声明模板）；
 *  - 名称快照：.withNameSnapshot('receiver_area_name') 选中后自动把完整路径
 *    （逐级 ext_name 组合，如「湖北省 武汉市 江岸区」）写入快照列
 *    （历史事实型字段推荐"代码 + 名称快照"双写）；
 *  - 只可选中 status=1 的正常地区（撤销行对新数据不可见）。
 *
 * 字段 state 为地区 id（int|null）。
 */
class AreaPicker extends Field
{
    protected string $view = 'cmf-area::forms.components.area-picker';

    protected string|Closure|null $snapshotColumn = null;

    protected function setUp(): void
    {
        parent::setUp();

        // state 归一化为 int|null
        $this->dehydrateStateUsing(fn (mixed $state): ?int => filled($state) ? (int) $state : null);

        // 构建（回填）时强制校验注册声明
        $this->afterStateHydrated(fn () => $this->assertRegistered());

        // 保存（脱水）时兜底再校验一次（覆盖回填后模型上下文变化的场景）
        $this->beforeStateDehydrated(fn () => $this->assertRegistered());

        // 名称快照双写：选中后自动把当前 ext_name 写入快照列
        $this->afterStateUpdated(function (mixed $state, Set $set): void {
            $snapshotColumn = $this->getSnapshotColumn();
            if ($snapshotColumn === null) {
                return;
            }

            $set($snapshotColumn, $this->snapshotNameOf($state));
        });

        // 录入校验：只允许 status=1 的正常地区
        $this->rule(
            fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_numeric($value)) {
                    $fail('请选择有效的行政区划。');

                    return;
                }

                $area = Area::query()->find((int) $value);
                if ($area === null || $area->status !== 1) {
                    $fail('所选行政区划不存在或已撤销。');
                }
            },
        );
    }

    /**
     * 名称快照列（如 receiver_area_name）。
     */
    public function withNameSnapshot(string|Closure|null $column): static
    {
        $this->snapshotColumn = $column;

        return $this;
    }

    public function getSnapshotColumn(): ?string
    {
        $column = $this->evaluate($this->snapshotColumn);

        return $column === null ? null : (string) $column;
    }

    /**
     * 注册强制校验：字段未在目标模型的 areaReferences() 声明时抛异常。
     * 无模型上下文（如独立表单 / 筛选器）时跳过——无法确定归属表。
     */
    public function assertRegistered(): void
    {
        $model = $this->getModel();

        if ($model === null || ! is_string($model) || ! class_exists($model)) {
            return;
        }

        if (! app(ReferenceCollector::class)->isDeclared($model, $this->getName())) {
            /** @var Model $instance */
            $instance = new $model;
            throw AreaReferenceNotRegisteredException::forField($model, $instance->getTable(), $this->getName());
        }
    }

    /**
     * 选中值的快照名称：完整路径，逐级 ext_name 组合
     * （如「湖北省 武汉市 江岸区」；直辖市会保留同名层级，如「北京市 北京市 东城区」）。
     */
    protected function snapshotNameOf(mixed $state): ?string
    {
        if (! is_numeric($state)) {
            return null;
        }

        return Area::query()->find((int) $state)?->fullName();
    }
}
