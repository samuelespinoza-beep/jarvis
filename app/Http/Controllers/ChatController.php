<?php

namespace App\Http\Controllers;

use App\Ai\Agents\Jarvis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Symfony\Component\HttpFoundation\Response;

class ChatController extends Controller
{
    public function __construct(private ConversationStore $conversaciones) {}

    /**
     * Mensajes de una conversación. Sin ID, devuelve la última conversación
     * del usuario con Jarvis.
     */
    public function mensajes(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $id = $request->string('conversacion')->toString()
            ?: $this->conversaciones->latestConversationId(
                Conversation::participantType($usuario),
                Conversation::participantKey($usuario),
                Jarvis::class,
            );

        if (! $id) {
            return response()->json(['conversacion_id' => null, 'mensajes' => []]);
        }

        $this->autorizar($request, $id);

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
            'conversacion_id' => ['nullable', 'string', 'max:36'],
        ]);

        $id = $datos['conversacion_id'] ?? null;

        if ($id) {
            $this->autorizar($request, $id);
        }

        $respuesta = (new Jarvis)
            ->continueOrStart($id, as: $request->user())
            ->stream($datos['mensaje'], model: config('jarvis.chat.modelo'));

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
