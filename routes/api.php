<?php

use App\Http\Controllers\Api\V1\BuildArticlesController;
use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');

Route::get('/v1/build/articles', BuildArticlesController::class)->name('v1.build.articles');
