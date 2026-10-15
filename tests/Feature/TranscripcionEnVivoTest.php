<?php

namespace Tests\Feature;

use App\Enums\EstadoReunion;
use App\Models\Reunion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TranscripcionEnVivoTest extends TestCase
{
    use RefreshDatabase;

    private function reunion(EstadoReunion $estado): Reunion
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return Reunion::create([
            'estado' => $estado,
            'iniciada_en' => now(),
            'creada_por' => $user->id,
        ]);
    }

    public function test_issues_a_temporary_openai_key_for_a_recording_meeting()
    {
        Http::fake(['api.openai.com/v1/realtime/client_secrets' => Http::response([
            'value' => 'ek_temporal_123',
            'expires_at' => 1791990000,
        ])]);

        $reunion = $this->reunion(EstadoReunion::Grabando);

        $this->postJson(route('reuniones.en-vivo', $reunion))
            ->assertOk()
            ->assertExactJson(['llave' => 'ek_temporal_123', 'expira_en' => 1791990000]);

        Http::assertSent(fn (PeticionHttp $peticion) => $peticion->hasHeader('Authorization', 'Bearer '.config('ai.providers.openai.key'))
            && $peticion['session']['type'] === 'transcription'
            && $peticion['session']['audio']['input']['transcription'] === ['model' => 'gpt-4o-mini-transcribe', 'language' => 'es', 'prompt' => 'Jarvis']
            && $peticion['session']['audio']['input']['turn_detection']['type'] === 'server_vad'
            && $peticion['expires_after']['seconds'] === 120);
    }

    public function test_the_real_api_key_is_never_returned_to_the_browser()
    {
        config(['ai.providers.openai.key' => 'sk-secreta']);
        Http::fake(['api.openai.com/*' => Http::response(['value' => 'ek_temporal', 'expires_at' => 1])]);

        $respuesta = $this->postJson(route('reuniones.en-vivo', $this->reunion(EstadoReunion::Grabando)));

        $this->assertStringNotContainsString('sk-secreta', $respuesta->getContent());
    }

    public function test_a_paused_meeting_also_gets_a_key_to_hear_the_resume_order()
    {
        Http::fake(['api.openai.com/v1/realtime/client_secrets' => Http::response(['value' => 'ek_1', 'expires_at' => 1])]);

        $this->postJson(route('reuniones.en-vivo', $this->reunion(EstadoReunion::Pausada)))->assertOk();
    }

    public function test_closed_meetings_do_not_get_a_key()
    {
        Http::fake();

        $this->postJson(route('reuniones.en-vivo', $this->reunion(EstadoReunion::Procesando)))->assertConflict();
        $this->postJson(route('reuniones.en-vivo', $this->reunion(EstadoReunion::Completada)))->assertConflict();
        Http::assertNothingSent();
    }

    public function test_it_can_be_disabled()
    {
        config(['jarvis.en_vivo.habilitada' => false]);
        Http::fake();

        $this->postJson(route('reuniones.en-vivo', $this->reunion(EstadoReunion::Grabando)))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_reports_when_openai_fails()
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'invalid_api_key']], 401)]);

        $this->postJson(route('reuniones.en-vivo', $this->reunion(EstadoReunion::Grabando)))
            ->assertStatus(502)
            ->assertJsonPath('message', 'No se pudo iniciar la transcripción en vivo.');
    }
}
