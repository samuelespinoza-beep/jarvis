<?php

namespace Tests\Feature;

use App\Enums\EstadoFragmento;
use App\Enums\EstadoReunion;
use App\Jobs\TranscribirFragmento;
use App\Models\Reunion;
use App\Models\User;
use App\Services\CierreReunion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Prompts\TranscriptionPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TranscriptionSegment;
use Laravel\Ai\Responses\Data\TranscriptionUsage;
use Laravel\Ai\Responses\TranscriptionResponse;
use Laravel\Ai\Transcription;
use RuntimeException;
use Tests\TestCase;

class TranscripcionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * Respuesta simulada de OpenAI con dos hablantes.
     */
    private function respuesta(string $a, string $b): TranscriptionResponse
    {
        return new TranscriptionResponse(
            "{$a} {$b}",
            new Collection([
                new TranscriptionSegment($a, 'A', 0.0, 4.5),
                new TranscriptionSegment($b, 'B', 4.5, 9.0),
            ]),
            new TranscriptionUsage(inputTokens: 100, outputTokens: 20, audioSeconds: 60),
            new Meta('openai', 'gpt-4o-transcribe-diarize'),
        );
    }

    private function iniciarReunion(): Reunion
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('reuniones.store'))
            ->assertCreated();

        return Reunion::query()->firstOrFail();
    }

    private function subirFragmento(Reunion $reunion, int $orden, int $duracion = 60)
    {
        return $this->postJson(route('reuniones.fragmentos.store', $reunion), [
            'audio' => UploadedFile::fake()->create("{$orden}.webm", 300, 'audio/webm'),
            'orden' => $orden,
            'duracion_segundos' => $duracion,
        ])->assertCreated();
    }

    public function test_each_fragment_is_transcribed_with_speakers_and_meeting_times()
    {
        Transcription::fake([
            $this->respuesta('Buenos días a todos.', 'Empecemos con ventas.'),
            $this->respuesta('Las ventas subieron.', 'Excelente noticia.'),
        ]);

        $reunion = $this->iniciarReunion();
        $this->subirFragmento($reunion, 1, 60);
        $this->subirFragmento($reunion, 2, 60);

        Transcription::assertGenerated(fn (TranscriptionPrompt $prompt) => $prompt->isDiarized()
            && $prompt->language === 'es'
            && $prompt->model === 'gpt-4o-transcribe-diarize');

        $segmentos = $reunion->segmentos()->get();

        $this->assertSame(
            ['Buenos días a todos.', 'Empecemos con ventas.', 'Las ventas subieron.', 'Excelente noticia.'],
            $segmentos->pluck('texto')->all(),
        );
        $this->assertSame(['A', 'B', 'A', 'B'], $segmentos->pluck('hablante')->all());
        // El segundo fragmento empieza en el segundo 60 de la reunión.
        $this->assertSame(64.5, $segmentos[3]->inicio_segundos);

        $this->assertSame(EstadoFragmento::Listo, $reunion->fragmentos()->first()->estado);
        $this->assertSame(120, $reunion->refresh()->costo_audio_segundos);
        $this->assertSame(240, $reunion->costo_tokens);
    }

    public function test_meeting_is_completed_when_finished_after_everything_was_transcribed()
    {
        Transcription::fake([$this->respuesta('Hola.', 'Adiós.')]);

        $reunion = $this->iniciarReunion();
        $this->subirFragmento($reunion, 1);

        $this->postJson(route('reuniones.terminar', $reunion))
            ->assertOk()
            ->assertJsonPath('reunion.estado', 'completada');

        $transcripcion = json_decode(Storage::disk('local')->get("reuniones/{$reunion->id}/transcripcion.json"), true);
        $this->assertSame("Hola.\nAdiós.", $transcripcion['texto']);
        $this->assertSame(1, $transcripcion['segmentos'][0]['fragmento']);

        $this->getJson(route('reuniones.show', $reunion))
            ->assertOk()
            ->assertJsonPath('reunion.estado', 'completada')
            ->assertJsonPath('fragmentos.0.estado', 'listo')
            ->assertJsonPath('segmentos.1.texto', 'Adiós.')
            ->assertJsonPath('segmentos.1.hablante', 'B');
    }

    public function test_meeting_is_completed_when_the_last_fragment_finishes_after_finishing()
    {
        Queue::fake();
        Transcription::fake([$this->respuesta('Hola.', 'Adiós.')]);

        $reunion = $this->iniciarReunion();
        $this->subirFragmento($reunion, 1);

        $this->postJson(route('reuniones.terminar', $reunion))
            ->assertJsonPath('reunion.estado', 'procesando');

        $this->getJson(route('reuniones.show', $reunion))->assertJsonPath('segmentos', []);

        Queue::assertPushed(TranscribirFragmento::class, function (TranscribirFragmento $job) {
            $job->handle(app(CierreReunion::class));

            return true;
        });

        $this->assertSame(EstadoReunion::Completada, $reunion->refresh()->estado);
        Storage::disk('local')->assertExists("reuniones/{$reunion->id}/transcripcion.json");
    }

    public function test_failed_fragments_mark_the_meeting_as_error_and_can_be_reprocessed()
    {
        Queue::fake();

        $reunion = $this->iniciarReunion();
        $this->subirFragmento($reunion, 1);
        $this->postJson(route('reuniones.terminar', $reunion));

        $fragmento = $reunion->fragmentos()->first();
        (new TranscribirFragmento($fragmento))->failed(new RuntimeException('OpenAI no respondió.'));

        $this->assertSame(EstadoFragmento::Error, $fragmento->refresh()->estado);
        $this->assertSame('OpenAI no respondió.', $fragmento->error);
        $this->assertSame(EstadoReunion::Error, $reunion->refresh()->estado);

        $this->postJson(route('reuniones.reprocesar', $reunion))
            ->assertOk()
            ->assertJsonPath('reunion.estado', 'procesando');

        $this->assertSame(EstadoFragmento::Pendiente, $fragmento->refresh()->estado);
        Queue::assertPushed(TranscribirFragmento::class, 2);
    }

    public function test_only_meetings_with_errors_can_be_reprocessed()
    {
        Queue::fake();

        $reunion = $this->iniciarReunion();

        $this->postJson(route('reuniones.reprocesar', $reunion))->assertConflict();
    }
}
