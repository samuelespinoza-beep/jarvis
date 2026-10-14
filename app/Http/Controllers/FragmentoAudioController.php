<?php

namespace App\Http\Controllers;

use App\Enums\EstadoFragmento;
use App\Enums\EstadoReunion;
use App\Http\Requests\GuardarFragmentoRequest;
use App\Jobs\TranscribirFragmento;
use App\Models\Reunion;
use App\Services\EspejoReunion;
use Illuminate\Http\JsonResponse;

class FragmentoAudioController extends Controller
{
    public function __construct(private EspejoReunion $espejo) {}

    /**
     * Recibe un fragmento de audio de la reunión en curso.
     */
    public function store(GuardarFragmentoRequest $request, Reunion $reunion): JsonResponse
    {
        if ($reunion->estado !== EstadoReunion::Grabando) {
            return response()->json(['message' => 'La reunión ya no está grabando.'], 409);
        }

        $orden = $request->integer('orden');

        // Si el navegador reintenta un fragmento que sí llegó (se perdió la respuesta), no se duplica.
        $existente = $reunion->fragmentos()->where('orden', $orden)->first();

        if ($existente) {
            return response()->json(['fragmento' => ['id' => $existente->id, 'orden' => $existente->orden]]);
        }

        $archivo = $request->file('audio');
        $mime = $archivo->getClientMimeType();

        $ruta = $archivo->storeAs(
            $reunion->carpeta().'/audio',
            sprintf('%04d.%s', $orden, $this->extension($mime)),
            'local',
        );

        $fragmento = $reunion->fragmentos()->create([
            'orden' => $orden,
            'ruta_archivo' => $ruta,
            'mime' => $mime,
            'tamano_bytes' => $archivo->getSize(),
            'duracion_segundos' => $request->integer('duracion_segundos'),
            'estado' => EstadoFragmento::Pendiente,
        ]);

        $this->espejo->guardar($reunion->refresh());

        TranscribirFragmento::dispatch($fragmento);

        return response()->json(['fragmento' => ['id' => $fragmento->id, 'orden' => $fragmento->orden]], 201);
    }

    private function extension(string $mime): string
    {
        return match (true) {
            str_contains($mime, 'ogg') => 'ogg',
            str_contains($mime, 'mp4') => 'm4a',
            default => 'webm',
        };
    }
}
