<?php

namespace App\Models;

use App\Enums\EstadoFragmento;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $reunion_id
 * @property int $orden
 * @property string $ruta_archivo
 * @property string $mime
 * @property int $tamano_bytes
 * @property int $duracion_segundos
 * @property EstadoFragmento $estado
 * @property int $intentos
 * @property string|null $error
 * @property array{tema: string, resumen: string, puntos: list<string>}|null $resumen
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('fragmentos_audio')]
#[Fillable(['orden', 'ruta_archivo', 'mime', 'tamano_bytes', 'duracion_segundos', 'estado', 'intentos', 'error', 'resumen'])]
class FragmentoAudio extends Model
{
    use HasUlids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado' => EstadoFragmento::class,
            'resumen' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Reunion, $this>
     */
    public function reunion(): BelongsTo
    {
        return $this->belongsTo(Reunion::class);
    }

    /**
     * @return HasMany<SegmentoTranscripcion, $this>
     */
    public function segmentos(): HasMany
    {
        return $this->hasMany(SegmentoTranscripcion::class, 'fragmento_id');
    }

    /**
     * Lo dicho en este tramo, con minuto de la reunión y hablante.
     */
    public function transcripcionComoTexto(): string
    {
        return $this->segmentos()->orderBy('orden')->get()->map(function (SegmentoTranscripcion $segmento) {
            $segundos = (int) $segmento->inicio_segundos;
            $minuto = sprintf('%02d:%02d', intdiv($segundos, 60), $segundos % 60);
            $hablante = $segmento->hablante ? "Hablante {$segmento->hablante}" : 'Hablante';

            return "[{$minuto}] {$hablante}: {$segmento->texto}";
        })->implode("\n");
    }

    /**
     * Segundo de la reunión en el que empieza este fragmento.
     */
    public function desplazamientoSegundos(): int
    {
        return (int) static::query()
            ->where('reunion_id', $this->reunion_id)
            ->where('orden', '<', $this->orden)
            ->sum('duracion_segundos');
    }
}
