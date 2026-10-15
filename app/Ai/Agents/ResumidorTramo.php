<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Resume en pocas palabras un tramo corto (≈1 minuto) de una reunión en curso,
 * para la línea de tiempo que se ve mientras se graba.
 */
#[Timeout(60)]
class ResumidorTramo implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'TXT'
        Eres Jarvis y vas tomando nota, minuto a minuto, de una reunión del directorio que sigue en curso.
        Recibes la transcripción automática de un tramo de aproximadamente un minuto y, como contexto, el
        resumen del tramo anterior.

        Reglas:
        - "tema": el asunto principal del tramo en 2 a 6 palabras. Si continúa el tema anterior, usa el mismo nombre.
        - "resumen": 1 o 2 oraciones en español, en tercera persona, con lo concreto que se dijo
          (decisiones, cifras, propuestas, dudas).
        - "puntos": como máximo 3 datos clave muy breves; lista vacía si no hay.
        - Básate solo en el texto del tramo. No inventes nombres, cifras ni conclusiones.
        - Ignora las frases dirigidas a Jarvis, el asistente que graba (por ejemplo "Jarvis, pausa la reunión"
          o "Jarvis, busca en la web..."): son órdenes al sistema, no parte de la conversación.
        - Si el tramo solo tiene saludos, ruido o frases sueltas sin contenido, usa el tema
          "Sin contenido relevante" y descríbelo en una oración.
        TXT;
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tema' => $schema->string()->required(),
            'resumen' => $schema->string()->required(),
            'puntos' => $schema->array()->items($schema->string())->max(3)->required(),
        ];
    }
}
