<?php

use App\Http\Controllers\Api\DefinitionController;
use App\Http\Controllers\Api\DiffController;
use App\Http\Controllers\Api\EcuFileController;
use App\Http\Controllers\Api\TuningController;
use Illuminate\Support\Facades\Route;

Route::controller(EcuFileController::class)->prefix('files')->name('files.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store');
    Route::get('{file}', 'show')->name('show');
    Route::patch('{file}', 'update')->name('update');
    Route::delete('{file}', 'destroy')->name('destroy');
    Route::post('{file}/reanalyze', 'reanalyze')->name('reanalyze');
    Route::get('{file}/raw', 'raw')->name('raw');
    Route::post('{file}/checksum', 'checksum')->name('checksum');
    Route::post('{file}/dyno', 'dyno')->name('dyno');
    Route::post('{file}/versions', 'saveVersion')->name('versions.store');
    Route::get('{file}/versions/{version}/download', 'downloadVersion')->name('versions.download');
    Route::get('{file}/versions/{version}/raw', 'versionRaw')->name('versions.raw');
    Route::delete('{file}/versions/{version}', 'destroyVersion')->name('versions.destroy');
    Route::post('{file}/xdf', 'importXdf')->name('xdf.import');
    Route::get('{file}/xdf', 'exportXdf')->name('xdf.export');
});

Route::post('files/{file}/diff', DiffController::class)->name('files.diff');

Route::controller(TuningController::class)->prefix('files/{file}/stage1')->name('files.stage1')->group(function () {
    Route::post('/', 'stage1');
    Route::post('explain', 'explain')->name('.explain');
});

Route::get('definitions', [DefinitionController::class, 'index'])->name('definitions.index');
Route::delete('definitions/{definition}', [DefinitionController::class, 'destroy'])->name('definitions.destroy');
