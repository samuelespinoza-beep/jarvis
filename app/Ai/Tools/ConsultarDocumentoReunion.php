<?php

namespace App\Ai\Tools;

use App\Ai\Agents\LectorDocumento;
use App\Models\Reunion;
use App\Support\DocumentoChat;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Responde preguntas sobre un documento adjuntado al chat de una reunión,
 * para retomar lo revisado en reuniones anteriores.
 */
class ConsultarDocumentoReunion implements Tool
{
    public function description(): Stringable|string
    {
        return 'Lee un documento (PDF, Word, etc.) o una imagen adjuntada en el chat de una reunión y responde una pregunta '
            .'sobre él. Usa LeerReunion para ver los documentos de una reunión y su "archivo". Escribe la '
            .'pregunta completa, o pide un resumen si quieren revisarlo en general.';
    }

    public function handle(Request $request): Stringable|string
    {
        $reunion = Reunion::query()->find($request->string('reunion_id')->toString());

        if (! $reunion) {
            return 'No existe una reunión con ese ID. Usa ConsultarReuniones para encontrarla.';
        }

        $documento = $reunion->documentoDelChat($request->string('archivo')->toString());

        if (! $documento) {
            return 'Esa reunión no tiene ese documento. Usa LeerReunion para ver sus documentos.';
        }

        $respuesta = (new LectorDocumento)->prompt(
            $request->string('pregunta')->toString(),
            attachments: [DocumentoChat::adjunto($documento['path'], $documento['name'], $documento['disk'])],
            model: config('jarvis.chat.modelo'),
        );

        $reunion->increment('costo_tokens', $respuesta->usage->inputTokens + $respuesta->usage->outputTokens);

        return "Documento «{$documento['name']}»:\n{$respuesta->text}";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'reunion_id' => $schema->string()->description('ID de la reunión, obtenido con ConsultarReuniones.')->required(),
            'archivo' => $schema->string()->description('El "archivo" del documento, tal como lo muestra LeerReunion.')->required(),
            'pregunta' => $schema->string()->description('La pregunta completa sobre el documento.')->required(),
        ];
    }
}
