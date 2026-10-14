<?php

namespace App\Http\Controllers;

use App\Enums\EstadoReunion;
use App\Http\Resources\ReunionResource;
use App\Models\Reunion;
use App\Services\EspejoReunion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReunionController extends Controller
{
    public function __construct(private EspejoReunion $espejo) {}

    /**
     * Inicia una reunión nueva (botón "Escuchar").
     */
    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'titulo' => ['nullable', 'string', 'max:255'],
        ]);

        $activa = Reunion::query()->where('estado', EstadoReunion::Grabando)->first();

        if ($activa) {
            return response()->json([
                'message' => 'Ya hay una reunión en curso. Termínala antes de iniciar otra.',
                'reunion' => (new ReunionResource($activa))->resolve(),
            ], 409);
        }

        $reunion = Reunion::create([
            'titulo' => $datos['titulo'] ?? null,
            'estado' => EstadoReunion::Grabando,
            'iniciada_en' => now(),
            'creada_por' => $request->user()->id,
        ]);

        $this->espejo->guardar($reunion);

        return response()->json([
            'reunion' => (new ReunionResource($reunion->loadCount('fragmentos')))->resolve(),
        ], 201);
    }

    /**
     * Termina la reunión (botón "Terminar"). El navegador lo llama después de
     * haber subido el último fragmento.
     */
    public function terminar(Reunion $reunion): JsonResponse
    {
        if ($reunion->estado !== EstadoReunion::Grabando) {
            return response()->json([
                'message' => 'La reunión ya fue terminada.',
                'reunion' => (new ReunionResource($reunion))->resolve(),
            ], 409);
        }

        // Sin audio no hay nada que procesar: se descarta en lugar de dejar una reunión vacía.
        if (! $reunion->fragmentos()->exists()) {
            Storage::disk('local')->deleteDirectory($reunion->carpeta());
            $reunion->delete();

            return response()->json(['descartada' => true]);
        }

        $reunion->update([
            'estado' => EstadoReunion::Procesando,
            'finalizada_en' => now(),
            'duracion_segundos' => (int) $reunion->fragmentos()->sum('duracion_segundos'),
        ]);

        $this->espejo->guardar($reunion);

        return response()->json([
            'descartada' => false,
            'reunion' => (new ReunionResource($reunion->loadCount('fragmentos')))->resolve(),
        ]);
    }
}
