<?php

namespace App\Ai\Agents;

use App\Ai\ContenidoExterno;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebSearch;
use Stringable;

/**
 * Busca en internet para la conversación por voz en vivo: el modelo de voz no
 * puede usar la búsqueda web de OpenAI directamente, así que se la pide a este
 * agente y recibe un resumen breve para contarlo en voz alta.
 */
#[MaxSteps(4)]
#[Timeout(60)]
class BuscadorWeb implements Agent, HasTools
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        $hoy = now()->translatedFormat('l j \d\e F \d\e Y');

        return <<<TXT
        Buscas en internet y respondes en español. Hoy es {$hoy} (Lima, Perú).
        - Responde con un resumen breve (3 a 6 frases) con los datos concretos que encontraste.
        - Al final, las fuentes: una por línea, con el enlace directo a la nota o página (no a la portada ni
          a una sección), así: "- Titular de la nota — https://…". Sin Markdown en esas líneas.
        - Si no encuentras nada confiable, dilo. No inventes cifras ni noticias.
        TXT.ContenidoExterno::REGLA;
    }

    public function tools(): iterable
    {
        return [(new WebSearch)->max(3)->location(country: 'PE')];
    }
}
