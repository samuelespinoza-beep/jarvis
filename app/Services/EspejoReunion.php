<?php

namespace App\Services;

use App\Models\FragmentoAudio;
use App\Models\Reunion;
use Illuminate\Support\Facades\Storage;

/**
 * Mantiene storage/app/private/reuniones/{id}/reunion.json como copia legible
 * de la reunión, para respaldo y para migrar a otra base de datos sin la app.
 */
class EspejoReunion
{
    public function guardar(Reunion $reunion): void
    {
        $reunion->loadMissing('fragmentos', 'creador');

        $datos = [
            'id' => $reunion->id,
            'titulo' => $reunion->titulo,
            'estado' => $reunion->estado->value,
            'iniciada_en' => $reunion->iniciada_en->toIso8601String(),
            'pausada_en' => $reunion->pausada_en?->toIso8601String(),
            'finalizada_en' => $reunion->finalizada_en?->toIso8601String(),
            'duracion_segundos' => $reunion->duracion_segundos,
            'creada_por' => [
                'id' => $reunion->creador->id,
                'email' => $reunion->creador->email,
            ],
            'fragmentos' => $reunion->fragmentos->map(fn (FragmentoAudio $fragmento) => [
                'id' => $fragmento->id,
                'orden' => $fragmento->orden,
                'archivo' => basename($fragmento->ruta_archivo),
                'mime' => $fragmento->mime,
                'tamano_bytes' => $fragmento->tamano_bytes,
                'duracion_segundos' => $fragmento->duracion_segundos,
                'estado' => $fragmento->estado->value,
            ])->all(),
        ];

        Storage::disk('local')->put(
            $reunion->carpeta().'/reunion.json',
            json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }
}
