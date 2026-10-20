<?php

namespace App\Models;

use App\Enums\EstadoReunion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $titulo
 * @property list<string>|null $agenda
 * @property EstadoReunion $estado
 * @property Carbon $iniciada_en
 * @property Carbon|null $pausada_en
 * @property Carbon|null $finalizada_en
 * @property int|null $duracion_segundos
 * @property Carbon|null $transcripcion_lista_en
 * @property int $creada_por
 * @property string|null $conversacion_id
 * @property int $costo_audio_segundos
 * @property int $costo_tokens
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('reuniones')]
#[Fillable(['titulo', 'agenda', 'estado', 'iniciada_en', 'pausada_en', 'finalizada_en', 'duracion_segundos', 'creada_por', 'conversacion_id'])]
class Reunion extends Model
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
            'estado' => EstadoReunion::class,
            'agenda' => 'array',
            'iniciada_en' => 'datetime',
            'pausada_en' => 'datetime',
            'finalizada_en' => 'datetime',
            'transcripcion_lista_en' => 'datetime',
        ];
    }

    /**
     * Carpeta de la reunión dentro del disco "local" (storage/app/private).
     */
    public function carpeta(): string
    {
        return "reuniones/{$this->id}";
    }

    /**
     * @return HasMany<FragmentoAudio, $this>
     */
    public function fragmentos(): HasMany
    {
        return $this->hasMany(FragmentoAudio::class)->orderBy('orden');
    }

    /**
     * @return HasMany<SegmentoTranscripcion, $this>
     */
    public function segmentos(): HasMany
    {
        return $this->hasMany(SegmentoTranscripcion::class)->orderBy('inicio_segundos')->orderBy('orden');
    }

    /**
     * @return HasMany<Resumen, $this>
     */
    public function resumenes(): HasMany
    {
        return $this->hasMany(Resumen::class)->orderByDesc('version');
    }

    /**
     * @return HasOne<Resumen, $this>
     */
    public function resumen(): HasOne
    {
        return $this->hasOne(Resumen::class)->latestOfMany('version');
    }

    /**
     * Transcripción en texto plano con minuto y hablante, lista para la IA.
     */
    public function transcripcionComoTexto(): string
    {
        return $this->segmentos()->get()->map(function (SegmentoTranscripcion $segmento) {
            $segundos = (int) $segmento->inicio_segundos;
            $minuto = sprintf('%02d:%02d', intdiv($segundos, 60), $segundos % 60);
            $hablante = $segmento->hablante ? "Hablante {$segmento->hablante}" : 'Hablante';

            return "[{$minuto}] {$hablante}: {$segmento->texto}";
        })->implode("\n");
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creada_por');
    }
}
