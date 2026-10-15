<?php

namespace App\Http\Controllers;

use App\Models\Reunion;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Throwable;

class TranscripcionEnVivoController extends Controller
{
    /**
     * Entrega al navegador una llave temporal de OpenAI Realtime para que
     * transmita el micrófono directamente y reciba el texto en vivo, donde
     * busca las órdenes de voz ("Jarvis, pausa la reunión"). También sirve
     * con la reunión en pausa, para poder decir "Jarvis, reanuda". La llave
     * real (OPENAI_API_KEY) nunca sale del servidor.
     */
    public function __invoke(Reunion $reunion): JsonResponse
    {
        if (! config('jarvis.en_vivo.habilitada')) {
            return response()->json(['message' => 'La transcripción en vivo está desactivada.'], 404);
        }

        if (! $reunion->estado->estaAbierta()) {
            return response()->json(['message' => 'La reunión no está abierta.'], 409);
        }

        try {
            $respuesta = Http::baseUrl(config('ai.providers.openai.url'))
                ->withToken(config('ai.providers.openai.key'))
                ->timeout(15)
                ->post('realtime/client_secrets', [
                    'expires_after' => [
                        'anchor' => 'created_at',
                        'seconds' => config('jarvis.en_vivo.segundos_llave'),
                    ],
                    'session' => [
                        'type' => 'transcription',
                        'audio' => [
                            'input' => [
                                // Micrófono de sala, no auriculares.
                                'noise_reduction' => ['type' => 'far_field'],
                                'transcription' => [
                                    'model' => config('jarvis.en_vivo.modelo'),
                                    'language' => config('jarvis.transcripcion.idioma'),
                                    // Ayuda a reconocer la palabra de activación en vez de "Yarvis" o "Jarvi".
                                    'prompt' => 'Jarvis',
                                ],
                                // OpenAI detecta las pausas al hablar y cierra cada frase.
                                'turn_detection' => [
                                    'type' => 'server_vad',
                                    'silence_duration_ms' => 500,
                                ],
                            ],
                        ],
                    ],
                ])
                ->throw();
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'No se pudo iniciar la transcripción en vivo.'], 502);
        }

        return response()->json([
            'llave' => $respuesta->json('value'),
            'expira_en' => $respuesta->json('expires_at'),
        ]);
    }
}
