<?php

namespace App\Http\Resources;

use App\Support\DocumentoChat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Ai\Models\ConversationMessage;

/**
 * Un mensaje del chat tal como lo muestra la app: en el chat y en la pestaña
 * "Chat" de la reunión.
 *
 * @mixin ConversationMessage
 */
class MensajeChatResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $adjunto = DocumentoChat::delMensaje($this->attachments);

        return [
            'id' => $this->id,
            'rol' => $this->role === 'user' ? 'usuario' : 'jarvis',
            'contenido' => $this->content,
            'creado_en' => $this->created_at?->toIso8601String(),
            // Dicho en la conversación por voz en vivo.
            'en_vivo' => (bool) ($this->meta['en_vivo'] ?? false),
            'documento' => $adjunto['nombre'] ?? null,
            'imagen' => ($adjunto['imagen'] ?? false) ? route('chat.adjunto', $this->id) : null,
        ];
    }
}
