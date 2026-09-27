<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\HousingIncidentController;
use App\Http\Controllers\HousingRequestDraftController;
use App\Http\Controllers\MaxSessionController;
use App\Http\Controllers\MeterController;
use App\Http\Controllers\MeterReadingController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::post('/appeals/preview', HousingRequestDraftController::class)->name('appeals.preview');
Route::post('/auth/max', MaxSessionController::class)->middleware('throttle:10,1')->name('auth.max');
Route::middleware('auth')->group(function () {
    Route::get('/my/houses', [HousingIncidentController::class, 'houses'])->name('houses.mine');
    Route::get('/houses/{house}/incidents', [HousingIncidentController::class, 'index'])
        ->whereNumber('house')
        ->name('houses.incidents.index');
    Route::post('/houses/{house}/incidents', [HousingIncidentController::class, 'store'])
        ->whereNumber('house')
        ->name('houses.incidents.store');
    Route::get('/meters', [MeterController::class, 'index'])->name('meters.index');
    Route::post('/meters', [MeterController::class, 'store'])->name('meters.store');
    Route::post('/meters/{meterId}/readings', [MeterReadingController::class, 'store'])
        ->whereNumber('meterId')
        ->name('meters.readings.store');
});
