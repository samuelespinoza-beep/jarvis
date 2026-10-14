<?php

namespace Tests;

use App\Ai\Agents\ResumidorReunion;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Transcription;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Los tests nunca deben llamar a OpenAI ni a ningún servicio externo.
        // Solo se permite el SSR local de Vite, que Inertia consulta si el servidor de desarrollo está activo.
        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://127.0.0.1:*', 'http://localhost:*']);
        Transcription::fake()->preventStrayTranscriptions();
        // Sin respuesta definida, el fake devuelve datos inventados que cumplen el esquema del acta.
        ResumidorReunion::fake();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
