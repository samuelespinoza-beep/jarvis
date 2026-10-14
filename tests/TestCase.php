<?php

namespace Tests;

use App\Ai\Agents\ResumidorReunion;
use App\Ai\Agents\ResumidorTramo;
use App\Support\Navegador;
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
        ResumidorTramo::fake();

        // DNS simulado: cualquier dominio es público salvo localhost; las IP se usan tal cual.
        Navegador::$resolverDns = fn (string $host) => match (true) {
            filter_var($host, FILTER_VALIDATE_IP) !== false => [$host],
            $host === 'localhost' => ['127.0.0.1'],
            default => ['93.184.215.14'],
        };
    }

    protected function tearDown(): void
    {
        Navegador::$resolverDns = null;

        parent::tearDown();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
