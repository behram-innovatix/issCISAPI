<?php

use App\Http\Controllers\Api\ConnectorController;
use App\Http\Controllers\Api\DataSourceController;
use App\Http\Controllers\Api\DataSourceFileController;
use App\Http\Controllers\Api\IngestController;
use App\Http\Controllers\Api\InsightsController;
use App\Http\Controllers\Api\SourceTableController;
use App\Http\Controllers\Api\StreamModelController;
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

        // database sources: the tables to copy, and how each dashboard stream is built from the copies
        Route::get('data-sources/{data_source}/tables', [SourceTableController::class, 'index'])->name('data-sources.tables.index');
        Route::get('data-sources/{data_source}/tables/{table}', [SourceTableController::class, 'show'])->where('table', '[^/]+')->name('data-sources.tables.show');
        Route::post('data-sources/{data_source}/tables/{table}/preview', [SourceTableController::class, 'preview'])->where('table', '[^/]+')->name('data-sources.tables.preview');
        Route::put('data-sources/{data_source}/tables/{table}', [SourceTableController::class, 'update'])->where('table', '[^/]+')->name('data-sources.tables.update');
        Route::delete('data-sources/{data_source}/tables/{table}', [SourceTableController::class, 'destroy'])->where('table', '[^/]+')->name('data-sources.tables.destroy');
        Route::get('data-sources/{data_source}/streams', [StreamModelController::class, 'index'])->name('data-sources.streams.index');
        Route::put('data-sources/{data_source}/streams/{stream}', [StreamModelController::class, 'update'])->name('data-sources.streams.update');
        Route::post('data-sources/{data_source}/streams/{stream}/preview', [StreamModelController::class, 'preview'])->name('data-sources.streams.preview');
        Route::delete('data-sources/{data_source}/streams/{stream}', [StreamModelController::class, 'destroy'])->name('data-sources.streams.destroy');
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
