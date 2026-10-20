<?php

namespace App\Ai\Tools;

use App\Models\Reunion;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Devuelve el acta y, si se pide, la transcripción y el chat de una reunión.
 */
class LeerReunion implements Tool
{
    private const MAX_CARACTERES_TRANSCRIPCION = 80000;

    private const MAX_CARACTERES_CHAT = 60000;

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Devuelve el acta completa (acuerdos, pendientes, puntos tratados) de una reunión por su ID '
            .'y los documentos que se adjuntaron en su chat. '
            .'Con incluir_transcripcion=true también devuelve lo que se dijo, con minuto y hablante; '
            .'úsalo solo si el acta no alcanza para responder. Con incluir_chat=true devuelve completo lo '
            .'conversado con el Asistente IA en el chat de esa reunión, escrito y por voz en vivo.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $reunion = Reunion::query()->with('resumen')->find($request->string('id')->toString());

        if (! $reunion) {
            return 'No existe una reunión con ese ID. Usa ConsultarReuniones para encontrarla.';
        }

        $partes = [
            'Título: '.($reunion->titulo ?: 'Sin título'),
            'Fecha: '.$reunion->iniciada_en->translatedFormat('l j \d\e F \d\e Y, H:i'),
            'Duración: '.intdiv((int) $reunion->duracion_segundos, 60).' minutos',
            'Acta: '.($reunion->resumen
                ? json_encode($reunion->resumen->contenido, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                : 'Todavía no tiene acta.'),
            $this->documentos($reunion),
        ];

        if ($request->boolean('incluir_transcripcion')) {
            $partes[] = "Transcripción:\n".Str::limit($reunion->transcripcionComoTexto(), self::MAX_CARACTERES_TRANSCRIPCION);
        }

        if ($request->boolean('incluir_chat')) {
            $chat = $reunion->chatComoTexto(soloDuranteLaReunion: false);
            $partes[] = "Chat de la reunión (escrito y por voz):\n"
                .($chat !== '' ? Str::limit($chat, self::MAX_CARACTERES_CHAT) : 'No se usó el chat.');
        }

        return implode("\n", $partes);
    }

    private function documentos(Reunion $reunion): string
    {
        $documentos = $reunion->documentosDelChat(soloDuranteLaReunion: false);

        if (! $documentos) {
            return 'Documentos adjuntos: ninguno.';
        }

        return "Documentos adjuntos en el chat de la reunión (léelos con ConsultarDocumentoReunion):\n"
            .implode("\n", array_map(fn (array $documento) => "- {$documento['name']} (archivo: {$documento['archivo']})", $documentos));
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('ID de la reunión, obtenido con ConsultarReuniones.')->required(),
            'incluir_transcripcion' => $schema->boolean()->required(),
            'incluir_chat' => $schema->boolean()->required(),
        ];
    }
}
