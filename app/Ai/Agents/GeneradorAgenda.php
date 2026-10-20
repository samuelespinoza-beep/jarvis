<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Arma la agenda de una reunión a partir de capturas, documentos o un texto
 * pegado (la agenda que mandó alguien por correo, una presentación…). Solo
 * propone los puntos: el usuario los revisa antes de usarlos.
 */
#[Timeout(120)]
class GeneradorAgenda implements Agent, HasStructuredOutput
{
    use Promptable;

    public const MAX_PUNTOS = 20;

    public const MAX_CARACTERES = 200;

    public function instructions(): Stringable|string
    {
        $maxPuntos = self::MAX_PUNTOS;
        $maxCaracteres = self::MAX_CARACTERES;

        return <<<TXT
        Eres el Asistente IA del directorio de La República. Recibes la agenda de una reunión en imágenes
        (capturas de pantalla, fotos), documentos o texto, y la conviertes en la lista de puntos de la agenda.

        Reglas:
        - Un punto por cada tema o bloque de la agenda, en el mismo orden en que aparece. Si la agenda viene
          en varias imágenes o partes, júntalas en un solo orden (por horario si lo tiene).
        - Cada punto, en una sola línea de máximo {$maxCaracteres} caracteres, con este formato:
          "13:00–13:15 · Título del tema. Lidera: cargo o nombre. Temas: lo que se va a tratar."
          Omite el horario, "Lidera:" o "Temas:" si no aparecen. Si los temas no caben, resúmelos sin
          perder los más importantes.
        - Copia horarios, nombres y cargos tal como aparecen. No inventes temas, responsables ni horarios.
        - No incluyas como puntos los títulos de bloque (por ejemplo "Bloque I: Negocio"), las pausas ni
          los saludos, salvo que tengan temas propios.
        - Máximo {$maxPuntos} puntos.
        - Si el contenido no parece una agenda o no se puede leer, devuelve la lista vacía y explica en
          "aviso" qué pasó. Si todo salió bien, "aviso" es null. Si algo no se leía bien, menciónalo en "aviso".
        TXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'puntos' => $schema->array()->items($schema->string())->max(self::MAX_PUNTOS)->required(),
            'aviso' => $schema->string()->nullable()->required(),
        ];
    }
}
