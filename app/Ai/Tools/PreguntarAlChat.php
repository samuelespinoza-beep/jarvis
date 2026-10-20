<?php

namespace App\Ai\Tools;

use App\Ai\Agents\ConsultorDelChat;
use App\Models\Reunion;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Para la conversación por voz en vivo: le pasa una pregunta al chat escrito,
 * que tiene los documentos adjuntos completos (ver ConsultorDelChat).
 */
class PreguntarAlChat implements Tool
{
    public function __construct(
        private ?Reunion $reunion = null,
        private ?string $conversacionId = null,
    ) {}

    public function description(): Stringable|string
    {
        return 'Consulta al chat escrito, que tiene los documentos adjuntos completos y todo lo escrito antes '
            .'en este chat. Úsala para preguntas sobre un documento adjunto (cifras, fechas, cláusulas, '
            .'detalles) o sobre lo conversado por escrito. Escribe la pregunta completa, con el contexto necesario.';
    }

    public function handle(Request $request): Stringable|string
    {
        if (! $this->conversacionId) {
            return 'Este chat todavía no tiene documentos ni mensajes escritos.';
        }

        return (new ConsultorDelChat($this->reunion, $this->conversacionId))
            ->prompt($request->string('pregunta')->toString(), model: config('jarvis.chat.modelo'))
            ->text;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'pregunta' => $schema->string()->description('La pregunta completa, como para alguien que no escuchó la conversación.')->required(),
        ];
    }
}
