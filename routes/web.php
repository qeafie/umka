<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\HouseAccessController;
use App\Http\Controllers\HouseInvitationController;
use App\Http\Controllers\HousingIncidentController;
use App\Http\Controllers\HousingRequestDraftController;
use App\Http\Controllers\IncidentWorkflowController;
use App\Http\Controllers\MaxSessionController;
use App\Http\Controllers\MeterController;
use App\Http\Controllers\MeterReadingController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::post('/appeals/preview', HousingRequestDraftController::class)->name('appeals.preview');
Route::post('/auth/max', MaxSessionController::class)->middleware('throttle:10,1')->name('auth.max');
Route::middleware('auth')->group(function () {
    Route::get('/admin/houses/{house}/invitations', [HouseInvitationController::class, 'index'])->whereNumber('house')->name('invitations.index');
    Route::post('/admin/houses/{house}/invitations', [HouseInvitationController::class, 'store'])->middleware('throttle:20,1')->whereNumber('house')->name('invitations.store');
    Route::delete('/admin/houses/{house}/invitations/{invitation}', [HouseInvitationController::class, 'destroy'])->whereNumber(['house', 'invitation'])->name('invitations.destroy');
    Route::post('/invitations/preview', [HouseInvitationController::class, 'preview'])->middleware('throttle:20,1')->name('invitations.preview');
    Route::post('/invitations/accept', [HouseInvitationController::class, 'accept'])->middleware('throttle:20,1')->name('invitations.accept');
    Route::put('/admin/houses/{house}/layout', [HouseAccessController::class, 'updateLayout'])
        ->whereNumber('house')->name('admin.houses.layout.update');
    Route::put('/admin/houses/{house}/members/{member}/location', [HouseAccessController::class, 'updateLocation'])
        ->whereNumber(['house', 'member'])->name('admin.houses.members.location.update');
    Route::get('/admin/houses/{house}/members', [HouseAccessController::class, 'index'])
        ->whereNumber('house')->name('admin.houses.members.index');
    Route::put('/admin/houses/{house}/members/{member}', [HouseAccessController::class, 'update'])
        ->whereNumber(['house', 'member'])->name('admin.houses.members.update');
    Route::delete('/admin/houses/{house}/members/{member}', [HouseAccessController::class, 'destroy'])
        ->whereNumber(['house', 'member'])->name('admin.houses.members.destroy');
    Route::get('/my/houses', [HousingIncidentController::class, 'houses'])->name('houses.mine');
    Route::get('/houses/{house}/incidents', [HousingIncidentController::class, 'index'])
        ->whereNumber('house')
        ->name('houses.incidents.index');
    Route::post('/houses/{house}/incidents', [HousingIncidentController::class, 'store'])
        ->whereNumber('house')
        ->name('houses.incidents.store');
    Route::patch('/houses/{house}/incidents/{incident}', [IncidentWorkflowController::class, 'update'])
        ->whereNumber(['house', 'incident'])
        ->name('houses.incidents.update');
    Route::post('/houses/{house}/incidents/{incident}/responses', [IncidentWorkflowController::class, 'respond'])
        ->whereNumber(['house', 'incident'])
        ->name('houses.incidents.responses.store');
    Route::get('/meters', [MeterController::class, 'index'])->name('meters.index');
    Route::post('/meters', [MeterController::class, 'store'])->name('meters.store');
    Route::post('/meters/{meterId}/readings', [MeterReadingController::class, 'store'])
        ->whereNumber('meterId')
        ->name('meters.readings.store');
});
