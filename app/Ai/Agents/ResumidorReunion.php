<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Convierte la transcripción de una reunión en un acta estructurada.
 */
#[Timeout(240)]
class ResumidorReunion implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'TXT'
        Eres Jarvis, el asistente que redacta las actas de las reuniones del directorio de la empresa
        (gerentes y jefes de área). Recibirás la transcripción automática de una reunión en español.

        Cada línea tiene el formato "[mm:ss] Hablante X: texto". Los hablantes se identifican solo con
        letras y la misma letra puede no ser la misma persona en distintos tramos de la reunión, así que
        no inventes nombres ni cargos: si alguien se presenta o es llamado por su nombre, puedes usarlo.

        Reglas:
        - Escribe en español neutro y profesional, en tercera persona.
        - Básate solo en lo que se dijo. No inventes datos, cifras, responsables ni fechas.
        - Ignora las frases dirigidas a Jarvis, el asistente que graba (por ejemplo "Jarvis, pausa la reunión"
          o "Jarvis, busca en la web..."): son órdenes al sistema, no parte de la conversación.
        - La transcripción puede tener errores de reconocimiento de voz: interpreta con sentido común,
          pero si algo es ambiguo, dilo en lugar de suponer.
        - "minuto_aprox" es el minuto de la reunión (número entero) en que empieza a tratarse el punto.
        - En los acuerdos, deja "responsable" y "fecha_limite" en null si no se mencionan.
        - Si una sección no tiene contenido, devuelve una lista vacía.
        - "sugerencias_de_mejora" son recomendaciones tuyas para la próxima reunión o sobre temas que
          quedaron poco claros; sé concreto y breve.
        TXT;
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        $punto = fn (JsonSchema $schema) => [
            'tema' => $schema->string()->required(),
            'detalle' => $schema->string()->required(),
            'minuto_aprox' => $schema->integer()->nullable()->required(),
        ];

        return [
            'titulo_sugerido' => $schema->string()
                ->description('Título corto que describa la reunión, máximo 8 palabras.')
                ->required(),
            'resumen_ejecutivo' => $schema->string()
                ->description('Resumen de 3 a 5 oraciones con lo más importante de la reunión.')
                ->required(),
            'temas_tratados' => $schema->array()->items($schema->string())->required(),
            'puntos_principales' => $schema->array()->items($schema->object($punto))->required(),
            'puntos_secundarios' => $schema->array()->items($schema->object($punto))->required(),
            'acuerdos' => $schema->array()->items($schema->object(fn (JsonSchema $schema) => [
                'descripcion' => $schema->string()->required(),
                'responsable' => $schema->string()->nullable()->required(),
                'fecha_limite' => $schema->string()->nullable()->required(),
            ]))->required(),
            'pendientes' => $schema->array()->items($schema->string())->required(),
            'riesgos_o_puntos_criticos' => $schema->array()->items($schema->string())->required(),
            'sugerencias_de_mejora' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
