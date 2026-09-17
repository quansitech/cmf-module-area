<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Quansitech\Cmf\Area\Http\Controllers\CmfAreaController;

Route::prefix(config('cmf-area.route_prefix', 'cmf-area'))
    ->middleware(config('cmf-area.middleware', ['web', 'auth']))
    ->group(function (): void {
        Route::get('children/{id?}', [CmfAreaController::class, 'children'])->name('cmf-area.children');
        Route::get('path/{id}', [CmfAreaController::class, 'path'])->name('cmf-area.path');
    });
