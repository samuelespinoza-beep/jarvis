<?php

namespace App\Ai\Tools;

use App\Ai\Agents\BuscadorWeb;
use App\Ai\ContenidoExterno;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Búsqueda web para la conversación por voz en vivo (ver BuscadorWeb).
 */
class BuscarEnInternet implements Tool
{
    public function description(): Stringable|string
    {
        return 'Busca en internet información general o de actualidad (noticias, precios, mercados, '
            .'tendencias) y devuelve un resumen con sus fuentes. No incluyas en la consulta datos '
            .'internos de las reuniones ni de los documentos.';
    }

    public function handle(Request $request): Stringable|string
    {
        $resultado = (new BuscadorWeb)
            ->prompt($request->string('consulta')->toString(), model: config('jarvis.chat.modelo'))
            ->text;

        return ContenidoExterno::envolver('la búsqueda web', $resultado);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'consulta' => $schema->string()->description('Qué buscar, en pocas palabras.')->required(),
        ];
    }
}
