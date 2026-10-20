<?php

namespace Tests\Feature;

use App\Ai\Agents\Jarvis;
use App\Ai\Agents\PreparadorInforme;
use App\Ai\Agents\ResumidorReunion;
use App\Enums\EstadoFragmento;
use App\Enums\EstadoReunion;
use App\Jobs\GenerarResumen;
use App\Models\Reunion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\TestCase;

class AgendaEInformePrevioTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_a_meeting_starts_with_its_agenda_and_it_can_be_changed_while_open()
    {
        $id = $this->postJson(route('reuniones.store'), [
            'titulo' => 'Comité editorial',
            'agenda' => ['Cobertura del Papa', '  ', 'Fenómeno El Niño'],
        ])->assertCreated()
            ->assertJsonPath('reunion.agenda', ['Cobertura del Papa', 'Fenómeno El Niño'])
            ->json('reunion.id');

        $this->putJson(route('reuniones.agenda', $id), ['agenda' => ['Fenómeno El Niño', 'Presupuesto 2027']])
            ->assertOk()
            ->assertJsonPath('reunion.agenda', ['Fenómeno El Niño', 'Presupuesto 2027']);

        // El chat sabe la agenda de la reunión.
        $instrucciones = (string) (new Jarvis(Reunion::find($id)))->instructions();
        $this->assertStringContainsString('2. Presupuesto 2027', $instrucciones);

        // Terminada, ya no se cambia.
        Reunion::query()->whereKey($id)->update(['estado' => EstadoReunion::Completada]);
        $this->putJson(route('reuniones.agenda', $id), ['agenda' => ['Otro']])->assertStatus(409);
    }

    public function test_the_minutes_say_which_agenda_items_were_covered()
    {
        ResumidorReunion::fake();
        $reunion = $this->reunionTranscrita(['Cobertura del Papa', 'Presupuesto 2027']);

        GenerarResumen::dispatchSync($reunion);

        ResumidorReunion::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains("Agenda:\n1. Cobertura del Papa\n2. Presupuesto 2027"));
    }

    public function test_a_silent_meeting_marks_its_agenda_as_not_covered()
    {
        $reunion = $this->reunionTranscrita(['Cobertura del Papa'], conTexto: false);

        GenerarResumen::dispatchSync($reunion);

        $this->assertSame(
            [['punto' => 'Cobertura del Papa', 'estado' => 'no_tratado', 'comentario' => 'No se detectó conversación.']],
            $reunion->refresh()->resumen->contenido['agenda'],
        );
    }

    public function test_the_brief_reviews_the_last_minutes_and_flags_overdue_commitments()
    {
        $this->reunionConActa('Comité comercial', [
            ['descripcion' => 'Enviar reporte de tráfico', 'responsable' => 'Ana', 'fecha_limite' => '1 de octubre'],
        ]);
        PreparadorInforme::fake([[
            'resumen' => 'Queda pendiente el reporte de tráfico.',
            'compromisos' => [
                ['descripcion' => 'Enviar reporte de tráfico', 'responsable' => 'Ana', 'fecha_limite' => '1 de octubre', 'fecha_iso' => now()->subDays(3)->toDateString(), 'reunion' => 'Comité comercial'],
                ['descripcion' => 'Definir presupuesto', 'responsable' => null, 'fecha_limite' => null, 'fecha_iso' => null, 'reunion' => 'Comité comercial'],
                ['descripcion' => 'Plan de video', 'responsable' => 'Luis', 'fecha_limite' => 'próximo mes', 'fecha_iso' => now()->addMonth()->toDateString(), 'reunion' => 'Comité comercial'],
            ],
            'retomar' => [],
            'alertas' => ['El presupuesto no tiene responsable.'],
            'sugerencias_agenda' => ['Estado del reporte de tráfico'],
            'antecedentes_agenda' => [],
        ]]);

        $this->postJson(route('reuniones.informe-previo'), ['agenda' => ['Cobertura del Papa']])
            ->assertOk()
            ->assertJsonPath('reuniones.0.titulo', 'Comité comercial')
            ->assertJsonPath('informe.compromisos.0.vencido', true)
            ->assertJsonPath('informe.compromisos.1.vencido', false)
            ->assertJsonPath('informe.compromisos.2.vencido', false)
            ->assertJsonPath('informe.alertas.0', 'El presupuesto no tiene responsable.');

        PreparadorInforme::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('1. Cobertura del Papa')
            && $prompt->contains('Enviar reporte de tráfico (responsable: Ana; fecha límite: 1 de octubre)'));
    }

    public function test_without_previous_minutes_there_is_no_brief_and_the_ai_is_not_called()
    {
        PreparadorInforme::fake();

        $this->postJson(route('reuniones.informe-previo'))
            ->assertOk()
            ->assertJsonPath('reuniones', [])
            ->assertJsonPath('informe', null);

        PreparadorInforme::assertNeverPrompted();
    }

    /**
     * @param  list<string>|null  $agenda
     */
    private function reunionTranscrita(?array $agenda, bool $conTexto = true): Reunion
    {
        $reunion = Reunion::create([
            'agenda' => $agenda,
            'estado' => EstadoReunion::Procesando,
            'iniciada_en' => now()->subMinutes(5),
            'finalizada_en' => now(),
            'duracion_segundos' => 300,
            'creada_por' => $this->user->id,
        ]);

        $fragmento = $reunion->fragmentos()->create([
            'orden' => 1,
            'ruta_archivo' => "reuniones/{$reunion->id}/audio/0001.webm",
            'mime' => 'audio/webm',
            'tamano_bytes' => 1000,
            'duracion_segundos' => 300,
            'estado' => EstadoFragmento::Listo,
        ]);

        if ($conTexto) {
            $fragmento->segmentos()->create([
                'reunion_id' => $reunion->id,
                'orden' => 1,
                'hablante' => 'A',
                'inicio_segundos' => 10,
                'fin_segundos' => 15,
                'texto' => 'Hablemos de la cobertura del Papa.',
            ]);
        }

        $reunion->forceFill(['transcripcion_lista_en' => now()])->save();

        return $reunion;
    }

    /**
     * @param  list<array<string, string|null>>  $acuerdos
     */
    private function reunionConActa(string $titulo, array $acuerdos): Reunion
    {
        $reunion = Reunion::create([
            'titulo' => $titulo,
            'estado' => EstadoReunion::Completada,
            'iniciada_en' => now()->subWeek(),
            'finalizada_en' => now()->subWeek(),
            'duracion_segundos' => 600,
            'creada_por' => $this->user->id,
        ]);

        $reunion->resumenes()->create([
            'version' => 1,
            'modelo' => 'gpt-6-luna',
            'tokens' => 100,
            'contenido' => [
                'resumen_ejecutivo' => 'Se revisó el tráfico.',
                'acuerdos' => $acuerdos,
                'pendientes' => ['Revisar presupuesto'],
                'riesgos_o_puntos_criticos' => [],
            ],
        ]);

        return $reunion;
    }

    public function test_the_chat_knows_what_was_said_minute_by_minute_in_the_open_meeting()
    {
        $reunion = Reunion::create([
            'estado' => EstadoReunion::Grabando,
            'iniciada_en' => now(),
            'creada_por' => $this->user->id,
        ]);

        foreach ([1 => ['tema' => 'Cobertura del Papa', 'resumen' => 'Se propuso reforzarla.', 'puntos' => ['3 notas diarias']], 2 => null] as $orden => $resumen) {
            $reunion->fragmentos()->create([
                'orden' => $orden,
                'ruta_archivo' => "reuniones/{$reunion->id}/audio/{$orden}.webm",
                'mime' => 'audio/webm',
                'tamano_bytes' => 1000,
                'duracion_segundos' => 60,
                'estado' => $resumen ? EstadoFragmento::Listo : EstadoFragmento::Transcribiendo,
                'resumen' => $resumen,
            ]);
        }

        $instrucciones = (string) (new Jarvis($reunion))->instructions();

        $this->assertStringContainsString('- [00:00] Cobertura del Papa: Se propuso reforzarla. (3 notas diarias)', $instrucciones);
        $this->assertStringNotContainsString('[01:00]', $instrucciones);
    }
}
