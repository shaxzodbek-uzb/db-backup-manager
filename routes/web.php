<?php

use App\Http\Controllers\ConnectionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DestinationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::resource('connections', ConnectionController::class)->except(['show']);
    Route::post('connections/{connection}/test', [ConnectionController::class, 'test'])
        ->middleware('throttle:10,1')
        ->name('connections.test');
    Route::get('connections/{connection}/databases', [ConnectionController::class, 'databases'])
        ->name('connections.databases');

    Route::resource('destinations', DestinationController::class)->except(['show']);
    Route::post('destinations/{destination}/test', [DestinationController::class, 'test'])
        ->middleware('throttle:10,1')
        ->name('destinations.test');
});

require __DIR__.'/settings.php';
