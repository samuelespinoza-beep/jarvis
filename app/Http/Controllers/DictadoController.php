<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Ai\Transcription;

/**
 * Transcribe lo que el usuario le dicta al chat y devuelve el texto, que el
 * navegador deja en la caja del mensaje para revisarlo antes de enviarlo.
 */
class DictadoController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            // Chrome y Edge reportan el audio WebM unas veces como audio/webm y otras como video/webm.
            'audio' => [
                'required',
                'file',
                'mimetypes:audio/webm,video/webm,audio/ogg,audio/mp4,video/mp4',
                'max:'.config('jarvis.dictado.max_kb'),
            ],
        ], [
            'audio.uploaded' => 'No se pudo subir el audio.',
            'audio.mimetypes' => 'Formato de audio no admitido.',
            'audio.max' => 'El audio es demasiado largo.',
        ]);

        $respuesta = Transcription::fromUpload($request->file('audio'))
            ->language(config('jarvis.transcripcion.idioma'))
            ->timeout(60)
            ->generate(model: config('jarvis.dictado.modelo'));

        return response()->json(['texto' => trim($respuesta->text)]);
    }
}
