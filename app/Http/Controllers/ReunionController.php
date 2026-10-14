<?php

namespace App\Http\Controllers;

use App\Enums\EstadoFragmento;
use App\Enums\EstadoReunion;
use App\Http\Resources\ReunionResource;
use App\Jobs\GenerarResumen;
use App\Jobs\TranscribirFragmento;
use App\Models\FragmentoAudio;
use App\Models\Reunion;
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

        $activa = Reunion::query()->whereIn('estado', EstadoReunion::abiertos())->first();

        if ($activa) {
            return response()->json([
                'message' => 'Ya hay una reunión abierta. Reanúdala o termínala antes de iniciar otra.',
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
     * pocos segundos mientras se transcribe y se genera el resumen.
     */
    public function estado(Reunion $reunion): JsonResponse
    {
        $reunion->loadCount('fragmentos');

        $inicio = 0;

        return response()->json([
            'reunion' => (new ReunionResource($reunion))->resolve(),
            // También es la línea de tiempo: cada tramo con su minuto de inicio y su resumen corto.
            'fragmentos' => $reunion->fragmentos()->get()->map(function (FragmentoAudio $fragmento) use (&$inicio) {
                $tramo = [
                    'orden' => $fragmento->orden,
                    'estado' => $fragmento->estado->value,
                    'error' => $fragmento->error,
                    'inicio_segundos' => $inicio,
                    'duracion_segundos' => $fragmento->duracion_segundos,
                    'resumen' => $fragmento->resumen,
                ];

                $inicio += $fragmento->duracion_segundos;

                return $tramo;
            }),
            'resumen' => $reunion->estado === EstadoReunion::Completada
                ? $reunion->resumen?->contenido
                : null,
        ]);
    }

    /**
     * Pausa la reunión (botón "Pausar"): deja de grabar, pero la reunión sigue
     * abierta para reanudarla más tarde.
     */
    public function pausar(Reunion $reunion): JsonResponse
    {
        if ($reunion->estado !== EstadoReunion::Grabando) {
            return response()->json(['message' => 'La reunión no está grabando.'], 409);
        }

        $reunion->update(['estado' => EstadoReunion::Pausada, 'pausada_en' => now()]);
        $this->espejo->guardar($reunion);

        return $this->respuesta($reunion);
    }

    /**
     * Vuelve a grabar en la misma reunión (botón "Reanudar"). También sirve para
     * retomar una reunión que quedó grabando porque se cerró el navegador.
     */
    public function reanudar(Reunion $reunion): JsonResponse
    {
        if (! $reunion->estado->estaAbierta()) {
            return response()->json(['message' => 'La reunión ya fue terminada.'], 409);
        }

        $reunion->update(['estado' => EstadoReunion::Grabando, 'pausada_en' => null]);
        $this->espejo->guardar($reunion);

        return $this->respuesta($reunion);
    }

    /**
     * Termina la reunión (botón "Terminar reunión"), esté grabando o en pausa.
     * El navegador lo llama después de haber subido el último fragmento.
     */
    public function terminar(Reunion $reunion): JsonResponse
    {
        if (! $reunion->estado->estaAbierta()) {
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
            'pausada_en' => null,
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
     * Retoma una reunión que quedó en error: vuelve a transcribir los
     * fragmentos fallidos o, si la transcripción ya estaba completa, vuelve a
     * generar el resumen.
     */
    public function reprocesar(Reunion $reunion): JsonResponse
    {
        if ($reunion->estado !== EstadoReunion::Error) {
            return response()->json(['message' => 'La reunión no tiene errores que reprocesar.'], 409);
        }

        $fallidos = $reunion->fragmentos()->where('estado', EstadoFragmento::Error)->get();

        $fallidos->each->update(['estado' => EstadoFragmento::Pendiente, 'error' => null]);
        $reunion->update(['estado' => EstadoReunion::Procesando]);
        $this->espejo->guardar($reunion);

        if ($fallidos->isNotEmpty()) {
            $fallidos->each(fn (FragmentoAudio $fragmento) => TranscribirFragmento::dispatch($fragmento));
        } else {
            GenerarResumen::dispatch($reunion);
        }

        return response()->json([
            'reunion' => (new ReunionResource($reunion->refresh()->loadCount('fragmentos')))->resolve(),
        ]);
    }

    /**
     * Genera una versión nueva del resumen de una reunión completada.
     */
    public function regenerarResumen(Reunion $reunion): JsonResponse
    {
        if ($reunion->estado !== EstadoReunion::Completada) {
            return response()->json(['message' => 'La reunión todavía no está completada.'], 409);
        }

        $reunion->update(['estado' => EstadoReunion::Procesando]);
        $this->espejo->guardar($reunion);

        GenerarResumen::dispatch($reunion);

        return response()->json([
            'reunion' => (new ReunionResource($reunion->refresh()->loadCount('fragmentos')))->resolve(),
        ]);
    }

    private function respuesta(Reunion $reunion): JsonResponse
    {
        $reunion->loadCount('fragmentos')->loadSum('fragmentos', 'duracion_segundos');

        return response()->json(['reunion' => (new ReunionResource($reunion))->resolve()]);
    }
}
