<?php

namespace App\Services;

use App\Enums\EstadoFragmento;
use App\Enums\EstadoReunion;
use App\Jobs\GenerarResumen;
use App\Models\Reunion;
use App\Models\SegmentoTranscripcion;
use Illuminate\Support\Facades\Storage;

/**
 * Decide si una reunión terminada ya tiene todo su audio transcrito y, en
 * ese caso, encola la generación del resumen. Se llama al terminar la
 * reunión y cada vez que un fragmento termina de transcribirse, así que
 * puede ejecutarse varias veces sin efectos dobles.
 */
class CierreReunion
{
    public function __construct(private EspejoReunion $espejo) {}

    public function verificar(Reunion $reunion): void
    {
        $reunion->refresh();

        if ($reunion->estado !== EstadoReunion::Procesando) {
            return;
        }

        $estados = $reunion->fragmentos()->pluck('estado');

        if ($estados->contains(fn (EstadoFragmento $estado) => in_array($estado, [EstadoFragmento::Pendiente, EstadoFragmento::Transcribiendo]))) {
            return;
        }

        $transcrita = ! $estados->contains(EstadoFragmento::Error);

        // Solo uno de los procesos que llegue aquí a la vez cambia la reunión.
        if (! $transcrita) {
            $actualizada = Reunion::query()
                ->whereKey($reunion->id)
                ->where('estado', EstadoReunion::Procesando)
                ->update(['estado' => EstadoReunion::Error]);
        } else {
            $actualizada = Reunion::query()
                ->whereKey($reunion->id)
                ->where('estado', EstadoReunion::Procesando)
                ->whereNull('transcripcion_lista_en')
                ->update(['transcripcion_lista_en' => now()]);
        }

        if (! $actualizada) {
            return;
        }

        $reunion->refresh();

        if ($transcrita) {
            $this->guardarTranscripcion($reunion);
            GenerarResumen::dispatch($reunion);
        }

        $this->espejo->guardar($reunion);
    }

    private function guardarTranscripcion(Reunion $reunion): void
    {
        $segmentos = $reunion->segmentos()->with('fragmento:id,orden')->get();

        $datos = [
            'reunion_id' => $reunion->id,
            'texto' => $segmentos->pluck('texto')->implode("\n"),
            'segmentos' => $segmentos->map(fn (SegmentoTranscripcion $segmento) => [
                'fragmento' => $segmento->fragmento->orden,
                'hablante' => $segmento->hablante,
                'inicio_segundos' => $segmento->inicio_segundos,
                'fin_segundos' => $segmento->fin_segundos,
                'texto' => $segmento->texto,
            ])->all(),
        ];

        Storage::disk('local')->put(
            $reunion->carpeta().'/transcripcion.json',
            json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }
}
