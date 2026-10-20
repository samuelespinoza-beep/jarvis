<?php

namespace App\Models;

use App\Enums\EstadoReunion;
use App\Support\DocumentoChat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Models\ConversationMessage;

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
     * Margen tras terminar la reunión en que el chat todavía cuenta para el
     * acta: el SDK guarda el mensaje (y su documento) recién al terminar la
     * respuesta, que con un PDF puede tardar, así que un mensaje enviado justo
     * antes de terminar queda guardado con una hora posterior.
     */
    private const MARGEN_CHAT_MINUTOS = 5;

    /**
     * Mensajes del chat (escrito o por voz) de la reunión. Para el acta solo
     * cuentan los enviados mientras duró (con un margen, ver arriba): lo que
     * se siga escribiendo después de terminarla no entra.
     *
     * @return Collection<int, ConversationMessage>
     */
    public function mensajesDelChat(bool $soloDuranteLaReunion = true): Collection
    {
        if (! $this->conversacion_id) {
            return new Collection;
        }

        return ConversationMessage::query()
            ->where('conversation_id', $this->conversacion_id)
            ->whereIn('role', ['user', 'assistant'])
            ->when($soloDuranteLaReunion, fn ($query) => $query->where(
                'created_at', '<=', $this->finalizada_en?->copy()->addMinutes(self::MARGEN_CHAT_MINUTOS) ?? now(),
            ))
            ->oldest()
            ->orderBy('id')
            ->get(['id', 'role', 'content', 'attachments', 'meta', 'created_at']);
    }

    /**
     * El chat de la reunión (escrito y por voz) en texto plano, con el minuto
     * aproximado en que se escribió o dijo (contado desde el inicio, así que
     * con pausas queda corrido).
     */
    public function chatComoTexto(bool $soloDuranteLaReunion = true): string
    {
        return $this->mensajesDelChat($soloDuranteLaReunion)->map(function (ConversationMessage $mensaje) {
            $adjunto = DocumentoChat::delMensaje($mensaje->attachments);

            if (trim($mensaje->content) === '' && ! $adjunto) {
                return null;
            }

            $segundos = max(0, (int) $this->iniciada_en->diffInSeconds($mensaje->created_at));
            $minuto = sprintf('%02d:%02d', intdiv($segundos, 60), $segundos % 60);
            $quien = ($mensaje->role === 'user' ? 'Usuario' : 'Asistente IA')
                .(($mensaje->meta['en_vivo'] ?? false) ? ' (por voz)' : '')
                .($adjunto ? ' (adjuntó '.DocumentoChat::describir($adjunto).')' : '');

            return "[{$minuto}] {$quien}: ".Str::limit(trim($mensaje->content), 3000);
        })->filter()->implode("\n");
    }

    /**
     * Documentos e imágenes adjuntados al chat de la reunión que siguen en el
     * disco. El nombre del archivo guardado ("archivo") los identifica para
     * descargarlos.
     *
     * @return list<array{name: string, archivo: string, path: string, disk: string, imagen: bool}>
     */
    public function documentosDelChat(bool $soloDuranteLaReunion = true): array
    {
        return $this->mensajesDelChat($soloDuranteLaReunion)
            ->flatMap(fn (ConversationMessage $mensaje) => collect($mensaje->attachments)->whereIn('type', DocumentoChat::TIPOS))
            ->filter(fn (array $documento) => Storage::disk($documento['disk'] ?? 'local')->exists($documento['path']))
            ->unique('path')
            ->map(fn (array $documento) => [
                'name' => $documento['name'] ?? basename($documento['path']),
                'archivo' => basename($documento['path']),
                'path' => $documento['path'],
                'disk' => $documento['disk'] ?? 'local',
                'imagen' => $documento['type'] === 'stored-image',
            ])
            ->values()
            ->all();
    }

    /**
     * Un documento del chat de la reunión por el nombre de su archivo guardado.
     *
     * @return array{name: string, archivo: string, path: string, disk: string, imagen: bool}|null
     */
    public function documentoDelChat(string $archivo): ?array
    {
        return collect($this->documentosDelChat(soloDuranteLaReunion: false))->firstWhere('archivo', $archivo);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creada_por');
    }
}
