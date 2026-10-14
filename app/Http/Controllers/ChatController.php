<?php

namespace App\Http\Controllers;

use App\Ai\Agents\Jarvis;
use App\Models\Reunion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chat con Jarvis. Mientras hay una reunión abierta, el chat pertenece a ella
 * (una conversación por reunión). Sin reunión abierta es un chat general que
 * empieza en blanco cada vez.
 */
class ChatController extends Controller
{
    public function __construct(private ConversationStore $conversaciones) {}

    /**
     * Mensajes del chat de una reunión (?reunion=ID) o de una conversación
     * general (?conversacion=ID).
     */
    public function mensajes(Request $request): JsonResponse
    {
        if ($request->filled('reunion')) {
            $id = Reunion::query()->findOrFail($request->string('reunion')->toString())->conversacion_id;
        } else {
            $id = $request->string('conversacion')->toString() ?: null;

            if ($id) {
                $this->autorizar($request, $id);
            }
        }

        if (! $id) {
            return response()->json(['conversacion_id' => null, 'mensajes' => []]);
        }

        $mensajes = ConversationMessage::query()
            ->where('conversation_id', $id)
            ->whereIn('role', ['user', 'assistant'])
            ->where('content', '!=', '')
            ->oldest()
            ->get(['role', 'content'])
            ->map(fn (ConversationMessage $mensaje) => [
                'rol' => $mensaje->role === 'user' ? 'usuario' : 'jarvis',
                'contenido' => $mensaje->content,
            ]);

        return response()->json(['conversacion_id' => $id, 'mensajes' => $mensajes]);
    }

    /**
     * Envía un mensaje a Jarvis y transmite su respuesta como eventos SSE.
     * El ID de la conversación viaja en la cabecera X-Conversacion-Id.
     */
    public function enviar(Request $request): Response
    {
        $datos = $request->validate([
            'mensaje' => ['required', 'string', 'max:4000'],
            'reunion_id' => ['nullable', 'string', Rule::exists('reuniones', 'id')],
            'conversacion_id' => ['nullable', 'string', 'max:36'],
        ]);

        $reunion = isset($datos['reunion_id']) ? Reunion::query()->find($datos['reunion_id']) : null;

        // En una reunión manda la conversación guardada en ella, no la que diga el navegador.
        $id = $reunion ? $reunion->conversacion_id : ($datos['conversacion_id'] ?? null);

        if ($id && ! $reunion) {
            $this->autorizar($request, $id);
        }

        $respuesta = (new Jarvis($reunion?->estado->estaAbierta() ? $reunion : null))
            ->continueOrStart($id, as: $request->user())
            ->stream($datos['mensaje'], model: config('jarvis.chat.modelo'));

        // La conversación se crea al terminar de responder; recién ahí se asocia a la reunión.
        if ($reunion && ! $reunion->conversacion_id) {
            $respuesta->then(function (StreamedAgentResponse $completa) use ($reunion) {
                if ($completa->conversationId) {
                    $reunion->update(['conversacion_id' => $completa->conversationId]);
                }
            });
        }

        $http = $respuesta->toResponse($request);
        $http->headers->set('X-Conversacion-Id', (string) ($respuesta->conversationId ?? $id));
        // Evita que un proxy acumule la respuesta en lugar de enviarla en vivo.
        $http->headers->set('X-Accel-Buffering', 'no');

        return $http;
    }

    private function autorizar(Request $request, string $id): void
    {
        $usuario = $request->user();

        abort_unless($this->conversaciones->conversationBelongsTo(
            $id,
            Conversation::participantType($usuario),
            Conversation::participantKey($usuario),
        ), 404);
    }
}
