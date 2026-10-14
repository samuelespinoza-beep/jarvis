<?php

namespace App\Http\Controllers;

use App\Enums\EstadoFragmento;
use App\Enums\EstadoReunion;
use App\Http\Resources\ReunionResource;
use App\Jobs\TranscribirFragmento;
use App\Models\FragmentoAudio;
use App\Models\Reunion;
use App\Models\SegmentoTranscripcion;
use App\Services\CierreReunion;
use App\Services\EspejoReunion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReunionController extends Controller
{
    public function __construct(
        private EspejoReunion $espejo,
        private CierreReunion $cierre,
    ) {}

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
     * Estado de procesamiento de la reunión. El navegador lo consulta cada
     * pocos segundos mientras se transcribe.
     */
    public function show(Reunion $reunion): JsonResponse
    {
        $reunion->loadCount('fragmentos');

        return response()->json([
            'reunion' => (new ReunionResource($reunion))->resolve(),
            'fragmentos' => $reunion->fragmentos()->get()->map(fn (FragmentoAudio $fragmento) => [
                'orden' => $fragmento->orden,
                'estado' => $fragmento->estado->value,
                'error' => $fragmento->error,
            ]),
            'segmentos' => $reunion->estado === EstadoReunion::Completada
                ? $reunion->segmentos()->get()->map(fn (SegmentoTranscripcion $segmento) => [
                    'hablante' => $segmento->hablante,
                    'inicio_segundos' => $segmento->inicio_segundos,
                    'texto' => $segmento->texto,
                ])
                : [],
        ]);
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

        // Si todos los fragmentos ya se transcribieron durante la reunión, se cierra aquí mismo.
        $this->cierre->verificar($reunion);

        return response()->json([
            'descartada' => false,
            'reunion' => (new ReunionResource($reunion->refresh()->loadCount('fragmentos')))->resolve(),
        ]);
    }

    /**
     * Vuelve a encolar los fragmentos que fallaron al transcribirse.
     */
    public function reprocesar(Reunion $reunion): JsonResponse
    {
        if ($reunion->estado !== EstadoReunion::Error) {
            return response()->json(['message' => 'La reunión no tiene fragmentos con error.'], 409);
        }

        $fallidos = $reunion->fragmentos()->where('estado', EstadoFragmento::Error)->get();

        $fallidos->each->update(['estado' => EstadoFragmento::Pendiente, 'error' => null]);
        $reunion->update(['estado' => EstadoReunion::Procesando]);
        $this->espejo->guardar($reunion);

        $fallidos->each(fn (FragmentoAudio $fragmento) => TranscribirFragmento::dispatch($fragmento));

        return response()->json([
            'reunion' => (new ReunionResource($reunion->refresh()->loadCount('fragmentos')))->resolve(),
        ]);
    }
}
