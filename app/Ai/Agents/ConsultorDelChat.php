<?php

namespace App\Ai\Agents;

use App\Models\Reunion;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * El chat escrito respondiendo una consulta de la conversación por voz en
 * vivo: lee la conversación guardada, con sus documentos adjuntos completos,
 * pero no guarda la consulta. Lo conversado por voz se guarda aparte al
 * terminar, así que guardarla aquí la duplicaría.
 */
#[MaxSteps(6)]
#[Timeout(90)]
class ConsultorDelChat implements Agent, Conversational, HasTools
{
    use Promptable;

    public function __construct(
        private ?Reunion $reunion = null,
        private ?string $conversacionId = null,
    ) {}

    public function instructions(): Stringable|string
    {
        return (new Jarvis($this->reunion))->instruccionesComunes().<<<'TXT'

        Consulta desde la conversación por voz en vivo (ahora mismo):
        - Te pregunta el asistente de voz, que leerá tu respuesta en voz alta al usuario.
        - Responde con lo que dicen los documentos adjuntos y los mensajes de este chat; si hace falta,
          usa tus herramientas. Si el dato no está, dilo en vez de suponerlo.
        - Directo y breve: como máximo 6 frases, sin Markdown, listas, tablas ni direcciones web.
        TXT;
    }

    public function messages(): iterable
    {
        if (! $this->conversacionId) {
            return [];
        }

        return resolve(ConversationStore::class)
            ->getLatestConversationMessages($this->conversacionId, 100)
            ->all();
    }

    public function tools(): iterable
    {
        return (new Jarvis($this->reunion))->tools();
    }
}
