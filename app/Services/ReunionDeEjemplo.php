<?php

namespace App\Services;

use App\Ai\Agents\Jarvis;
use App\Enums\EstadoFragmento;
use App\Enums\EstadoReunion;
use App\Models\FragmentoAudio;
use App\Models\Resumen;
use App\Models\Reunion;
use App\Models\SegmentoTranscripcion;
use App\Models\User;
use App\Support\DocumentoChat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

/**
 * Reunión que trae la versión instalada para no empezar vacía: su
 * transcripción, minuto a minuto, chat (con sus documentos) y acta. Vive como
 * paquete en database/datos/reunion-ejemplo: reunion.json y los archivos.
 */
class ReunionDeEjemplo
{
    public function __construct(private EspejoReunion $espejo) {}

    public static function carpeta(): string
    {
        return database_path('datos/reunion-ejemplo');
    }

    /**
     * Guarda una reunión como paquete de ejemplo (reemplaza el anterior).
     */
    public function exportar(Reunion $reunion, ?string $titulo = null): void
    {
        $carpeta = self::carpeta();
        File::deleteDirectory($carpeta);
        File::ensureDirectoryExists($carpeta);

        $archivos = [];

        $mensajes = $reunion->mensajesDelChat(soloDuranteLaReunion: false)->map(function (ConversationMessage $mensaje) use (&$archivos) {
            $adjunto = collect($mensaje->attachments)->first(fn (array $adjunto) => in_array($adjunto['type'] ?? null, DocumentoChat::TIPOS, true));
            $archivo = null;

            if ($adjunto && Storage::disk($adjunto['disk'] ?? 'local')->exists($adjunto['path'])) {
                $archivo = 'adjunto-'.(count($archivos) + 1).'.'.pathinfo($adjunto['path'], PATHINFO_EXTENSION);
                File::put(self::carpeta()."/{$archivo}", Storage::disk($adjunto['disk'] ?? 'local')->get($adjunto['path']));
                $archivos[] = $archivo;
            }

            return [
                'rol' => $mensaje->role,
                'contenido' => $mensaje->content,
                'en_vivo' => (bool) ($mensaje->meta['en_vivo'] ?? false),
                'creado_en' => $mensaje->created_at->toIso8601String(),
                'adjunto' => $archivo ? ['archivo' => $archivo, 'nombre' => $adjunto['name'] ?? $archivo] : null,
            ];
        })->all();

        $datos = [
            'titulo' => $titulo ?? $reunion->titulo,
            'agenda' => $reunion->agenda,
            'iniciada_en' => $reunion->iniciada_en->toIso8601String(),
            'finalizada_en' => $reunion->finalizada_en?->toIso8601String(),
            'duracion_segundos' => $reunion->duracion_segundos,
            'costo_audio_segundos' => $reunion->costo_audio_segundos,
            'costo_tokens' => $reunion->costo_tokens,
            'fragmentos' => $reunion->fragmentos()->with('segmentos')->get()->map(fn (FragmentoAudio $fragmento) => [
                'orden' => $fragmento->orden,
                'duracion_segundos' => $fragmento->duracion_segundos,
                'resumen' => $fragmento->resumen,
                'segmentos' => $fragmento->segmentos->map(fn (SegmentoTranscripcion $segmento) => [
                    'orden' => $segmento->orden,
                    'hablante' => $segmento->hablante,
                    'inicio_segundos' => $segmento->inicio_segundos,
                    'fin_segundos' => $segmento->fin_segundos,
                    'texto' => $segmento->texto,
                ])->all(),
            ])->all(),
            'resumenes' => $reunion->resumenes()->get()->reverse()->map(fn (Resumen $resumen) => [
                'version' => $resumen->version,
                'modelo' => $resumen->modelo,
                'contenido' => $resumen->contenido,
                'tokens' => $resumen->tokens,
                'creado_en' => $resumen->created_at?->toIso8601String(),
            ])->values()->all(),
            'chat' => $mensajes,
        ];

        File::put("{$carpeta}/reunion.json", json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Carga la reunión de ejemplo si la base todavía no tiene ninguna
     * reunión (la primera instalación). Al actualizar no hace nada.
     */
    public function importarSiEstaVacia(User $usuario): ?Reunion
    {
        $archivo = self::carpeta().'/reunion.json';

        if (Reunion::query()->exists() || ! File::exists($archivo)) {
            return null;
        }

        $datos = json_decode(File::get($archivo), true);

        $reunion = DB::transaction(function () use ($datos, $usuario) {
            $reunion = Reunion::create([
                'titulo' => $datos['titulo'],
                'agenda' => $datos['agenda'],
                'estado' => EstadoReunion::Completada,
                'iniciada_en' => $this->hora($datos['iniciada_en']),
                'finalizada_en' => isset($datos['finalizada_en']) ? $this->hora($datos['finalizada_en']) : null,
                'duracion_segundos' => $datos['duracion_segundos'],
                'creada_por' => $usuario->id,
            ]);
            $reunion->forceFill([
                'transcripcion_lista_en' => $reunion->finalizada_en ?? $reunion->iniciada_en,
                'costo_audio_segundos' => $datos['costo_audio_segundos'] ?? 0,
                'costo_tokens' => $datos['costo_tokens'] ?? 0,
            ])->save();

            foreach ($datos['fragmentos'] as $datosFragmento) {
                $fragmento = $reunion->fragmentos()->create([
                    'orden' => $datosFragmento['orden'],
                    // Sin audio: el ejemplo solo trae lo transcrito.
                    'ruta_archivo' => $reunion->carpeta().sprintf('/audio/%04d.webm', $datosFragmento['orden']),
                    'mime' => 'audio/webm',
                    'tamano_bytes' => 0,
                    'duracion_segundos' => $datosFragmento['duracion_segundos'],
                    'estado' => EstadoFragmento::Listo,
                    'resumen' => $datosFragmento['resumen'],
                ]);

                foreach ($datosFragmento['segmentos'] as $segmento) {
                    $fragmento->segmentos()->create([...$segmento, 'reunion_id' => $reunion->id]);
                }
            }

            foreach ($datos['resumenes'] as $datosResumen) {
                $resumen = $reunion->resumenes()->create([
                    'version' => $datosResumen['version'],
                    'modelo' => $datosResumen['modelo'],
                    'contenido' => $datosResumen['contenido'],
                    'tokens' => $datosResumen['tokens'],
                ]);

                if (isset($datosResumen['creado_en'])) {
                    $resumen->forceFill(['created_at' => $this->hora($datosResumen['creado_en'])])->save();
                }
            }

            $this->importarChat($reunion, $usuario, $datos['chat'] ?? []);

            return $reunion;
        });

        foreach ($reunion->resumenes()->get() as $resumen) {
            Storage::disk('local')->put(
                $reunion->carpeta()."/resumenes/v{$resumen->version}.json",
                json_encode(['version' => $resumen->version, 'modelo' => $resumen->modelo, ...$resumen->contenido], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );
        }

        $this->espejo->guardar($reunion);

        return $reunion;
    }

    /**
     * @param  list<array<string, mixed>>  $mensajes
     */
    private function importarChat(Reunion $reunion, User $usuario, array $mensajes): void
    {
        if (! $mensajes) {
            return;
        }

        $tipo = Conversation::participantType($usuario);
        $clave = Conversation::participantKey($usuario);
        $conversacion = resolve(ConversationStore::class)->storeConversation($tipo, $clave, $reunion->titulo ?: 'Reunión');
        $reunion->update(['conversacion_id' => $conversacion]);

        foreach ($mensajes as $mensaje) {
            $adjuntos = [];

            // Cada instalación guarda su propia copia del archivo.
            if ($mensaje['adjunto'] ?? null) {
                $origen = self::carpeta().'/'.$mensaje['adjunto']['archivo'];
                $ruta = 'documentos/'.Str::uuid().'.'.pathinfo($origen, PATHINFO_EXTENSION);
                Storage::disk('local')->put($ruta, File::get($origen));
                $adjuntos[] = [
                    'type' => DocumentoChat::esImagen($ruta) ? 'stored-image' : 'stored-document',
                    'name' => $mensaje['adjunto']['nombre'],
                    'path' => $ruta,
                    'disk' => 'local',
                ];
            }

            $hora = $this->hora($mensaje['creado_en']);

            ConversationMessage::query()->insert([
                'id' => (string) Str::uuid7(),
                'conversation_id' => $conversacion,
                'participant_type' => $tipo,
                'participant_id' => $clave,
                'agent' => Jarvis::class,
                'role' => $mensaje['rol'],
                'content' => $mensaje['contenido'],
                'attachments' => json_encode($adjuntos),
                'steps' => '[]',
                'usage' => '[]',
                'meta' => json_encode($mensaje['en_vivo'] ? ['en_vivo' => true] : []),
                'status' => MessageStatus::Completed->value,
                'created_at' => $hora,
                'updated_at' => $hora,
            ]);
        }
    }

    // Las horas del paquete traen su zona; se guardan en la de la app, como el resto.
    private function hora(string $iso): Carbon
    {
        return Carbon::parse($iso)->timezone(config('app.timezone'));
    }
}
