<?php

use App\Http\Controllers\Api\DefinitionController;
use App\Http\Controllers\Api\EcuFileController;
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
    Route::post('{file}/versions', 'saveVersion')->name('versions.store');
    Route::get('{file}/versions/{version}/download', 'downloadVersion')->name('versions.download');
    Route::get('{file}/versions/{version}/raw', 'versionRaw')->name('versions.raw');
    Route::delete('{file}/versions/{version}', 'destroyVersion')->name('versions.destroy');
    Route::post('{file}/xdf', 'importXdf')->name('xdf.import');
    Route::get('{file}/xdf', 'exportXdf')->name('xdf.export');
});

Route::get('definitions', [DefinitionController::class, 'index'])->name('definitions.index');
Route::delete('definitions/{definition}', [DefinitionController::class, 'destroy'])->name('definitions.destroy');
