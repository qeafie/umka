<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\HousingRequestDraftController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::post('/appeals/preview', HousingRequestDraftController::class)->name('appeals.preview');
