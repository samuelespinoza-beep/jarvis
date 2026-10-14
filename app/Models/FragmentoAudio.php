<?php

namespace App\Models;

use App\Enums\EstadoFragmento;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('fragmentos_audio')]
#[Fillable(['orden', 'ruta_archivo', 'mime', 'tamano_bytes', 'duracion_segundos', 'estado'])]
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
        ];
    }

    /**
     * @return BelongsTo<Reunion, $this>
     */
    public function reunion(): BelongsTo
    {
        return $this->belongsTo(Reunion::class);
    }
}
