<?php

namespace Tests\Feature;

use App\Ai\Agents\ResumidorReunion;
use App\Enums\EstadoFragmento;
use App\Enums\EstadoReunion;
use App\Jobs\GenerarResumen;
use App\Models\Reunion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Prompts\AgentPrompt;
use RuntimeException;
use Tests\TestCase;

class ResumenTest extends TestCase
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

    /**
     * @return array<string, mixed>
     */
    private function acta(string $resumen = 'Se revisaron las ventas del trimestre.'): array
    {
        return [
            'titulo_sugerido' => 'Revisión de ventas Q3',
            'resumen_ejecutivo' => $resumen,
            'temas_tratados' => ['Ventas Q3'],
            'puntos_principales' => [['tema' => 'Ventas', 'detalle' => 'Subieron 10%.', 'minuto_aprox' => 0]],
            'puntos_secundarios' => [],
            'acuerdos' => [['descripcion' => 'Enviar reporte', 'responsable' => null, 'fecha_limite' => null]],
            'pendientes' => [],
            'riesgos_o_puntos_criticos' => [],
            'sugerencias_de_mejora' => [],
        ];
    }

    /**
     * Reunión ya transcrita, lista para resumir.
     */
    private function reunionTranscrita(?string $titulo = null, bool $conTexto = true): Reunion
    {
        $reunion = Reunion::create([
            'titulo' => $titulo,
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
                'inicio_segundos' => 65,
                'fin_segundos' => 70,
                'texto' => 'Las ventas subieron un diez por ciento.',
            ]);
        }

        $reunion->forceFill(['transcripcion_lista_en' => now()])->save();

        return $reunion;
    }

    public function test_summary_is_generated_with_luna_from_the_transcript()
    {
        ResumidorReunion::fake([$this->acta()]);
        $reunion = $this->reunionTranscrita();

        GenerarResumen::dispatchSync($reunion);

        ResumidorReunion::assertPrompted(fn (AgentPrompt $prompt) => $prompt->model === 'gpt-6-luna'
            && $prompt->contains('[01:05] Hablante A: Las ventas subieron un diez por ciento.'));

        $reunion->refresh();
        $this->assertSame(EstadoReunion::Completada, $reunion->estado);
        $this->assertSame('Revisión de ventas Q3', $reunion->titulo);
        $this->assertSame(1, $reunion->resumen->version);
        $this->assertSame('Se revisaron las ventas del trimestre.', $reunion->resumen->contenido['resumen_ejecutivo']);

        $archivo = json_decode(Storage::disk('local')->get("reuniones/{$reunion->id}/resumenes/v1.json"), true);
        $this->assertSame('gpt-6-luna', $archivo['modelo']);
        $this->assertSame(['Ventas Q3'], $archivo['temas_tratados']);
    }

    public function test_user_title_is_kept_and_sent_to_the_ai()
    {
        ResumidorReunion::fake([$this->acta()]);
        $reunion = $this->reunionTranscrita('Comité de gerencia');

        GenerarResumen::dispatchSync($reunion);

        ResumidorReunion::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('Título indicado por el usuario: Comité de gerencia'));
        $this->assertSame('Comité de gerencia', $reunion->refresh()->titulo);
    }

    public function test_silent_meetings_are_summarized_without_calling_the_ai()
    {
        ResumidorReunion::fake()->preventStrayPrompts();
        $reunion = $this->reunionTranscrita(conTexto: false);

        GenerarResumen::dispatchSync($reunion);

        ResumidorReunion::assertNeverPrompted();
        $this->assertSame(EstadoReunion::Completada, $reunion->refresh()->estado);
        $this->assertSame('ninguno', $reunion->resumen->modelo);
    }

    public function test_regenerating_creates_a_new_version_and_keeps_the_old_one()
    {
        ResumidorReunion::fake([$this->acta('Primera versión.'), $this->acta('Segunda versión.')]);
        $reunion = $this->reunionTranscrita();
        GenerarResumen::dispatchSync($reunion);

        $this->postJson(route('reuniones.resumen.regenerar', $reunion))->assertOk();

        $reunion->refresh();
        $this->assertSame(EstadoReunion::Completada, $reunion->estado);
        $this->assertSame([2, 1], $reunion->resumenes()->pluck('version')->all());
        $this->assertSame('Segunda versión.', $reunion->resumen->contenido['resumen_ejecutivo']);
        Storage::disk('local')->assertExists("reuniones/{$reunion->id}/resumenes/v1.json");
        Storage::disk('local')->assertExists("reuniones/{$reunion->id}/resumenes/v2.json");
    }

    public function test_only_completed_meetings_can_regenerate_their_summary()
    {
        $reunion = $this->reunionTranscrita();

        $this->postJson(route('reuniones.resumen.regenerar', $reunion))->assertConflict();
    }

    public function test_a_failed_summary_marks_the_meeting_as_error_and_reprocessing_retries_it()
    {
        Queue::fake();
        $reunion = $this->reunionTranscrita();

        (new GenerarResumen($reunion))->failed(new RuntimeException('Sin conexión.'));
        $this->assertSame(EstadoReunion::Error, $reunion->refresh()->estado);

        $this->postJson(route('reuniones.reprocesar', $reunion))
            ->assertOk()
            ->assertJsonPath('reunion.estado', 'procesando');

        Queue::assertPushed(GenerarResumen::class);
    }

    public function test_status_endpoint_includes_the_summary_when_completed()
    {
        ResumidorReunion::fake([$this->acta()]);
        $reunion = $this->reunionTranscrita();
        GenerarResumen::dispatchSync($reunion);

        $this->getJson(route('reuniones.estado', $reunion))
            ->assertOk()
            ->assertJsonPath('resumen.resumen_ejecutivo', 'Se revisaron las ventas del trimestre.');
    }

    public function test_history_lists_meetings_with_their_executive_summary()
    {
        ResumidorReunion::fake([$this->acta()]);
        $reunion = $this->reunionTranscrita();
        GenerarResumen::dispatchSync($reunion);

        $this->get(route('reuniones.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('reuniones/Index')
                ->where('reuniones.data.0.id', $reunion->id)
                ->where('reuniones.data.0.resumen_ejecutivo', 'Se revisaron las ventas del trimestre.'));
    }

    public function test_meeting_detail_shows_summary_versions_and_transcript()
    {
        ResumidorReunion::fake([$this->acta('Primera versión.'), $this->acta('Segunda versión.')]);
        $reunion = $this->reunionTranscrita();
        GenerarResumen::dispatchSync($reunion);
        $reunion->update(['estado' => EstadoReunion::Procesando]);
        GenerarResumen::dispatchSync($reunion);

        $this->get(route('reuniones.show', $reunion))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('reuniones/Show')
                ->where('resumen.version', 2)
                ->where('resumen.contenido.resumen_ejecutivo', 'Segunda versión.')
                ->has('versiones', 2)
                ->where('segmentos.0.hablante', 'A'));

        $this->get(route('reuniones.show', ['reunion' => $reunion, 'version' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('resumen.contenido.resumen_ejecutivo', 'Primera versión.'));
    }
}
