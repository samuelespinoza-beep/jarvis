<?php

namespace App\Http\Controllers;

use App\Support\TextoHablado;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Ai\Audio;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lee en voz alta una respuesta del chat: recibe el texto (Markdown) y la voz
 * elegida por el usuario, y devuelve el audio en MP3.
 */
class VozController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $config = config('jarvis.voz');
        $datos = $request->validate([
            'texto' => ['required', 'string', 'max:20000'],
            'voz' => ['nullable', 'string', Rule::in(array_keys($config['voces']))],
        ]);

        $texto = TextoHablado::desdeMarkdown($datos['texto'], $config['max_caracteres']);

        abort_if($texto === '', 422, 'No hay texto para leer.');

        $audio = Audio::of($texto)
            ->voice($datos['voz'] ?? $config['voz'])
            ->instructions($config['instrucciones'])
            ->timeout(60)
            ->generate('openai', $config['modelo']);

        return response($audio->content(), 200, [
            'Content-Type' => $audio->mimeType() ?? 'audio/mpeg',
            'Cache-Control' => 'no-store',
        ]);
    }
}
