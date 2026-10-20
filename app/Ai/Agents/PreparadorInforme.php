<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Prepara el informe previo a una reunión del directorio a partir de las
 * actas de las últimas reuniones y, si existe, de la agenda de la próxima.
 */
#[Timeout(120)]
class PreparadorInforme implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        $hoy = now()->translatedFormat('l j \d\e F \d\e Y');

        return <<<TXT
        Preparas el informe previo a la próxima reunión del directorio de La República, medio de
        comunicación peruano. Hoy es {$hoy} (Lima). Recibirás las actas de las últimas reuniones, de la
        más reciente a la más antigua, y, si existe, la agenda de la próxima reunión.

        Tu objetivo es que el directorio llegue sabiendo dónde quedó y qué tiene que resolver. Sé crítico:
        no maquilles el avance ni des por cumplido lo que no consta como cumplido.

        Reglas:
        - Básate solo en las actas. No inventes acuerdos, responsables, fechas ni avances.
        - "compromisos": incluye todos los acuerdos de las actas. Copia el responsable y la fecha límite tal
          como aparecen (null si no constan). En "fecha_iso" convierte la fecha límite a AAAA-MM-DD tomando
          como referencia la fecha de su reunión ("el viernes", "fin de mes"); si no se puede saber, null.
        - "retomar": temas que quedaron abiertos o sin decisión y conviene volver a tratar, con el motivo.
        - "alertas": problemas que el directorio debería ver: acuerdos sin responsable o sin fecha, temas que
          se repiten de reunión en reunión sin resolverse, contradicciones entre actas, riesgos sin dueño.
        - "sugerencias_agenda": hasta 5 puntos concretos para la agenda de la próxima reunión que aún no
          estén en ella.
        - "antecedentes_agenda": solo si hay agenda; por cada punto, qué se dijo o acordó antes sobre ese
          tema y en qué reunión. Si no hay antecedentes, dilo en "antecedentes".
        - Escribe en español, breve y concreto. Indica de qué reunión sale cada dato (título y fecha).
        TXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resumen' => $schema->string()
                ->description('De 2 a 4 oraciones: dónde quedó el directorio y qué es lo más urgente.')
                ->required(),
            'compromisos' => $schema->array()->items($schema->object(fn (JsonSchema $schema) => [
                'descripcion' => $schema->string()->required(),
                'responsable' => $schema->string()->nullable()->required(),
                'fecha_limite' => $schema->string()->nullable()->required(),
                'fecha_iso' => $schema->string()->nullable()->required(),
                'reunion' => $schema->string()->description('Título y fecha de la reunión donde se acordó.')->required(),
            ]))->required(),
            'retomar' => $schema->array()->items($schema->object(fn (JsonSchema $schema) => [
                'tema' => $schema->string()->required(),
                'motivo' => $schema->string()->required(),
                'reunion' => $schema->string()->required(),
            ]))->required(),
            'alertas' => $schema->array()->items($schema->string())->required(),
            'sugerencias_agenda' => $schema->array()->items($schema->string())->required(),
            'antecedentes_agenda' => $schema->array()->items($schema->object(fn (JsonSchema $schema) => [
                'punto' => $schema->string()->required(),
                'antecedentes' => $schema->string()->required(),
            ]))->required(),
        ];
    }
}
