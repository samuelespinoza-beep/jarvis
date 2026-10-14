<?php

namespace App\Jobs;

use App\Enums\EstadoFragmento;
use App\Models\FragmentoAudio;
use App\Services\CierreReunion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\TranscriptionSegment;
use Laravel\Ai\Responses\TranscriptionResponse;
use Laravel\Ai\Transcription;
use Throwable;

#[Tries(3)]
#[Backoff(15, 60)]
#[Timeout(330)]
#[DeleteWhenMissingModels]
class TranscribirFragmento implements ShouldQueue
{
    use Queueable;

    public function __construct(public FragmentoAudio $fragmento) {}

    public function handle(CierreReunion $cierre): void
    {
        $fragmento = $this->fragmento;

        if ($fragmento->estado !== EstadoFragmento::Listo) {
            $fragmento->update([
                'estado' => EstadoFragmento::Transcribiendo,
                'intentos' => $fragmento->intentos + 1,
                'error' => null,
            ]);

            $this->guardar($fragmento, $this->transcribir($fragmento));

            if (config('jarvis.resumen.por_tramos')) {
                ResumirTramo::dispatch($fragmento);
            }
        }

        $cierre->verificar($fragmento->reunion);
    }

    public function failed(?Throwable $excepcion): void
    {
        $this->fragmento->update([
            'estado' => EstadoFragmento::Error,
            'error' => Str::limit($excepcion?->getMessage() ?? 'Error desconocido.', 1000),
        ]);

        app(CierreReunion::class)->verificar($this->fragmento->reunion);
    }

    private function transcribir(FragmentoAudio $fragmento): TranscriptionResponse
    {
        $config = config('jarvis.transcripcion');

        return Transcription::fromStorage($fragmento->ruta_archivo, 'local')
            ->language($config['idioma'])
            ->diarize()
            // Los modelos con diarización exigen chunking_strategy para audios de más de 30 segundos.
            ->withProviderOptions(['chunking_strategy' => 'auto'])
            ->timeout($config['timeout_segundos'])
            ->generate(model: $config['modelo']);
    }

    private function guardar(FragmentoAudio $fragmento, TranscriptionResponse $respuesta): void
    {
        $desplazamiento = $fragmento->desplazamientoSegundos();

        $segmentos = $respuesta->segments
            ->filter(fn (TranscriptionSegment $segmento) => trim($segmento->text) !== '')
            ->values();

        // Si el proveedor no devuelve segmentos, se guarda el texto completo como uno solo.
        if ($segmentos->isEmpty() && trim($respuesta->text) !== '') {
            $segmentos = collect([
                new TranscriptionSegment($respuesta->text, '', 0, $fragmento->duracion_segundos),
            ]);
        }

        DB::transaction(function () use ($fragmento, $respuesta, $segmentos, $desplazamiento) {
            $fragmento->segmentos()->delete();

            $segmentos->each(fn (TranscriptionSegment $segmento, int $indice) => $fragmento->segmentos()->create([
                'reunion_id' => $fragmento->reunion_id,
                'orden' => $fragmento->orden * 10000 + $indice,
                'hablante' => $segmento->speaker ?: null,
                'inicio_segundos' => $desplazamiento + $segmento->startSeconds,
                'fin_segundos' => $desplazamiento + $segmento->endSeconds,
                'texto' => trim($segmento->text),
            ]));

            $fragmento->update(['estado' => EstadoFragmento::Listo]);

            $fragmento->reunion()->increment('costo_audio_segundos', (int) round(
                $respuesta->usage->audioSeconds ?? $fragmento->duracion_segundos
            ));
            $fragmento->reunion()->increment('costo_tokens', $respuesta->usage->inputTokens + $respuesta->usage->outputTokens);
        });
    }
}
