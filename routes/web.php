<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\ConversacionEnVivoController;
use App\Http\Controllers\DictadoController;
use App\Http\Controllers\FragmentoAudioController;
use App\Http\Controllers\GenerarAgendaController;
use App\Http\Controllers\HistorialController;
use App\Http\Controllers\InformePrevioController;
use App\Http\Controllers\JarvisController;
use App\Http\Controllers\ReunionController;
use App\Http\Controllers\TranscripcionEnVivoController;
use App\Http\Controllers\VozController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('jarvis'))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::get('assistant', JarvisController::class)->name('jarvis');
    // Dirección anterior: los marcadores guardados siguen funcionando.
    Route::redirect('jarvis', 'assistant');

    Route::get('chat/mensajes', [ChatController::class, 'mensajes'])->name('chat.mensajes');
    Route::get('chat/adjuntos/{mensaje}', [ChatController::class, 'adjunto'])->name('chat.adjunto');
    Route::post('chat', [ChatController::class, 'enviar'])->middleware('throttle:20,1')->name('chat.enviar');
    Route::post('chat/voz', VozController::class)->middleware('throttle:30,1')->name('chat.voz');
    Route::post('chat/dictado', DictadoController::class)->middleware('throttle:20,1')->name('chat.dictado');
    Route::post('chat/en-vivo/sesion', [ConversacionEnVivoController::class, 'sesion'])->middleware('throttle:10,1')->name('chat.en-vivo.sesion');
    Route::post('chat/en-vivo/herramienta', [ConversacionEnVivoController::class, 'herramienta'])->middleware('throttle:60,1')->name('chat.en-vivo.herramienta');
    Route::post('chat/en-vivo/guardar', [ConversacionEnVivoController::class, 'guardar'])->middleware('throttle:60,1')->name('chat.en-vivo.guardar');

    Route::get('reuniones', [HistorialController::class, 'index'])->name('reuniones.index');
    Route::get('reuniones/{reunion}', [HistorialController::class, 'show'])->name('reuniones.show');
    Route::get('reuniones/{reunion}/documentos/{archivo}', [HistorialController::class, 'documento'])->name('reuniones.documentos.show');

    Route::post('reuniones/informe-previo', InformePrevioController::class)->middleware('throttle:10,1')->name('reuniones.informe-previo');
    Route::post('reuniones/agenda/generar', GenerarAgendaController::class)->middleware('throttle:10,1')->name('reuniones.agenda.generar');
    Route::post('reuniones', [ReunionController::class, 'store'])->name('reuniones.store');
    Route::get('reuniones/{reunion}/estado', [ReunionController::class, 'estado'])->name('reuniones.estado');
    Route::post('reuniones/{reunion}/pausar', [ReunionController::class, 'pausar'])->name('reuniones.pausar');
    Route::post('reuniones/{reunion}/reanudar', [ReunionController::class, 'reanudar'])->name('reuniones.reanudar');
    Route::put('reuniones/{reunion}/agenda', [ReunionController::class, 'agenda'])->name('reuniones.agenda');
    Route::post('reuniones/{reunion}/terminar', [ReunionController::class, 'terminar'])->name('reuniones.terminar');
    Route::post('reuniones/{reunion}/reprocesar', [ReunionController::class, 'reprocesar'])->name('reuniones.reprocesar');
    Route::post('reuniones/{reunion}/resumen', [ReunionController::class, 'regenerarResumen'])->name('reuniones.resumen.regenerar');
    Route::post('reuniones/{reunion}/en-vivo', TranscripcionEnVivoController::class)->middleware('throttle:30,1')->name('reuniones.en-vivo');
    Route::post('reuniones/{reunion}/fragmentos', [FragmentoAudioController::class, 'store'])->name('reuniones.fragmentos.store');
});

require __DIR__.'/settings.php';
