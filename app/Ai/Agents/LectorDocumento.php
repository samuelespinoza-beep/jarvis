<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Lee un documento de una reunión anterior y responde lo que le pregunta el
 * chat (ver ConsultarDocumentoReunion). Las herramientas solo devuelven texto,
 * así que el documento completo lo lee este agente y no el chat.
 */
#[Timeout(120)]
class LectorDocumento implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'TXT'
        Eres el Asistente IA y te consultan sobre un documento o una imagen que se adjuntó en una reunión del
        directorio de La República. El archivo va adjunto.

        Reglas:
        - Responde en español solo con lo que dice o muestra el archivo. Si no lo dice, dilo; no lo completes con suposiciones.
        - Indica la sección o página cuando se pueda.
        - Incluye las cifras, fechas, nombres y plazos que pidan, tal como aparecen.
        - Si te piden revisarlo o resumirlo en general, da de qué trata, sus temas centrales y sus datos clave.
        - Sé completo pero sin relleno: otro asistente usará tu respuesta para contestar al usuario.
        TXT;
    }
}
