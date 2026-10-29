<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DefinitionController;
use App\Http\Controllers\Api\DiffController;
use App\Http\Controllers\Api\EcuFileController;
use App\Http\Controllers\Api\TuningController;
use Illuminate\Support\Facades\Route;

Route::controller(AuthController::class)->prefix('auth')->name('auth.')->group(function () {
    Route::get('/', 'show')->name('show');
    Route::post('login', 'login')->middleware('throttle:login')->name('login');
    Route::post('register', 'register')->middleware('throttle:register')->name('register');
    Route::post('logout', 'logout')->name('logout');
});

Route::middleware('auth')->group(function () {
    Route::get('files', [EcuFileController::class, 'index'])->name('files.index');
    Route::post('files', [EcuFileController::class, 'store'])->name('files.store');

    // everything about a file is owner-only (see EcuFilePolicy)
    Route::middleware('can:view,file')->group(function () {
        Route::controller(EcuFileController::class)->prefix('files/{file}')->name('files.')->group(function () {
            Route::get('/', 'show')->name('show');
            Route::patch('/', 'update')->name('update');
            Route::delete('/', 'destroy')->name('destroy');
            Route::post('reanalyze', 'reanalyze')->name('reanalyze');
            Route::get('raw', 'raw')->name('raw');
            Route::post('checksum', 'checksum')->name('checksum');
            Route::post('dyno', 'dyno')->name('dyno');
            Route::post('versions', 'saveVersion')->name('versions.store');
            Route::get('versions/{version}/download', 'downloadVersion')->name('versions.download');
            Route::get('versions/{version}/raw', 'versionRaw')->name('versions.raw');
            Route::delete('versions/{version}', 'destroyVersion')->name('versions.destroy');
            Route::post('xdf', 'importXdf')->name('xdf.import');
            Route::get('xdf', 'exportXdf')->name('xdf.export');
        });

        Route::post('files/{file}/diff', DiffController::class)->name('files.diff');

        Route::controller(TuningController::class)->prefix('files/{file}/stage1')->name('files.stage1')->group(function () {
            Route::post('/', 'stage1');
            Route::post('explain', 'explain')->name('.explain');
        });
    });

    Route::get('definitions', [DefinitionController::class, 'index'])->name('definitions.index');
    Route::delete('definitions/{definition}', [DefinitionController::class, 'destroy'])->middleware('can:delete,definition')->name('definitions.destroy');
});
