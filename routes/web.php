<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\FragmentoAudioController;
use App\Http\Controllers\HistorialController;
use App\Http\Controllers\JarvisController;
use App\Http\Controllers\ReunionController;
use App\Http\Controllers\TranscripcionEnVivoController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('jarvis'))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::get('jarvis', JarvisController::class)->name('jarvis');

    Route::get('chat/mensajes', [ChatController::class, 'mensajes'])->name('chat.mensajes');
    Route::post('chat', [ChatController::class, 'enviar'])->middleware('throttle:20,1')->name('chat.enviar');

    Route::get('reuniones', [HistorialController::class, 'index'])->name('reuniones.index');
    Route::get('reuniones/{reunion}', [HistorialController::class, 'show'])->name('reuniones.show');

    Route::post('reuniones', [ReunionController::class, 'store'])->name('reuniones.store');
    Route::get('reuniones/{reunion}/estado', [ReunionController::class, 'estado'])->name('reuniones.estado');
    Route::post('reuniones/{reunion}/pausar', [ReunionController::class, 'pausar'])->name('reuniones.pausar');
    Route::post('reuniones/{reunion}/reanudar', [ReunionController::class, 'reanudar'])->name('reuniones.reanudar');
    Route::post('reuniones/{reunion}/terminar', [ReunionController::class, 'terminar'])->name('reuniones.terminar');
    Route::post('reuniones/{reunion}/reprocesar', [ReunionController::class, 'reprocesar'])->name('reuniones.reprocesar');
    Route::post('reuniones/{reunion}/resumen', [ReunionController::class, 'regenerarResumen'])->name('reuniones.resumen.regenerar');
    Route::post('reuniones/{reunion}/en-vivo', TranscripcionEnVivoController::class)->middleware('throttle:30,1')->name('reuniones.en-vivo');
    Route::post('reuniones/{reunion}/fragmentos', [FragmentoAudioController::class, 'store'])->name('reuniones.fragmentos.store');
});

require __DIR__.'/settings.php';
