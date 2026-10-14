<?php

use App\Http\Controllers\FragmentoAudioController;
use App\Http\Controllers\JarvisController;
use App\Http\Controllers\ReunionController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('jarvis'))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::get('jarvis', JarvisController::class)->name('jarvis');

    Route::post('reuniones', [ReunionController::class, 'store'])->name('reuniones.store');
    Route::get('reuniones/{reunion}', [ReunionController::class, 'show'])->name('reuniones.show');
    Route::post('reuniones/{reunion}/terminar', [ReunionController::class, 'terminar'])->name('reuniones.terminar');
    Route::post('reuniones/{reunion}/reprocesar', [ReunionController::class, 'reprocesar'])->name('reuniones.reprocesar');
    Route::post('reuniones/{reunion}/fragmentos', [FragmentoAudioController::class, 'store'])->name('reuniones.fragmentos.store');
});

require __DIR__.'/settings.php';
