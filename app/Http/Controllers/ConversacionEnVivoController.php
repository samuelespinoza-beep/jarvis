<?php

namespace App\Http\Controllers;

use App\Ai\Agents\Jarvis;
use App\Ai\Tools\BuscarEnInternet;
use App\Ai\Tools\ConsultarReuniones;
use App\Ai\Tools\LeerPagina;
use App\Ai\Tools\LeerReunion;
use App\Ai\Tools\PreguntarAlChat;
use App\Ai\Tools\UltimosVideosYoutube;
use App\Models\Reunion;
use App\Support\DocumentoChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\MessageStatus;
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
     * Además está PreguntarAlChat, que necesita saber de qué chat se trata.
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

    // Historial del chat que recibe la voz al empezar: mensajes y caracteres como máximo.
    private const HISTORIAL_MENSAJES = 20;

    private const HISTORIAL_CARACTERES = 12000;

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
            'conversacion_id' => ['nullable', 'string', 'max:36'],
        ]);

        $config = config('jarvis.conversacion');
        $reunion = isset($datos['reunion_id']) ? Reunion::query()->find($datos['reunion_id']) : null;
        $conversacionId = $this->conversacionDelChat($request, $reunion, $datos['conversacion_id'] ?? null);
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
                        'instructions' => $this->instrucciones($reunion).$this->historial($conversacionId),
                        'audio' => [
                            'input' => [
                                'noise_reduction' => ['type' => $config['microfono']],
                                'transcription' => [
                                    'model' => config('jarvis.en_vivo.modelo'),
                                    'language' => config('jarvis.transcripcion.idioma'),
                                ],
                                'turn_detection' => $this->deteccionDeTurnos($config['deteccion']),
                            ],
                            'output' => ['voice' => $voz],
                        ],
                        'tools' => array_map($this->definicion(...), $this->herramientas($reunion, $conversacionId)),
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
            'interrumpir' => $config['deteccion']['interrumpir'],
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
            'reunion_id' => ['nullable', 'string', Rule::exists('reuniones', 'id')],
            'conversacion_id' => ['nullable', 'string', 'max:36'],
        ]);

        $reunion = isset($datos['reunion_id']) ? Reunion::query()->find($datos['reunion_id']) : null;
        $conversacionId = $this->conversacionDelChat($request, $reunion, $datos['conversacion_id'] ?? null);

        $herramienta = collect($this->herramientas($reunion, $conversacionId))
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
     * general), para verlo después y seguir escribiendo sobre eso. El
     * navegador lo envía por partes mientras se habla, así que un mensaje con
     * un ID ya guardado no se duplica: se actualiza su texto.
     */
    public function guardar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'reunion_id' => ['nullable', 'string', Rule::exists('reuniones', 'id')],
            'conversacion_id' => ['nullable', 'string', 'max:36'],
            'mensajes' => ['required', 'array', 'min:1', 'max:300'],
            'mensajes.*.rol' => ['required', Rule::in(['usuario', 'jarvis'])],
            'mensajes.*.contenido' => ['required', 'string', 'max:20000'],
            'mensajes.*.creado_en' => ['nullable', 'date'],
            // ID del mensaje, para guardar por partes sin duplicar.
            'mensajes.*.id' => ['nullable', 'uuid'],
        ]);

        $usuario = $request->user();
        $tipo = Conversation::participantType($usuario);
        $clave = Conversation::participantKey($usuario);
        $reunion = isset($datos['reunion_id']) ? Reunion::query()->find($datos['reunion_id']) : null;
        $id = $this->conversacionDelChat($request, $reunion, $datos['conversacion_id'] ?? null);

        if (! $id) {
            $id = $this->conversaciones->storeConversation($tipo, $clave, 'Conversación por voz');
            $reunion?->update(['conversacion_id' => $id]);
        }

        foreach ($datos['mensajes'] as $mensaje) {
            $mensajeId = $mensaje['id'] ?? (string) Str::uuid7();
            $existente = ConversationMessage::query()->find($mensajeId, ['id', 'conversation_id']);

            // Se guarda por partes mientras se habla: lo ya guardado solo se actualiza.
            if ($existente) {
                if ($existente->conversation_id === $id) {
                    ConversationMessage::query()->whereKey($mensajeId)->update([
                        'content' => $mensaje['contenido'],
                        'updated_at' => now(),
                    ]);
                }

                continue;
            }

            // A la hora en que se dijo, para que quede en orden con lo escrito mientras tanto (un documento, por ejemplo).
            $hora = isset($mensaje['creado_en']) ? Carbon::parse($mensaje['creado_en'])->timezone(config('app.timezone'))->min(now()) : now();

            // Igual que guarda el SDK los mensajes del chat, pero sin pasos de herramientas.
            ConversationMessage::query()->insert([
                'id' => $mensajeId,
                'conversation_id' => $id,
                'participant_type' => $tipo,
                'participant_id' => $clave,
                'agent' => Jarvis::class,
                'role' => $mensaje['rol'] === 'usuario' ? 'user' : 'assistant',
                'content' => $mensaje['contenido'],
                'attachments' => '[]',
                'steps' => '[]',
                'usage' => '[]',
                'meta' => json_encode(['en_vivo' => true]),
                'status' => MessageStatus::Completed->value,
                'created_at' => $hora,
                'updated_at' => $hora,
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
        return (new Jarvis($reunion))->instruccionesComunes().<<<'TXT'

        Conversación por voz en vivo (ahora mismo):
        - Estás hablando por voz con el usuario. Responde como en una conversación: breve (1 a 3 frases,
          salvo que pidan detalle), sin Markdown, listas, símbolos ni direcciones web.
        - Ante una decisión, di primero tu postura en una frase y el dato más importante que te falta;
          ofrece desarrollar el análisis en vez de soltarlo completo. La directiva anti-complacencia aplica
          igual: hablar breve no es dar la razón.
        - Para buscar en internet usa BuscarEnInternet. Antes de usar una herramienta, avisa en pocas
          palabras ("Un momento, lo reviso").
        - Si hay enlaces, menciona solo el nombre del sitio: lo conversado queda escrito en el chat.
        - El chat escrito sigue abierto mientras conversan. Si el usuario quiere mandarte un documento,
          dile que lo adjunte con el clip de la caja de texto y lo envíe; tú lo esperas.
        - Cuando escribe o adjunta algo, primero te llega un aviso de que el chat escrito está respondiendo:
          espera esa respuesta sin adelantarte. Luego te llega lo que respondió: es tu respuesta, ya escrita en
          pantalla. Dila en voz alta con naturalidad, sin decir que viene del chat.
        - Para detalles de un documento (cifras, fechas, cláusulas) o de lo escrito en el chat, usa
          PreguntarAlChat: el chat escrito tiene los documentos completos. No inventes lo que no sepas.
        TXT;
    }

    /**
     * Lo último del chat (escrito o por voz, con los resúmenes de documentos),
     * para que la voz siga la conversación en vez de empezar sin memoria.
     */
    private function historial(?string $conversacionId): string
    {
        if (! $conversacionId) {
            return '';
        }

        $mensajes = ConversationMessage::query()
            ->where('conversation_id', $conversacionId)
            ->whereIn('role', ['user', 'assistant'])
            ->where('content', '!=', '')
            ->latest()
            ->orderByDesc('id')
            ->limit(self::HISTORIAL_MENSAJES)
            ->get(['role', 'content', 'attachments']);

        $lineas = [];
        $caracteres = 0;

        // Del más reciente hacia atrás, hasta llenar el espacio.
        foreach ($mensajes as $mensaje) {
            $adjunto = DocumentoChat::delMensaje($mensaje->attachments);
            $quien = $mensaje->role === 'user'
                ? 'Usuario'.($adjunto ? ' (adjuntó '.DocumentoChat::describir($adjunto).')' : '')
                : 'Asistente';
            $linea = "{$quien}: ".Str::limit(trim($mensaje->content), 1500);

            if ($caracteres + mb_strlen($linea) > self::HISTORIAL_CARACTERES) {
                break;
            }

            $caracteres += mb_strlen($linea);
            array_unshift($lineas, $linea);
        }

        if (! $lineas) {
            return '';
        }

        return "\n\nLo conversado antes en este chat, del más antiguo al más reciente (continúa desde ahí):\n"
            .implode("\n", $lineas);
    }

    /**
     * El chat al que pertenece la voz: el de la reunión o, en el chat general,
     * el que diga el navegador si es del usuario.
     */
    private function conversacionDelChat(Request $request, ?Reunion $reunion, ?string $id): ?string
    {
        if ($reunion) {
            return $reunion->conversacion_id;
        }

        if ($id) {
            $usuario = $request->user();
            abort_unless($this->conversaciones->conversationBelongsTo(
                $id, Conversation::participantType($usuario), Conversation::participantKey($usuario),
            ), 404);
        }

        return $id;
    }

    /**
     * Cuándo cuenta como que el usuario habló. Con server_vad, el umbral deja
     * fuera golpes, eco del parlante y ruido de fondo; semantic_vad decide por
     * el sentido de la frase, pero se dispara con cualquier sonido.
     *
     * @param  array{tipo: string, umbral: float, relleno_ms: int, silencio_ms: int, interrumpir: bool}  $deteccion
     * @return array<string, mixed>
     */
    private function deteccionDeTurnos(array $deteccion): array
    {
        $comun = ['interrupt_response' => $deteccion['interrumpir'], 'create_response' => true];

        if ($deteccion['tipo'] === 'semantic_vad') {
            return ['type' => 'semantic_vad', 'eagerness' => 'low', ...$comun];
        }

        return [
            'type' => 'server_vad',
            'threshold' => $deteccion['umbral'],
            'prefix_padding_ms' => $deteccion['relleno_ms'],
            'silence_duration_ms' => $deteccion['silencio_ms'],
            ...$comun,
        ];
    }

    /**
     * @return list<Tool>
     */
    private function herramientas(?Reunion $reunion, ?string $conversacionId): array
    {
        return [
            ...array_map(fn (string $clase) => app($clase), self::HERRAMIENTAS),
            new PreguntarAlChat($reunion, $conversacionId),
        ];
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
