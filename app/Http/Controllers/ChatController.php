<?php

namespace App\Http\Controllers;

use App\Ai\Agents\Jarvis;
use App\Http\Resources\MensajeChatResource;
use App\Models\Reunion;
use App\Support\DocumentoChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chat con Jarvis. Cada reunión tiene su conversación: se usa mientras se
 * graba y sigue después de terminarla, junto al acta. Sin reunión es un chat
 * general que empieza en blanco cada vez.
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

        $mensajes = MensajeChatResource::collection(ConversationMessage::query()
            ->where('conversation_id', $id)
            ->whereIn('role', ['user', 'assistant'])
            ->where('content', '!=', '')
            ->oldest()
            ->orderBy('id')
            ->get(['id', 'role', 'content', 'attachments', 'meta', 'created_at']))
            ->resolve();

        return response()->json(['conversacion_id' => $id, 'mensajes' => $mensajes]);
    }

    /**
     * Envía un mensaje a Jarvis y transmite su respuesta como eventos SSE.
     * El ID de la conversación viaja en la cabecera X-Conversacion-Id.
     *
     * Si trae un documento o una imagen, se guarda en storage/app/private/documentos
     * y se adjunta al mensaje. Queda en el historial de la conversación, así que
     * las preguntas siguientes también lo tienen a la vista.
     */
    public function enviar(Request $request): Response
    {
        $datos = $request->validate([
            'mensaje' => ['required', 'string', 'max:4000'],
            'reunion_id' => ['nullable', 'string', Rule::exists('reuniones', 'id')],
            'conversacion_id' => ['nullable', 'string', 'max:36'],
            'documento' => ['nullable', ...DocumentoChat::reglas()],
        ], DocumentoChat::mensajes('documento'));

        $adjuntos = [];

        if ($request->hasFile('documento')) {
            $documento = $request->file('documento');
            $ruta = $documento->storeAs('documentos', Str::uuid().'.'.strtolower($documento->getClientOriginalExtension()));
            $adjuntos[] = DocumentoChat::adjunto($ruta, $documento->getClientOriginalName());
        }

        $reunion = isset($datos['reunion_id']) ? Reunion::query()->find($datos['reunion_id']) : null;

        // En una reunión manda la conversación guardada en ella, no la que diga el navegador.
        $id = $reunion ? $reunion->conversacion_id : ($datos['conversacion_id'] ?? null);

        if ($id && ! $reunion) {
            $this->autorizar($request, $id);
        }

        $respuesta = (new Jarvis($reunion))
            ->continueOrStart($id, as: $request->user())
            ->stream($datos['mensaje'], attachments: $adjuntos, model: config('jarvis.chat.modelo'));

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

    /**
     * Muestra la imagen (o el documento) adjuntada a un mensaje del chat, si el
     * chat es del usuario o de una reunión.
     */
    public function adjunto(Request $request, string $mensaje): Response
    {
        $mensaje = ConversationMessage::query()->findOrFail($mensaje);

        if (! Reunion::query()->where('conversacion_id', $mensaje->conversation_id)->exists()) {
            $this->autorizar($request, $mensaje->conversation_id);
        }

        $adjunto = collect($mensaje->attachments)->first(fn (array $adjunto) => in_array($adjunto['type'] ?? null, DocumentoChat::TIPOS, true));
        $disco = Storage::disk($adjunto['disk'] ?? 'local');

        abort_unless($adjunto && $disco->exists($adjunto['path']), 404);

        return $disco->response($adjunto['path'], $adjunto['name'] ?? null);
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
