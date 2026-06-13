<?php

use App\Http\Controllers\ConnectionController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::resource('connections', ConnectionController::class)->except(['show']);
    Route::post('connections/{connection}/test', [ConnectionController::class, 'test'])
        ->middleware('throttle:10,1')
        ->name('connections.test');
    Route::get('connections/{connection}/databases', [ConnectionController::class, 'databases'])
        ->name('connections.databases');
});

require __DIR__.'/settings.php';
