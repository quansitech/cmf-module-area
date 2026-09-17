<?php

declare(strict_types=1);

use Livewire\Livewire;
use Quansitech\Cmf\Area\Exceptions\AreaReferenceNotRegisteredException;
use Quansitech\Cmf\Area\Tests\Fixtures\Biz\Models\Order;
use Quansitech\Cmf\Area\Tests\Fixtures\Biz\Models\UndeclaredOrder;
use Quansitech\Cmf\Area\Tests\Fixtures\Livewire\PickerForm;

beforeEach(function (): void {
    migrateAreaSchema();
    createArea(['id' => 11, 'pid' => 0, 'deep' => 0, 'name' => '北京', 'ext_name' => '北京市', 'ext_id' => 110000000000]);
    createArea(['id' => 1101, 'pid' => 11, 'deep' => 1, 'name' => '北京', 'ext_name' => '北京市', 'ext_id' => 110100000000]);
    createArea(['id' => 110101, 'pid' => 1101, 'deep' => 2, 'name' => '东城', 'ext_name' => '东城区', 'ext_id' => 110101000000]);
});

it('Cast：未声明字段赋值时抛 AreaReferenceNotRegisteredException', function (): void {
    UndeclaredOrder::create(['title' => 'x', 'region_id' => 110101]);
})->throws(AreaReferenceNotRegisteredException::class, '未声明地区引用');

it('Cast：已声明字段正常写入', function (): void {
    $order = Order::create(['title' => 'x', 'region_id' => 110101]);

    expect($order->region_id)->toBe(110101);
});

it('Cast：写空值不触发校验', function (): void {
    $order = UndeclaredOrder::create(['title' => 'x', 'region_id' => null]);

    expect($order->region_id)->toBeNull();
});

it('Picker：已声明字段的表单正常渲染', function (): void {
    actingAsTestUser();

    Livewire::test(PickerForm::class)->assertOk();
});

it('Picker：未声明字段构建时抛异常（含可粘贴模板）', function (): void {
    actingAsTestUser();

    try {
        Livewire::test(PickerForm::class, ['modelClass' => UndeclaredOrder::class]);
        $this->fail('应抛出 AreaReferenceNotRegisteredException');
    } catch (Throwable $e) {
        // Livewire 测试渲染会把组件异常包进 ViewException，解开断言根因
        $root = $e;
        while ($root->getPrevious() !== null) {
            $root = $root->getPrevious();
        }
        expect($root)->toBeInstanceOf(AreaReferenceNotRegisteredException::class)
            ->and($root->getMessage())->toContain('请在 UndeclaredOrder 模型中添加');
    }
});

it('Picker：选中后名称快照写入完整路径（逐级 ext_name 组合）', function (): void {
    actingAsTestUser();

    Livewire::test(PickerForm::class)
        ->set('data.region_id', 110101)
        ->assertSet('data.region_name', '北京市 北京市 东城区');
});
