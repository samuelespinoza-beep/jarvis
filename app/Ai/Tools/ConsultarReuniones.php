<?php

namespace App\Ai\Tools;

use App\Enums\EstadoReunion;
use App\Models\Reunion;
use App\Models\SegmentoTranscripcion;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Busca reuniones grabadas por fecha o por palabras de su título, su acta, lo
 * que se habló (transcripción) o lo que se escribió o dijo en su chat. Si la
 * palabra solo aparece en lo hablado o en el chat, muestra dónde, para que la
 * IA sepa por qué la encontró aunque el acta no lo mencione.
 */
class ConsultarReuniones implements Tool
{
    private const LIMITE = 15;

    public function description(): Stringable|string
    {
        return 'Busca reuniones del directorio grabadas con el Asistente IA. Filtra por rango de fechas y/o por '
            .'una palabra o frase corta (un nombre, una empresa, un tema) que aparezca en el título, el acta, '
            .'lo que se habló (transcripción) o el chat de la reunión. Devuelve el ID, título, fecha y resumen '
            .'ejecutivo de cada una y, si solo se mencionó en lo hablado o en el chat, el fragmento. Si no '
            .'encuentra nada, prueba con otra palabra o un sinónimo. Para ver el acta completa, la '
            .'transcripción o el chat usa LeerReunion.';
    }

    public function handle(Request $request): Stringable|string
    {
        $desde = $request->string('desde')->toString();
        $hasta = $request->string('hasta')->toString();
        $texto = $request->string('texto')->trim()->toString();

        $reuniones = Reunion::query()
            ->where('estado', EstadoReunion::Completada)
            ->with('resumen')
            ->when($desde, fn ($query) => $query->where('iniciada_en', '>=', Carbon::parse($desde)->startOfDay()))
            ->when($hasta, fn ($query) => $query->where('iniciada_en', '<=', Carbon::parse($hasta)->endOfDay()))
            ->when($texto, fn ($query) => $query->where(fn ($query) => $query
                ->where('titulo', 'like', "%{$texto}%")
                ->orWhereHas('resumenes', fn ($query) => $query->where('contenido', 'like', "%{$texto}%"))
                ->orWhereIn('id', SegmentoTranscripcion::query()->select('reunion_id')->where('texto', 'like', "%{$texto}%"))
                ->orWhereIn('conversacion_id', $this->mensajesCon($texto)->select('conversation_id'))))
            ->latest('iniciada_en')
            ->limit(self::LIMITE)
            ->get();

        if ($reuniones->isEmpty()) {
            return 'No se encontraron reuniones con esos criterios.';
        }

        return $reuniones->map(fn (Reunion $reunion) => implode("\n", [
            "ID: {$reunion->id}",
            'Título: '.($reunion->titulo ?: 'Sin título'),
            'Fecha: '.$reunion->iniciada_en->translatedFormat('l j \d\e F \d\e Y, H:i'),
            'Duración: '.intdiv((int) $reunion->duracion_segundos, 60).' minutos',
            'Resumen: '.($reunion->resumen?->contenido['resumen_ejecutivo'] ?? 'Sin resumen.'),
            ...($texto ? $this->menciones($reunion, $texto) : []),
        ]))->implode("\n\n");
    }

    /**
     * Mensajes del chat (del usuario o del asistente) que contienen el texto.
     *
     * @return Builder<ConversationMessage>
     */
    private function mensajesCon(string $texto)
    {
        return ConversationMessage::query()
            ->whereIn('role', ['user', 'assistant'])
            ->where('content', 'like', "%{$texto}%");
    }

    /**
     * Dónde se mencionó el texto si no está en el título ni en el acta: el
     * primer fragmento de lo hablado y el primero del chat.
     *
     * @return list<string>
     */
    private function menciones(Reunion $reunion, string $texto): array
    {
        $enActa = Str::contains($reunion->titulo ?? '', $texto, ignoreCase: true)
            || Str::contains(json_encode($reunion->resumen?->contenido, JSON_UNESCAPED_UNICODE) ?: '', $texto, ignoreCase: true);

        if ($enActa) {
            return [];
        }

        $menciones = [];

        $segmento = $reunion->segmentos()->where('texto', 'like', "%{$texto}%")->first();

        if ($segmento) {
            $segundos = (int) $segmento->inicio_segundos;
            $menciones[] = sprintf('Se mencionó en lo hablado [%02d:%02d]: «%s»', intdiv($segundos, 60), $segundos % 60, $this->extracto($segmento->texto, $texto));
        }

        $mensaje = $reunion->conversacion_id
            ? $this->mensajesCon($texto)->where('conversation_id', $reunion->conversacion_id)->oldest()->first()
            : null;

        if ($mensaje) {
            $quien = $mensaje->role === 'user' ? 'el usuario' : 'el Asistente IA';
            $menciones[] = "Se mencionó en el chat ({$quien}): «{$this->extracto($mensaje->content, $texto)}»";
        }

        return $menciones;
    }

    /**
     * Unas palabras alrededor de la mención, para no devolver textos largos.
     */
    private function extracto(string $contenido, string $texto): string
    {
        return trim(Str::excerpt($contenido, $texto, ['radius' => 140]) ?? Str::limit($contenido, 280));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'desde' => $schema->string()->description('Fecha inicial en formato AAAA-MM-DD, o vacío.')->nullable()->required(),
            'hasta' => $schema->string()->description('Fecha final en formato AAAA-MM-DD, o vacío.')->nullable()->required(),
            'texto' => $schema->string()->description('Palabra o frase corta a buscar en el título, el acta, lo hablado o el chat, o vacío.')->nullable()->required(),
        ];
    }
}
