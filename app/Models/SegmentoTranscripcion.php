<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una intervención dentro de la transcripción. Los tiempos son relativos al
 * inicio de la reunión, no del fragmento.
 *
 * @property string $id
 * @property string $reunion_id
 * @property string $fragmento_id
 * @property int $orden
 * @property string|null $hablante
 * @property float $inicio_segundos
 * @property float $fin_segundos
 * @property string $texto
 */
#[Table('segmentos_transcripcion', timestamps: false)]
#[Fillable(['reunion_id', 'fragmento_id', 'orden', 'hablante', 'inicio_segundos', 'fin_segundos', 'texto'])]
class SegmentoTranscripcion extends Model
{
    use HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'inicio_segundos' => 'float',
            'fin_segundos' => 'float',
        ];
    }

    /**
     * @return BelongsTo<FragmentoAudio, $this>
     */
    public function fragmento(): BelongsTo
    {
        return $this->belongsTo(FragmentoAudio::class, 'fragmento_id');
    }
}
