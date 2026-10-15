<?php

namespace App\Http\Controllers;

use App\Ai\Agents\Jarvis;
use App\Ai\Tools\BuscarEnInternet;
use App\Ai\Tools\ConsultarReuniones;
use App\Ai\Tools\LeerPagina;
use App\Ai\Tools\LeerReunion;
use App\Ai\Tools\UltimosVideosYoutube;
use App\Models\Reunion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Tools\Request as PedidoHerramienta;
use Laravel\Ai\Tools\ToolNameResolver;
use Throwable;

/**
 * Conversación por voz en vivo con el asistente (OpenAI Realtime). El
 * navegador habla directamente con OpenAI por WebRTC; el servidor entrega la
 * llave temporal, ejecuta las herramientas que pida el modelo y, al terminar,
 * guarda lo conversado en el chat.
 */
class ConversacionEnVivoController extends Controller
{
    /**
     * Herramientas que el modelo de voz puede pedir. Las mismas del chat; la
     * búsqueda web va por BuscarEnInternet porque en tiempo real no existe.
     *
     * @var list<class-string<Tool>>
     */
    private const HERRAMIENTAS = [
        BuscarEnInternet::class,
        LeerPagina::class,
        UltimosVideosYoutube::class,
        ConsultarReuniones::class,
        LeerReunion::class,
    ];

    public function __construct(private ConversationStore $conversaciones) {}

    /**
     * Entrega la llave temporal para abrir la conversación por voz. La llave
     * real (OPENAI_API_KEY) nunca sale del servidor.
     */
    public function sesion(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'voz' => ['nullable', 'string', Rule::in(array_keys(config('jarvis.voz.voces')))],
            'reunion_id' => ['nullable', 'string', Rule::exists('reuniones', 'id')],
        ]);

        $config = config('jarvis.conversacion');
        $reunion = isset($datos['reunion_id']) ? Reunion::query()->find($datos['reunion_id']) : null;
        $voz = $datos['voz'] ?? config('jarvis.voz.voz');
        $voz = $config['voces_equivalentes'][$voz] ?? $voz;

        try {
            $respuesta = Http::baseUrl(config('ai.providers.openai.url'))
                ->withToken(config('ai.providers.openai.key'))
                ->timeout(15)
                ->post('realtime/client_secrets', [
                    'expires_after' => ['anchor' => 'created_at', 'seconds' => $config['segundos_llave']],
                    'session' => [
                        'type' => 'realtime',
                        'model' => $config['modelo'],
                        'instructions' => $this->instrucciones($reunion),
                        'audio' => [
                            'input' => [
                                'noise_reduction' => ['type' => 'near_field'],
                                'transcription' => [
                                    'model' => config('jarvis.en_vivo.modelo'),
                                    'language' => config('jarvis.transcripcion.idioma'),
                                ],
                                // Detecta cuándo el usuario terminó de hablar por el sentido de la frase, no solo por el silencio.
                                'turn_detection' => ['type' => 'semantic_vad'],
                            ],
                            'output' => ['voice' => $voz],
                        ],
                        'tools' => array_map($this->definicion(...), $this->herramientas()),
                    ],
                ])
                ->throw();
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'No se pudo iniciar la conversación en vivo.'], 502);
        }

        return response()->json([
            'llave' => $respuesta->json('value'),
            'voz' => $voz,
            'minutos_inactividad' => $config['minutos_inactividad'],
        ]);
    }

    /**
     * Ejecuta una herramienta que pidió el modelo de voz y devuelve su resultado.
     */
    public function herramienta(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:100'],
            'argumentos' => ['nullable', 'array'],
        ]);

        $herramienta = collect($this->herramientas())
            ->first(fn (Tool $herramienta) => ToolNameResolver::resolve($herramienta) === $datos['nombre']);

        abort_unless($herramienta, 404, 'Herramienta desconocida.');

        try {
            $resultado = (string) $herramienta->handle(new PedidoHerramienta($datos['argumentos'] ?? []));
        } catch (Throwable $e) {
            report($e);
            $resultado = 'La herramienta falló: '.Str::limit($e->getMessage(), 200);
        }

        return response()->json(['resultado' => $resultado]);
    }

    /**
     * Guarda lo conversado por voz como mensajes del chat (de la reunión o
     * general), para verlo después y seguir escribiendo sobre eso.
     */
    public function guardar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'reunion_id' => ['nullable', 'string', Rule::exists('reuniones', 'id')],
            'conversacion_id' => ['nullable', 'string', 'max:36'],
            'mensajes' => ['required', 'array', 'min:1', 'max:300'],
            'mensajes.*.rol' => ['required', Rule::in(['usuario', 'jarvis'])],
            'mensajes.*.contenido' => ['required', 'string', 'max:20000'],
        ]);

        $usuario = $request->user();
        $tipo = Conversation::participantType($usuario);
        $clave = Conversation::participantKey($usuario);
        $reunion = isset($datos['reunion_id']) ? Reunion::query()->find($datos['reunion_id']) : null;
        $id = $reunion ? $reunion->conversacion_id : ($datos['conversacion_id'] ?? null);

        if ($id && ! $reunion) {
            abort_unless($this->conversaciones->conversationBelongsTo($id, $tipo, $clave), 404);
        }

        if (! $id) {
            $id = $this->conversaciones->storeConversation($tipo, $clave, 'Conversación por voz');
            $reunion?->update(['conversacion_id' => $id]);
        }

        foreach ($datos['mensajes'] as $mensaje) {
            if ($mensaje['rol'] === 'usuario') {
                $this->conversaciones->storeUserMessage($id, $tipo, $clave, Jarvis::class, new UserMessage($mensaje['contenido']));

                continue;
            }

            // Igual que guarda el SDK las respuestas del chat, pero sin pasos de herramientas.
            ConversationMessage::query()->insert([
                'id' => (string) Str::uuid7(),
                'conversation_id' => $id,
                'participant_type' => $tipo,
                'participant_id' => $clave,
                'agent' => Jarvis::class,
                'role' => 'assistant',
                'content' => $mensaje['contenido'],
                'attachments' => '[]',
                'steps' => '[]',
                'usage' => '[]',
                'meta' => '[]',
                'status' => MessageStatus::Completed->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Conversation::query()->whereKey($id)->update(['updated_at' => now()]);

        return response()->json(['conversacion_id' => $id]);
    }

    /**
     * Las instrucciones del chat (personalidad, reglas, reunión en curso) más
     * las propias de hablar por voz.
     */
    private function instrucciones(?Reunion $reunion): string
    {
        return (new Jarvis($reunion))->instructions().<<<'TXT'

        Conversación por voz en vivo (ahora mismo):
        - Estás hablando por voz con el usuario. Responde como en una conversación: breve (1 a 3 frases,
          salvo que pidan detalle), sin Markdown, listas, símbolos ni direcciones web.
        - Para buscar en internet usa BuscarEnInternet. Antes de usar una herramienta, avisa en pocas
          palabras ("Un momento, lo reviso").
        - Si hay enlaces, menciona solo el nombre del sitio: lo conversado queda escrito en el chat.
        TXT;
    }

    /**
     * @return list<Tool>
     */
    private function herramientas(): array
    {
        return array_map(fn (string $clase) => app($clase), self::HERRAMIENTAS);
    }

    /**
     * Definición de una herramienta en el formato de funciones de OpenAI Realtime.
     *
     * @return array<string, mixed>
     */
    private function definicion(Tool $herramienta): array
    {
        $esquema = $herramienta->schema(new JsonSchemaTypeFactory);
        $esquema = filled($esquema) ? (new ObjectSchema($esquema))->toSchema() : [];

        return [
            'type' => 'function',
            'name' => ToolNameResolver::resolve($herramienta),
            'description' => (string) $herramienta->description(),
            'parameters' => [
                'type' => 'object',
                'properties' => $esquema['properties'] ?? (object) [],
                'required' => $esquema['required'] ?? [],
            ],
        ];
    }
}
