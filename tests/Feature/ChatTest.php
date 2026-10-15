<?php

namespace Tests\Feature;

use App\Ai\Agents\Jarvis;
use App\Enums\EstadoReunion;
use App\Models\Reunion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\StoredDocument;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_chat()
    {
        $this->postJson(route('chat.enviar'), ['mensaje' => 'Hola'])->assertUnauthorized();
        $this->getJson(route('chat.mensajes'))->assertUnauthorized();
    }

    public function test_jarvis_streams_its_answer_and_remembers_the_conversation()
    {
        Jarvis::fake(['Hola, soy Jarvis.']);
        $user = User::factory()->create();

        $respuesta = $this->actingAs($user)->post(route('chat.enviar'), ['mensaje' => '¿Quién eres?']);

        $respuesta->assertOk();
        $this->assertStringContainsString('text/event-stream', $respuesta->headers->get('Content-Type'));
        $contenido = $respuesta->streamedContent();
        $this->assertStringContainsString('"type":"text_delta"', $contenido);
        $this->assertStringContainsString('[DONE]', $contenido);

        $id = $respuesta->headers->get('X-Conversacion-Id');
        $this->assertNotEmpty($id);

        Jarvis::assertPrompted(fn (AgentPrompt $prompt) => $prompt->model === 'gpt-6-luna'
            && $prompt->contains('¿Quién eres?'));

        $this->getJson(route('chat.mensajes', ['conversacion' => $id]))
            ->assertOk()
            ->assertJsonPath('conversacion_id', $id)
            ->assertJsonPath('mensajes.0.rol', 'usuario')
            ->assertJsonPath('mensajes.0.contenido', '¿Quién eres?')
            ->assertJsonPath('mensajes.1.rol', 'jarvis')
            ->assertJsonPath('mensajes.1.contenido', 'Hola, soy Jarvis.')
            ->assertJsonPath('mensajes.0.creado_en', fn (?string $hora) => $hora !== null && strtotime($hora) !== false);
    }

    public function test_a_conversation_can_be_continued()
    {
        Jarvis::fake(['Primera respuesta.', 'Segunda respuesta.']);
        $user = User::factory()->create();

        // La conversación se guarda al terminar de transmitir, por eso se consume la respuesta.
        $primera = $this->actingAs($user)->post(route('chat.enviar'), ['mensaje' => 'Uno']);
        $primera->streamedContent();
        $id = $primera->headers->get('X-Conversacion-Id');

        $segunda = $this->post(route('chat.enviar'), ['mensaje' => 'Dos', 'conversacion_id' => $id]);
        $segunda->assertOk()->assertHeader('X-Conversacion-Id', $id);
        $segunda->streamedContent();

        $this->getJson(route('chat.mensajes', ['conversacion' => $id]))
            ->assertJsonCount(4, 'mensajes');
    }

    public function test_users_cannot_read_or_continue_someone_elses_conversation()
    {
        Jarvis::fake(['Respuesta privada.']);

        $respuesta = $this->actingAs(User::factory()->create())
            ->post(route('chat.enviar'), ['mensaje' => 'Algo confidencial']);
        $respuesta->streamedContent();
        $id = $respuesta->headers->get('X-Conversacion-Id');

        $this->actingAs(User::factory()->create());

        $this->getJson(route('chat.mensajes', ['conversacion' => $id]))->assertNotFound();
        $this->postJson(route('chat.enviar'), ['mensaje' => 'Hola', 'conversacion_id' => $id])->assertNotFound();
    }

    public function test_a_user_without_conversations_gets_an_empty_chat()
    {
        $this->actingAs(User::factory()->create())
            ->getJson(route('chat.mensajes'))
            ->assertExactJson(['conversacion_id' => null, 'mensajes' => []]);
    }

    public function test_the_general_chat_starts_empty_instead_of_reloading_the_last_conversation()
    {
        Jarvis::fake(['Respuesta.']);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('chat.enviar'), ['mensaje' => 'Hola'])->streamedContent();

        $this->getJson(route('chat.mensajes'))
            ->assertExactJson(['conversacion_id' => null, 'mensajes' => []]);
    }

    public function test_each_open_meeting_has_its_own_chat_and_jarvis_knows_about_it()
    {
        Jarvis::fake(['Van 5 minutos de reunión.', 'Seguimos.', 'Otra reunión.']);
        $user = User::factory()->create();
        $this->actingAs($user);

        $reunion = Reunion::create([
            'titulo' => 'Comité comercial',
            'estado' => EstadoReunion::Pausada,
            'iniciada_en' => now(),
            'creada_por' => $user->id,
        ]);

        $this->post(route('chat.enviar'), ['mensaje' => '¿Qué se ha hablado?', 'reunion_id' => $reunion->id])
            ->streamedContent();

        $conversacion = $reunion->refresh()->conversacion_id;
        $this->assertNotNull($conversacion);

        Jarvis::assertPrompted(fn (AgentPrompt $prompt) => str_contains((string) $prompt->agent->instructions(), "ID: {$reunion->id}")
            && str_contains((string) $prompt->agent->instructions(), 'Comité comercial'));

        // El segundo mensaje sigue en la misma conversación, aunque el navegador mande otra.
        $this->post(route('chat.enviar'), [
            'mensaje' => '¿Y ahora?',
            'reunion_id' => $reunion->id,
            'conversacion_id' => 'otra-cualquiera',
        ])->assertHeader('X-Conversacion-Id', $conversacion)->streamedContent();

        $this->getJson(route('chat.mensajes', ['reunion' => $reunion->id]))
            ->assertJsonPath('conversacion_id', $conversacion)
            ->assertJsonCount(4, 'mensajes');

        // Una reunión nueva empieza con el chat vacío.
        $otra = Reunion::create(['estado' => EstadoReunion::Grabando, 'iniciada_en' => now(), 'creada_por' => $user->id]);

        $this->getJson(route('chat.mensajes', ['reunion' => $otra->id]))
            ->assertExactJson(['conversacion_id' => null, 'mensajes' => []]);
    }

    public function test_jarvis_does_not_treat_a_finished_meeting_as_the_current_one()
    {
        Jarvis::fake(['Ok.']);
        $user = User::factory()->create();
        $this->actingAs($user);

        $reunion = Reunion::create([
            'estado' => EstadoReunion::Completada,
            'iniciada_en' => now(),
            'creada_por' => $user->id,
        ]);

        $this->post(route('chat.enviar'), ['mensaje' => 'Hola', 'reunion_id' => $reunion->id])->streamedContent();

        Jarvis::assertPrompted(fn (AgentPrompt $prompt) => ! str_contains((string) $prompt->agent->instructions(), 'Reunión en curso'));
    }

    public function test_the_chat_of_a_finished_meeting_continues_and_jarvis_knows_it_has_an_acta()
    {
        Jarvis::fake(['Durante la reunión.', 'Ya con el acta.']);
        $user = User::factory()->create();
        $this->actingAs($user);

        $reunion = Reunion::create([
            'titulo' => 'Comité de logística',
            'estado' => EstadoReunion::Grabando,
            'iniciada_en' => now(),
            'creada_por' => $user->id,
        ]);

        $this->post(route('chat.enviar'), ['mensaje' => '¿Qué se dijo?', 'reunion_id' => $reunion->id])->streamedContent();
        $conversacion = $reunion->refresh()->conversacion_id;

        $reunion->update(['estado' => EstadoReunion::Completada, 'finalizada_en' => now()]);

        $this->post(route('chat.enviar'), ['mensaje' => '¿Y los acuerdos?', 'reunion_id' => $reunion->id])
            ->assertHeader('X-Conversacion-Id', $conversacion)
            ->streamedContent();

        Jarvis::assertPrompted(function (AgentPrompt $prompt) use ($reunion) {
            $instrucciones = (string) $prompt->agent->instructions();

            return $prompt->contains('¿Y los acuerdos?')
                && str_contains($instrucciones, 'ya terminada')
                && str_contains($instrucciones, "ID: {$reunion->id}")
                && str_contains($instrucciones, 'Ya tiene acta');
        });

        $this->getJson(route('chat.mensajes', ['reunion' => $reunion->id]))->assertJsonCount(4, 'mensajes');
    }

    public function test_a_document_is_stored_and_attached_for_jarvis_to_summarize()
    {
        Storage::fake('local');
        Jarvis::fake(['### Resumen', 'Sigue siendo sobre el informe.']);
        $user = User::factory()->create();

        $respuesta = $this->actingAs($user)->post(route('chat.enviar'), [
            'mensaje' => 'Resume este documento y dime sus temas centrales.',
            'documento' => UploadedFile::fake()->create('Informe anual.pdf', 120, 'application/pdf'),
        ]);
        $respuesta->assertOk()->streamedContent();
        $id = $respuesta->headers->get('X-Conversacion-Id');

        $this->assertCount(1, Storage::disk('local')->files('documentos'));

        Jarvis::assertPrompted(function (AgentPrompt $prompt) {
            $documento = $prompt->attachments->first();

            return $documento instanceof StoredDocument
                && $documento->name() === 'Informe anual.pdf'
                && str_starts_with($documento->path, 'documentos/');
        });

        // El documento queda en la conversación para las preguntas siguientes.
        $this->post(route('chat.enviar'), ['mensaje' => '¿Y las cifras?', 'conversacion_id' => $id])
            ->streamedContent();

        $this->getJson(route('chat.mensajes', ['conversacion' => $id]))
            ->assertJsonPath('mensajes.0.documento', 'Informe anual.pdf')
            ->assertJsonPath('mensajes.2.documento', null);
    }

    public function test_unsupported_documents_are_rejected_with_a_json_error()
    {
        Storage::fake('local');
        Jarvis::fake();

        // Sin pedir JSON, como hace el chat al esperar el stream.
        $this->actingAs(User::factory()->create())
            ->post(route('chat.enviar'), [
                'mensaje' => 'Resume esto',
                'documento' => UploadedFile::fake()->create('programa.exe', 10, 'application/x-msdownload'),
            ], ['Accept' => 'text/event-stream'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['documento' => 'Formato no admitido']);

        Jarvis::assertNeverPrompted();
        $this->assertEmpty(Storage::disk('local')->files('documentos'));
    }

    public function test_documents_over_the_size_limit_are_rejected()
    {
        Storage::fake('local');
        Jarvis::fake();
        config(['jarvis.chat.documento_max_kb' => 50]);

        $this->actingAs(User::factory()->create())
            ->post(route('chat.enviar'), [
                'mensaje' => 'Resume esto',
                'documento' => UploadedFile::fake()->create('grande.pdf', 51, 'application/pdf'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['documento' => 'supera el máximo']);

        Jarvis::assertNeverPrompted();
    }
}
