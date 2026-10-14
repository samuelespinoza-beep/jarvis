<?php

namespace App\Jobs;

use App\Ai\Agents\ResumidorTramo;
use App\Models\FragmentoAudio;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * Genera el tema y el resumen corto de un tramo ya transcrito. Si falla no
 * afecta a la reunión: el acta final se hace con la transcripción completa.
 */
#[Tries(2)]
#[Backoff(10)]
#[Timeout(90)]
#[DeleteWhenMissingModels]
class ResumirTramo implements ShouldQueue
{
    use Queueable;

    public function __construct(public FragmentoAudio $fragmento) {}

    public function handle(): void
    {
        $fragmento = $this->fragmento;
        $texto = $fragmento->transcripcionComoTexto();

        // Silencio o ruido: no hace falta pagar una llamada a la IA.
        if (trim($texto) === '') {
            $fragmento->update(['resumen' => [
                'tema' => 'Sin conversación',
                'resumen' => 'No se detectó conversación en este tramo.',
                'puntos' => [],
            ]]);

            return;
        }

        $anterior = FragmentoAudio::query()
            ->where('reunion_id', $fragmento->reunion_id)
            ->where('orden', '<', $fragmento->orden)
            ->whereNotNull('resumen')
            ->orderByDesc('orden')
            ->value('resumen');

        $prompt = implode("\n", array_filter([
            $anterior ? 'Tramo anterior — tema: '.($anterior['tema'] ?? '').'. '.($anterior['resumen'] ?? '') : null,
            '',
            'Transcripción de este tramo:',
            $texto,
        ], fn (?string $linea) => $linea !== null));

        $respuesta = (new ResumidorTramo)->prompt($prompt, model: config('jarvis.resumen.modelo'));

        $fragmento->update(['resumen' => $respuesta->toArray()]);
        $fragmento->reunion()->increment('costo_tokens', $respuesta->usage->inputTokens + $respuesta->usage->outputTokens);
    }
}
