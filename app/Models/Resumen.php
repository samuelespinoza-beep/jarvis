<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Acta generada por IA a partir de la transcripción. Regenerar crea una
 * versión nueva y conserva las anteriores.
 *
 * @property string $id
 * @property string $reunion_id
 * @property int $version
 * @property string $modelo
 * @property array<string, mixed> $contenido
 * @property int $tokens
 * @property Carbon|null $created_at
 */
#[Table('resumenes')]
#[Fillable(['version', 'modelo', 'contenido', 'tokens'])]
class Resumen extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'contenido' => 'array',
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
