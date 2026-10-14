<?php

namespace App\Http\Resources;

use App\Models\Reunion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Reunion
 */
class ReunionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'titulo' => $this->titulo,
            'estado' => $this->estado->value,
            'iniciada_en' => $this->iniciada_en->toIso8601String(),
            'finalizada_en' => $this->finalizada_en?->toIso8601String(),
            'duracion_segundos' => $this->duracion_segundos,
            'transcripcion_lista' => $this->transcripcion_lista_en !== null,
            'fragmentos_subidos' => $this->whenCounted('fragmentos'),
        ];
    }
}
