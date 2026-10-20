<?php

namespace Tests\Feature;

use App\Ai\Agents\Jarvis;
use App\Ai\Agents\LectorDocumento;
use App\Ai\Agents\ResumidorReunion;
use App\Ai\Tools\ConsultarDocumentoReunion;
use App\Ai\Tools\LeerReunion;
use App\Enums\EstadoFragmento;
use App\Enums\EstadoReunion;
use App\Jobs\GenerarResumen;
use App\Models\Reunion;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Tools\Request;
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

    /**
     * Guarda un mensaje en el chat de la reunión, como lo deja el SDK.
     *
     * @param  list<array<string, string>>  $adjuntos
     */
    private function mensajeDelChat(Reunion $reunion, string $rol, string $contenido, CarbonInterface $hora, array $adjuntos = [], bool $porVoz = false): void
    {
        if (! $reunion->conversacion_id) {
            $reunion->update(['conversacion_id' => app(ConversationStore::class)->storeConversation(
                Conversation::participantType($this->user), Conversation::participantKey($this->user), 'Reunión',
            )]);
        }

        ConversationMessage::query()->insert([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $reunion->conversacion_id,
            'participant_type' => Conversation::participantType($this->user),
            'participant_id' => Conversation::participantKey($this->user),
            'agent' => Jarvis::class,
            'role' => $rol,
            'content' => $contenido,
            'attachments' => json_encode($adjuntos),
            'steps' => '[]',
            'usage' => '[]',
            'meta' => json_encode($porVoz ? ['en_vivo' => true] : []),
            'status' => 'completed',
            'created_at' => $hora,
            'updated_at' => $hora,
        ]);
    }

    public function test_summary_includes_the_meeting_chat_and_its_documents()
    {
        ResumidorReunion::fake([$this->acta()]);
        $reunion = $this->reunionTranscrita();
        Storage::disk('local')->put('documentos/informe.pdf', '%PDF-1.4 informe');

        $this->mensajeDelChat($reunion, 'user', '¿Qué dice el informe sobre costos?', $reunion->iniciada_en->copy()->addSeconds(130), [
            ['type' => 'stored-document', 'name' => 'Informe Q3.pdf', 'path' => 'documentos/informe.pdf', 'disk' => 'local'],
        ]);
        $this->mensajeDelChat($reunion, 'assistant', 'Los costos bajaron un 5%.', $reunion->iniciada_en->copy()->addSeconds(140));
        // Guardado al terminar la respuesta, poco después de terminar la reunión: cuenta.
        $this->mensajeDelChat($reunion, 'user', 'Enviado justo antes de terminar.', $reunion->finalizada_en->copy()->addSeconds(40));
        $this->mensajeDelChat($reunion, 'user', 'Esto es de después de la reunión.', $reunion->finalizada_en->copy()->addMinutes(10));

        GenerarResumen::dispatchSync($reunion);

        ResumidorReunion::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('[01:05] Hablante A: Las ventas subieron un diez por ciento.')
            && $prompt->contains('[02:10] Usuario (adjuntó el documento «Informe Q3.pdf»): ¿Qué dice el informe sobre costos?')
            && $prompt->contains('[02:20] Asistente IA: Los costos bajaron un 5%.')
            && $prompt->contains('Usuario: Enviado justo antes de terminar.')
            && ! $prompt->contains('después de la reunión')
            && count($prompt->attachments) === 1
            && $prompt->attachments->first()->name() === 'Informe Q3.pdf');
    }

    public function test_images_from_the_meeting_chat_go_to_the_summary_as_images()
    {
        ResumidorReunion::fake([$this->acta()]);
        $reunion = $this->reunionTranscrita();
        Storage::disk('local')->put('documentos/grafico.png', 'png');
        $this->mensajeDelChat($reunion, 'user', 'Te adjunto esta imagen.', $reunion->iniciada_en->copy()->addMinute(), [
            ['type' => 'stored-image', 'name' => 'Tráfico.png', 'path' => 'documentos/grafico.png', 'disk' => 'local'],
        ]);

        GenerarResumen::dispatchSync($reunion);

        ResumidorReunion::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('Usuario (adjuntó la imagen «Tráfico.png»)')
            && $prompt->attachments->first() instanceof StoredImage
            && $prompt->attachments->first()->name() === 'Tráfico.png');
    }

    public function test_meetings_without_audio_but_with_chat_are_summarized_by_the_ai()
    {
        ResumidorReunion::fake([$this->acta()]);
        $reunion = $this->reunionTranscrita(conTexto: false);
        $this->mensajeDelChat($reunion, 'user', 'Revisemos el presupuesto.', $reunion->iniciada_en->copy()->addMinute());

        GenerarResumen::dispatchSync($reunion);

        ResumidorReunion::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('No se detectó conversación en el audio.')
            && $prompt->contains('Usuario: Revisemos el presupuesto.'));
        $this->assertSame('gpt-6-luna', $reunion->refresh()->resumen->modelo);
    }

    public function test_meeting_page_lists_its_chat_documents_and_downloads_them()
    {
        $reunion = $this->reunionTranscrita();
        Storage::disk('local')->put('documentos/informe.pdf', '%PDF-1.4 informe');
        $this->mensajeDelChat($reunion, 'user', 'Revisemos esto.', $reunion->finalizada_en->copy()->addMinute(), [
            ['type' => 'stored-document', 'name' => 'Informe Q3.pdf', 'path' => 'documentos/informe.pdf', 'disk' => 'local'],
        ]);

        $this->get(route('reuniones.show', $reunion))
            ->assertInertia(fn (Assert $page) => $page
                ->where('documentos.0.nombre', 'Informe Q3.pdf')
                ->where('documentos.0.archivo', 'informe.pdf'));

        $this->get(route('reuniones.documentos.show', [$reunion, 'informe.pdf']))
            ->assertOk()
            ->assertDownload('Informe Q3.pdf');

        $this->get(route('reuniones.documentos.show', [$reunion, 'otro.pdf']))->assertNotFound();
    }

    public function test_the_assistant_can_read_a_document_from_a_previous_meeting()
    {
        LectorDocumento::fake(['El informe proyecta un crecimiento de 12%.']);
        $reunion = $this->reunionTranscrita('Comité anterior');
        Storage::disk('local')->put('documentos/informe.pdf', '%PDF-1.4 informe');
        $this->mensajeDelChat($reunion, 'user', 'Te adjunto el informe.', $reunion->iniciada_en->copy()->addMinute(), [
            ['type' => 'stored-document', 'name' => 'Informe Q3.pdf', 'path' => 'documentos/informe.pdf', 'disk' => 'local'],
        ]);

        $acta = (string) (new LeerReunion)->handle(new Request(['id' => $reunion->id, 'incluir_transcripcion' => false]));
        $this->assertStringContainsString('- Informe Q3.pdf (archivo: informe.pdf)', $acta);

        $respuesta = (string) (new ConsultarDocumentoReunion)->handle(new Request([
            'reunion_id' => $reunion->id,
            'archivo' => 'informe.pdf',
            'pregunta' => '¿Qué crecimiento proyecta?',
        ]));

        $this->assertStringContainsString('Documento «Informe Q3.pdf»:', $respuesta);
        $this->assertStringContainsString('crecimiento de 12%', $respuesta);
        LectorDocumento::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('¿Qué crecimiento proyecta?')
            && $prompt->attachments->first()->name() === 'Informe Q3.pdf');

        $otro = (string) (new ConsultarDocumentoReunion)->handle(new Request([
            'reunion_id' => $reunion->id, 'archivo' => '../.env', 'pregunta' => 'Algo',
        ]));
        $this->assertStringContainsString('no tiene ese documento', $otro);
    }

    public function test_the_meeting_chat_is_kept_with_the_meeting_and_readable_from_a_later_one()
    {
        $reunion = $this->reunionTranscrita('Comité anterior');
        $this->mensajeDelChat($reunion, 'user', '¿Cuánto creció el tráfico?', $reunion->iniciada_en->copy()->addMinute(), porVoz: true);
        $this->mensajeDelChat($reunion, 'assistant', 'Creció 8% en octubre.', $reunion->iniciada_en->copy()->addMinutes(2), porVoz: true);
        $this->mensajeDelChat($reunion, 'user', 'Lo vemos la próxima.', $reunion->finalizada_en->copy()->addHour());

        // En la página de la reunión, la pestaña "Chat" lo tiene todo, también lo de después.
        $this->get(route('reuniones.show', $reunion))
            ->assertInertia(fn (Assert $page) => $page
                ->has('chat', 3)
                ->where('chat.0.contenido', '¿Cuánto creció el tráfico?')
                ->where('chat.0.en_vivo', true)
                ->where('chat.2.en_vivo', false));

        // Desde otra reunión, el Asistente lo lee completo.
        $resultado = (string) (new LeerReunion)->handle(new Request(['id' => $reunion->id, 'incluir_transcripcion' => false, 'incluir_chat' => true]));

        $this->assertStringContainsString('[01:00] Usuario (por voz): ¿Cuánto creció el tráfico?', $resultado);
        $this->assertStringContainsString('Asistente IA (por voz): Creció 8% en octubre.', $resultado);
        $this->assertStringContainsString('Usuario: Lo vemos la próxima.', $resultado);

        $sinChat = (string) (new LeerReunion)->handle(new Request(['id' => $reunion->id, 'incluir_transcripcion' => false, 'incluir_chat' => false]));
        $this->assertStringNotContainsString('Creció 8%', $sinChat);
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
