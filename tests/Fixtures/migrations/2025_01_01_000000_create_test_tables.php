<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->timestamps();
        });

        // 历史事实型：keep + 名称快照
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->unsignedBigInteger('region_id')->nullable();
            $table->string('region_name')->nullable();
            $table->timestamps();
        });

        // 当前状态型：remap
        Schema::create('stores', function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->unsignedBigInteger('area_id')->nullable();
            $table->timestamps();
        });

        // 引用列类型为 varchar：sync 应拒绝登记
        Schema::create('bad_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->string('region_id')->nullable();
            $table->timestamps();
        });
    }
};
