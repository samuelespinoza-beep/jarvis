<?php

namespace Tests\Feature;

use App\Enums\EstadoFragmento;
use App\Enums\EstadoReunion;
use App\Jobs\TranscribirFragmento;
use App\Models\Reunion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReunionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        // Aquí solo se prueba la grabación; la transcripción tiene su propio test.
        Queue::fake();
    }

    private function iniciarReunion(User $user, ?string $titulo = null): Reunion
    {
        $this->actingAs($user)
            ->postJson(route('reuniones.store'), ['titulo' => $titulo])
            ->assertCreated();

        return Reunion::query()->latest('iniciada_en')->firstOrFail();
    }

    private function subirFragmento(Reunion $reunion, int $orden, int $duracion = 240)
    {
        return $this->postJson(route('reuniones.fragmentos.store', $reunion), [
            'audio' => UploadedFile::fake()->create("{$orden}.webm", 900, 'audio/webm'),
            'orden' => $orden,
            'duracion_segundos' => $duracion,
        ]);
    }

    public function test_guests_cannot_use_jarvis()
    {
        $this->get(route('jarvis'))->assertRedirect(route('login'));
        $this->postJson(route('reuniones.store'))->assertUnauthorized();
    }

    public function test_jarvis_page_shows_the_unfinished_meeting()
    {
        $user = User::factory()->create();
        $reunion = $this->iniciarReunion($user);

        $this->get(route('jarvis'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Jarvis')
                ->where('reunionActiva.id', $reunion->id)
                ->where('reunionActiva.fragmentos_subidos', 0)
                ->where('grabacion.fragmentoSegundos', 60));
    }

    public function test_starting_a_meeting_creates_it_and_its_json_mirror()
    {
        $user = User::factory()->create();
        $reunion = $this->iniciarReunion($user, 'Comité de gerencia');

        $this->assertSame(EstadoReunion::Grabando, $reunion->estado);
        $this->assertSame('Comité de gerencia', $reunion->titulo);
        $this->assertSame($user->id, $reunion->creada_por);

        $espejo = json_decode(Storage::disk('local')->get("reuniones/{$reunion->id}/reunion.json"), true);
        $this->assertSame('grabando', $espejo['estado']);
        $this->assertSame('Comité de gerencia', $espejo['titulo']);
    }

    public function test_only_one_meeting_can_be_recording_at_a_time()
    {
        $user = User::factory()->create();
        $reunion = $this->iniciarReunion($user);

        $this->postJson(route('reuniones.store'))
            ->assertConflict()
            ->assertJsonPath('reunion.id', $reunion->id);
    }

    public function test_fragments_are_stored_in_order()
    {
        $user = User::factory()->create();
        $reunion = $this->iniciarReunion($user);

        $this->subirFragmento($reunion, 1)->assertCreated();
        $this->subirFragmento($reunion, 2, 95)->assertCreated();

        Storage::disk('local')->assertExists("reuniones/{$reunion->id}/audio/0001.webm");
        Storage::disk('local')->assertExists("reuniones/{$reunion->id}/audio/0002.webm");

        $fragmentos = $reunion->fragmentos()->get();
        $this->assertSame([1, 2], $fragmentos->pluck('orden')->all());
        $this->assertSame(EstadoFragmento::Pendiente, $fragmentos[0]->estado);
        Queue::assertPushed(TranscribirFragmento::class, 2);

        $espejo = json_decode(Storage::disk('local')->get("reuniones/{$reunion->id}/reunion.json"), true);
        $this->assertSame(['0001.webm', '0002.webm'], array_column($espejo['fragmentos'], 'archivo'));
    }

    public function test_retrying_an_uploaded_fragment_does_not_duplicate_it()
    {
        $user = User::factory()->create();
        $reunion = $this->iniciarReunion($user);

        $this->subirFragmento($reunion, 1)->assertCreated();
        $this->subirFragmento($reunion, 1)->assertOk();

        $this->assertSame(1, $reunion->fragmentos()->count());
    }

    public function test_fragments_must_be_audio()
    {
        $user = User::factory()->create();
        $reunion = $this->iniciarReunion($user);

        $this->postJson(route('reuniones.fragmentos.store', $reunion), [
            'audio' => UploadedFile::fake()->create('notas.pdf', 10, 'application/pdf'),
            'orden' => 1,
            'duracion_segundos' => 10,
        ])->assertUnprocessable()->assertJsonValidationErrors('audio');
    }

    public function test_finishing_a_meeting_marks_it_for_processing()
    {
        $user = User::factory()->create();
        $reunion = $this->iniciarReunion($user);
        $this->subirFragmento($reunion, 1, 240)->assertCreated();
        $this->subirFragmento($reunion, 2, 95)->assertCreated();

        $this->postJson(route('reuniones.terminar', $reunion))
            ->assertOk()
            ->assertJsonPath('descartada', false)
            ->assertJsonPath('reunion.estado', 'procesando')
            ->assertJsonPath('reunion.duracion_segundos', 335)
            ->assertJsonPath('reunion.fragmentos_subidos', 2);

        $reunion->refresh();
        $this->assertSame(EstadoReunion::Procesando, $reunion->estado);
        $this->assertNotNull($reunion->finalizada_en);

        $this->subirFragmento($reunion, 3)->assertConflict();
        $this->postJson(route('reuniones.terminar', $reunion))->assertConflict();
    }

    public function test_finishing_a_meeting_without_audio_discards_it()
    {
        $user = User::factory()->create();
        $reunion = $this->iniciarReunion($user);

        $this->postJson(route('reuniones.terminar', $reunion))
            ->assertOk()
            ->assertJsonPath('descartada', true);

        $this->assertModelMissing($reunion);
        Storage::disk('local')->assertMissing("reuniones/{$reunion->id}");
    }

    public function test_a_meeting_can_be_paused_and_resumed_keeping_a_single_meeting()
    {
        $user = User::factory()->create();
        $reunion = $this->iniciarReunion($user, 'Comité comercial');

        $this->subirFragmento($reunion, 1, 300)->assertCreated();
        $this->postJson(route('reuniones.pausar', $reunion))
            ->assertOk()
            ->assertJsonPath('reunion.estado', 'pausada')
            ->assertJsonPath('reunion.segundos_grabados', 300);

        $this->assertNotNull($reunion->refresh()->pausada_en);

        // El último tramo puede llegar justo después de pausar.
        $this->subirFragmento($reunion, 2, 20)->assertCreated();

        $this->postJson(route('reuniones.reanudar', $reunion))
            ->assertOk()
            ->assertJsonPath('reunion.estado', 'grabando')
            ->assertJsonPath('reunion.pausada_en', null)
            ->assertJsonPath('reunion.fragmentos_subidos', 2);

        $this->subirFragmento($reunion, 3, 600)->assertCreated();
        $this->postJson(route('reuniones.terminar', $reunion))
            ->assertOk()
            ->assertJsonPath('reunion.estado', 'procesando')
            ->assertJsonPath('reunion.duracion_segundos', 920);

        $this->assertSame(1, Reunion::count());
    }

    public function test_a_paused_meeting_can_be_finished_directly()
    {
        $reunion = $this->iniciarReunion(User::factory()->create());
        $this->subirFragmento($reunion, 1)->assertCreated();
        $this->postJson(route('reuniones.pausar', $reunion))->assertOk();

        $this->postJson(route('reuniones.terminar', $reunion))
            ->assertOk()
            ->assertJsonPath('reunion.estado', 'procesando')
            ->assertJsonPath('reunion.pausada_en', null);
    }

    public function test_no_new_meeting_can_start_while_one_is_paused()
    {
        $reunion = $this->iniciarReunion(User::factory()->create());
        $this->postJson(route('reuniones.pausar', $reunion))->assertOk();

        $this->postJson(route('reuniones.store'))
            ->assertConflict()
            ->assertJsonPath('reunion.id', $reunion->id);
    }

    public function test_jarvis_page_shows_the_paused_meeting_with_its_recorded_time()
    {
        $user = User::factory()->create();
        $reunion = $this->iniciarReunion($user);
        $this->subirFragmento($reunion, 1, 240)->assertCreated();
        $this->subirFragmento($reunion, 2, 60)->assertCreated();
        $this->postJson(route('reuniones.pausar', $reunion))->assertOk();

        $this->get(route('jarvis'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('reunionActiva.id', $reunion->id)
                ->where('reunionActiva.estado', 'pausada')
                ->where('reunionActiva.segundos_grabados', 300));
    }

    public function test_a_meeting_left_recording_after_a_browser_crash_can_be_resumed()
    {
        $reunion = $this->iniciarReunion(User::factory()->create());

        $this->postJson(route('reuniones.reanudar', $reunion))
            ->assertOk()
            ->assertJsonPath('reunion.estado', 'grabando');
    }

    public function test_only_open_meetings_can_be_paused_or_resumed()
    {
        $reunion = $this->iniciarReunion(User::factory()->create());

        $this->postJson(route('reuniones.reanudar', $reunion))->assertOk();
        $this->postJson(route('reuniones.pausar', $reunion))->assertOk();
        $this->postJson(route('reuniones.pausar', $reunion))->assertConflict();

        $this->subirFragmento($reunion, 1)->assertCreated();
        $this->postJson(route('reuniones.terminar', $reunion))->assertOk();

        $this->postJson(route('reuniones.reanudar', $reunion))->assertConflict();
        $this->postJson(route('reuniones.pausar', $reunion))->assertConflict();
    }
}
