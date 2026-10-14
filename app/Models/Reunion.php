<?php

namespace App\Models;

use App\Enums\EstadoReunion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $titulo
 * @property EstadoReunion $estado
 * @property Carbon $iniciada_en
 * @property Carbon|null $finalizada_en
 * @property int|null $duracion_segundos
 * @property int $creada_por
 * @property int $costo_audio_segundos
 * @property int $costo_tokens
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('reuniones')]
#[Fillable(['titulo', 'estado', 'iniciada_en', 'finalizada_en', 'duracion_segundos', 'creada_por'])]
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
            'iniciada_en' => 'datetime',
            'finalizada_en' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creada_por');
    }
}
