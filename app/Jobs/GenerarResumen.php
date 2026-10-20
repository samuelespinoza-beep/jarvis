<?php

namespace App\Jobs;

use App\Ai\Agents\ResumidorReunion;
use App\Enums\EstadoReunion;
use App\Models\Reunion;
use App\Services\EspejoReunion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Tries(3)]
#[Backoff(30, 120)]
#[Timeout(300)]
#[DeleteWhenMissingModels]
class GenerarResumen implements ShouldQueue
{
    use Queueable;

    public function __construct(public Reunion $reunion) {}

    public function handle(EspejoReunion $espejo): void
    {
        $reunion = $this->reunion;
        $transcripcion = $reunion->transcripcionComoTexto();
        $modelo = config('jarvis.resumen.modelo');

        if (trim($transcripcion) === '') {
            // Audio sin voz: no tiene sentido pagar una llamada a la IA.
            $contenido = $this->resumenVacio($reunion);
            $modelo = 'ninguno';
            $tokens = 0;
        } else {
            $respuesta = (new ResumidorReunion)->prompt($this->prompt($reunion, $transcripcion), model: $modelo);
            $contenido = $respuesta->toArray();
            $tokens = $respuesta->usage->inputTokens + $respuesta->usage->outputTokens;
        }

        $resumen = DB::transaction(function () use ($reunion, $contenido, $modelo, $tokens) {
            $resumen = $reunion->resumenes()->create([
                'version' => ((int) $reunion->resumenes()->max('version')) + 1,
                'modelo' => $modelo,
                'contenido' => $contenido,
                'tokens' => $tokens,
            ]);

            $reunion->increment('costo_tokens', $tokens);
            $reunion->update([
                'estado' => EstadoReunion::Completada,
                'titulo' => $reunion->titulo ?: ($contenido['titulo_sugerido'] ?? null),
            ]);

            return $resumen;
        });

        Storage::disk('local')->put(
            $reunion->carpeta()."/resumenes/v{$resumen->version}.json",
            json_encode([
                'version' => $resumen->version,
                'modelo' => $resumen->modelo,
                'generado_en' => $resumen->created_at?->toIso8601String(),
                ...$resumen->contenido,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        $espejo->guardar($reunion);
    }

    public function failed(?Throwable $excepcion): void
    {
        Reunion::query()
            ->whereKey($this->reunion->id)
            ->where('estado', EstadoReunion::Procesando)
            ->update(['estado' => EstadoReunion::Error]);
    }

    private function prompt(Reunion $reunion, string $transcripcion): string
    {
        $duracion = intdiv((int) $reunion->duracion_segundos, 60);

        return implode("\n", array_filter([
            $reunion->titulo ? "Título indicado por el usuario: {$reunion->titulo}" : null,
            'Fecha: '.$reunion->iniciada_en->translatedFormat('l j \d\e F \d\e Y, H:i'),
            "Duración: {$duracion} minutos",
            $reunion->agenda
                ? "Agenda:\n".implode("\n", array_map(fn (string $punto, int $i) => ($i + 1).". {$punto}", $reunion->agenda, array_keys($reunion->agenda)))
                : 'La reunión no tenía agenda.',
            '',
            'Transcripción:',
            $transcripcion,
        ], fn (?string $linea) => $linea !== null));
    }

    /**
     * @return array<string, mixed>
     */
    private function resumenVacio(Reunion $reunion): array
    {
        return [
            'titulo_sugerido' => 'Reunión sin conversación detectada',
            'resumen_ejecutivo' => 'No se detectó conversación en el audio grabado.',
            'temas_tratados' => [],
            'puntos_principales' => [],
            'puntos_secundarios' => [],
            'acuerdos' => [],
            'pendientes' => [],
            'riesgos_o_puntos_criticos' => [],
            'sugerencias_de_mejora' => ['Verificar que el micrófono capte la sala antes de iniciar la reunión.'],
            'agenda' => array_map(
                fn (string $punto) => ['punto' => $punto, 'estado' => 'no_tratado', 'comentario' => 'No se detectó conversación.'],
                $reunion->agenda ?? [],
            ),
        ];
    }
}
