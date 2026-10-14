<?php

namespace Tests\Feature;

use App\Ai\Agents\ResumidorTramo;
use App\Enums\EstadoFragmento;
use App\Enums\EstadoReunion;
use App\Jobs\ResumirTramo;
use App\Models\FragmentoAudio;
use App\Models\Reunion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Transcription;
use Tests\TestCase;

class ResumenPorTramosTest extends TestCase
{
    use RefreshDatabase;

    private Reunion $reunion;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->reunion = Reunion::create([
            'estado' => EstadoReunion::Grabando,
            'iniciada_en' => now(),
            'creada_por' => $user->id,
        ]);
    }

    private function tramo(int $orden, ?string $texto, int $duracion = 60): FragmentoAudio
    {
        $fragmento = $this->reunion->fragmentos()->create([
            'orden' => $orden,
            'ruta_archivo' => "x{$orden}.webm",
            'mime' => 'audio/webm',
            'tamano_bytes' => 1,
            'duracion_segundos' => $duracion,
            'estado' => EstadoFragmento::Listo,
        ]);

        if ($texto) {
            $fragmento->segmentos()->create([
                'reunion_id' => $this->reunion->id,
                'orden' => $orden * 10000,
                'hablante' => 'A',
                'inicio_segundos' => ($orden - 1) * 60 + 5,
                'fin_segundos' => ($orden - 1) * 60 + 20,
                'texto' => $texto,
            ]);
        }

        return $fragmento;
    }

    public function test_each_transcribed_minute_gets_its_own_summary()
    {
        Transcription::fake(['Propongo subir el presupuesto de marketing en 10%.']);
        ResumidorTramo::fake([['tema' => 'Presupuesto de marketing', 'resumen' => 'Se propuso subirlo 10%.', 'puntos' => ['+10%']]]);

        $this->postJson(route('reuniones.fragmentos.store', $this->reunion), [
            'audio' => UploadedFile::fake()->create('1.webm', 200, 'audio/webm'),
            'orden' => 1,
            'duracion_segundos' => 60,
        ])->assertCreated();

        $fragmento = $this->reunion->fragmentos()->first();
        $this->assertSame('Presupuesto de marketing', $fragmento->resumen['tema']);
        ResumidorTramo::assertPrompted(fn (AgentPrompt $prompt) => $prompt->model === 'gpt-6-luna'
            && $prompt->contains('Propongo subir el presupuesto de marketing en 10%.'));
    }

    public function test_the_previous_minute_is_sent_as_context()
    {
        ResumidorTramo::fake([['tema' => 'Ventas', 'resumen' => 'Siguen con ventas.', 'puntos' => []]]);

        $this->tramo(1, 'Hablemos de ventas.')->update(['resumen' => ['tema' => 'Ventas', 'resumen' => 'Abrieron el tema de ventas.', 'puntos' => []]]);
        $segundo = $this->tramo(2, 'Las ventas subieron en septiembre.');

        ResumirTramo::dispatchSync($segundo);

        ResumidorTramo::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('Tramo anterior — tema: Ventas. Abrieron el tema de ventas.')
            && $prompt->contains('[01:05] Hablante A: Las ventas subieron en septiembre.'));
    }

    public function test_silent_minutes_do_not_call_the_ai()
    {
        ResumidorTramo::fake()->preventStrayPrompts();

        $silencio = $this->tramo(1, null);
        ResumirTramo::dispatchSync($silencio);

        ResumidorTramo::assertNeverPrompted();
        $this->assertSame('Sin conversación', $silencio->refresh()->resumen['tema']);
    }

    public function test_it_can_be_disabled()
    {
        config(['jarvis.resumen.por_tramos' => false]);
        Queue::fake();
        Transcription::fake(['Hola.']);

        $this->postJson(route('reuniones.fragmentos.store', $this->reunion), [
            'audio' => UploadedFile::fake()->create('1.webm', 200, 'audio/webm'),
            'orden' => 1,
            'duracion_segundos' => 60,
        ])->assertCreated();

        Queue::assertNotPushed(ResumirTramo::class);
    }

    public function test_status_endpoint_returns_the_timeline()
    {
        $this->tramo(1, 'Uno.', 60)->update(['resumen' => ['tema' => 'Apertura', 'resumen' => 'Inicio.', 'puntos' => []]]);
        $this->tramo(2, 'Dos.', 45);

        $this->getJson(route('reuniones.estado', $this->reunion))
            ->assertJsonPath('fragmentos.0.inicio_segundos', 0)
            ->assertJsonPath('fragmentos.0.resumen.tema', 'Apertura')
            ->assertJsonPath('fragmentos.1.inicio_segundos', 60)
            ->assertJsonPath('fragmentos.1.duracion_segundos', 45)
            ->assertJsonPath('fragmentos.1.resumen', null);
    }
}
