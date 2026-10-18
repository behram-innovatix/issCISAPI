<?php

use App\Http\Controllers\Api\ConnectorController;
use App\Http\Controllers\Api\DataSourceController;
use App\Http\Controllers\Api\DataSourceFileController;
use App\Http\Controllers\Api\IngestController;
use App\Http\Controllers\Api\InsightsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/*
| Connection management for CIS administrators. Called server-to-server by the CIS web app with a
| token that has the `connections:manage` ability (php artisan cis:token).
*/
Route::prefix('v1/admin')
    ->middleware(['auth:sanctum', 'abilities:connections:manage'])
    ->name('admin.')
    ->group(function () {
        Route::get('connectors', [ConnectorController::class, 'index'])->name('connectors.index');
        Route::apiResource('data-sources', DataSourceController::class);
        Route::post('data-sources/{data_source}/test', [DataSourceController::class, 'test'])->name('data-sources.test');
        Route::post('data-sources/{data_source}/sync', [DataSourceController::class, 'sync'])->name('data-sources.sync');
        Route::get('data-sources/{data_source}/runs', [DataSourceController::class, 'runs'])->name('data-sources.runs');
        Route::get('data-sources/{data_source}/files', [DataSourceFileController::class, 'index'])->name('data-sources.files.index');
        Route::post('data-sources/{data_source}/files', [DataSourceFileController::class, 'store'])->name('data-sources.files.store');
        Route::delete('data-sources/{data_source}/files/{stream}', [DataSourceFileController::class, 'destroy'])->name('data-sources.files.destroy');
    });

/*
| Dashboard datasets for the CIS web app (token ability `insights:read`).
*/
Route::get('v1/insights/{platform}', [InsightsController::class, 'show'])
    ->middleware(['auth:sanctum', 'abilities:insights:read'])
    ->name('insights.show');

/*
| Records pushed by push-ingest sources, authenticated by an HMAC signature per source.
*/
Route::post('v1/ingest/{dataSource}/{stream}', [IngestController::class, 'store'])
    ->middleware('throttle:120,1')
    ->name('ingest.store');
