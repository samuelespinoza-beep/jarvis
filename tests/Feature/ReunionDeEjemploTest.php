<?php

namespace Tests\Feature;

use App\Enums\EstadoReunion;
use App\Http\Middleware\SesionEscritorio;
use App\Models\Reunion;
use App\Services\ReunionDeEjemplo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReunionDeEjemploTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_install_starts_with_the_example_meeting_and_an_update_does_not_duplicate_it()
    {
        Storage::fake('local');
        $usuario = SesionEscritorio::usuarioLocal();
        $ejemplo = app(ReunionDeEjemplo::class);

        $reunion = $ejemplo->importarSiEstaVacia($usuario);

        $this->assertNotNull($reunion);
        $this->assertStringContainsString('(reunión de ejemplo)', $reunion->titulo);
        $this->assertSame(EstadoReunion::Completada, $reunion->estado);
        $this->assertNotEmpty($reunion->agenda);
        $this->assertNotSame('', $reunion->transcripcionComoTexto());
        $this->assertNotNull($reunion->resumen);
        $this->assertNotEmpty($reunion->resumen->contenido['acuerdos']);
        $this->assertStringContainsString('(por voz)', $reunion->chatComoTexto());
        Storage::disk('local')->assertExists("reuniones/{$reunion->id}/reunion.json");

        // El PDF del chat queda en el disco de esta instalación y se puede descargar.
        $documentos = $reunion->documentosDelChat(soloDuranteLaReunion: false);
        $this->assertCount(1, $documentos);
        Storage::disk('local')->assertExists($documentos[0]['path']);

        // El chat queda a nombre del usuario local.
        $this->actingAs($usuario)
            ->getJson(route('chat.mensajes', ['reunion' => $reunion->id]))
            ->assertOk()
            ->assertJsonCount(6, 'mensajes');

        // Al actualizar (ya hay reuniones), no se vuelve a cargar.
        $this->assertNull($ejemplo->importarSiEstaVacia($usuario));
        $this->assertSame(1, Reunion::count());
    }

    public function test_the_install_command_loads_it_only_once()
    {
        Storage::fake('local');
        $env = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($env, 'APP_KEY=base64:'.base64_encode(random_bytes(32))."\n");

        $this->artisan('jarvis:instalar', ['--env' => $env])
            ->expectsOutputToContain('Se cargó la reunión de ejemplo')
            ->assertSuccessful();

        $this->artisan('jarvis:instalar', ['--env' => $env])
            ->doesntExpectOutputToContain('Se cargó la reunión de ejemplo')
            ->assertSuccessful();

        $this->assertSame(1, Reunion::count());
        unlink($env);
    }
}
