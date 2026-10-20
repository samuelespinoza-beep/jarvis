<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Convierte la transcripción de una reunión, junto con su chat y los
 * documentos adjuntados en él, en un acta estructurada.
 */
#[Timeout(240)]
class ResumidorReunion implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'TXT'
        Eres el Asistente IA que redacta las actas de las reuniones del directorio de la empresa
        (gerentes y jefes de área). Recibirás la transcripción automática de una reunión en español, lo que
        se conversó en el chat del Asistente IA durante la reunión y los documentos e imágenes que se adjuntaron en él.

        Cada línea tiene el formato "[mm:ss] Hablante X: texto". Los hablantes se identifican solo con
        letras y la misma letra puede no ser la misma persona en distintos tramos de la reunión, así que
        no inventes nombres ni cargos: si alguien se presenta o es llamado por su nombre, puedes usarlo.

        Reglas:
        - Escribe en español neutro y profesional, en tercera persona.
        - Básate solo en lo que se dijo. No inventes datos, cifras, responsables ni fechas.
        - Ignora las frases dirigidas al Asistente IA, el sistema que graba (por ejemplo "Asistente, pausa la
          reunión" o "Asistente, busca en la web..."): son órdenes al sistema, no parte de la conversación.
        - La transcripción puede tener errores de reconocimiento de voz: interpreta con sentido común,
          pero si algo es ambiguo, dilo en lugar de suponer.
        - El chat es parte de la reunión. Cada línea tiene el formato "[mm:ss] Usuario: texto" o
          "[mm:ss] Asistente IA: texto", con "(por voz)" si se dijo en la conversación por voz en vivo. Lo que
          escribe, dice o pregunta el usuario cuenta como un aporte más a la reunión. Las respuestas del Asistente IA (búsquedas, análisis, resúmenes de documentos) son material
          de apoyo: úsalas como contexto, pero no las presentes como acuerdos ni como opiniones de los asistentes.
        - Los documentos e imágenes adjuntos son material revisado en la reunión. Incluye en las demás secciones lo que de
          ellos se discutió o sea relevante para la agenda, sin transcribirlos. En "documentos_revisados" pon
          cada documento o imagen (con su nombre de archivo) y en "aporte" lo principal que aporta a la reunión, en 1 o 2
          oraciones. Si no hay documentos, lista vacía.
        - "minuto_aprox" es el minuto de la reunión (número entero) en que empieza a tratarse el punto.
        - En los acuerdos, deja "responsable" y "fecha_limite" en null si no se mencionan.
        - Si una sección no tiene contenido, devuelve una lista vacía.
        - "sugerencias_de_mejora" son recomendaciones tuyas para la próxima reunión o sobre temas que
          quedaron poco claros; sé concreto y breve.
        - "agenda": si te dan la agenda, por cada punto (en el mismo orden y con el mismo texto) indica si se
          trató ("tratado"), si se trató solo en parte o sin decisión ("parcial") o si no se trató
          ("no_tratado"), con un comentario breve de lo que se dijo o resolvió. Si no hay agenda, lista vacía.
          Los temas tratados que no estaban en la agenda van igual en las demás secciones.
        TXT;
    }

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
            'documentos_revisados' => $schema->array()->items($schema->object(fn (JsonSchema $schema) => [
                'nombre' => $schema->string()->required(),
                'aporte' => $schema->string()->required(),
            ]))->required(),
            'agenda' => $schema->array()->items($schema->object(fn (JsonSchema $schema) => [
                'punto' => $schema->string()->required(),
                'estado' => $schema->string()->enum(['tratado', 'parcial', 'no_tratado'])->required(),
                'comentario' => $schema->string()->required(),
            ]))->required(),
        ];
    }
}
