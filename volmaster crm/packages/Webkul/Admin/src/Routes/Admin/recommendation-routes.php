<?php

use Illuminate\Support\Facades\Route;
use Webkul\Admin\Http\Controllers\RecommendationController;

Route::controller(RecommendationController::class)->prefix('recommendations')->group(function () {
    Route::get('', 'index')->name('admin.recommendations.index');
});
