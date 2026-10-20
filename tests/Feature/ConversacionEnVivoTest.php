<?php

namespace Tests\Feature;

use App\Ai\Agents\BuscadorWeb;
use App\Ai\Agents\ConsultorDelChat;
use App\Ai\Agents\Jarvis;
use App\Enums\EstadoReunion;
use App\Models\Reunion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Files\StoredDocument;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\TestCase;

class ConversacionEnVivoTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_use_the_live_conversation()
    {
        $this->postJson(route('chat.en-vivo.sesion'))->assertUnauthorized();
        $this->postJson(route('chat.en-vivo.herramienta'))->assertUnauthorized();
        $this->postJson(route('chat.en-vivo.guardar'))->assertUnauthorized();
    }

    public function test_issues_a_temporary_key_for_a_voice_session_with_the_chat_tools()
    {
        config(['ai.providers.openai.key' => 'sk-secreta']);
        Http::fake(['api.openai.com/v1/realtime/client_secrets' => Http::response(['value' => 'ek_voz_1', 'expires_at' => 1])]);

        $respuesta = $this->actingAs(User::factory()->create())
            ->postJson(route('chat.en-vivo.sesion'), ['voz' => 'onyx'])
            ->assertOk()
            ->assertJsonPath('llave', 'ek_voz_1')
            // Onyx no existe en tiempo real: se usa la más parecida.
            ->assertJsonPath('voz', 'cedar');

        $this->assertStringNotContainsString('sk-secreta', $respuesta->getContent());

        Http::assertSent(function (PeticionHttp $peticion) {
            $sesion = $peticion['session'];
            $herramientas = array_column($sesion['tools'], 'name');

            return $sesion['type'] === 'realtime'
                && $sesion['model'] === 'gpt-realtime-2.1-mini'
                && $sesion['audio']['output']['voice'] === 'cedar'
                && str_contains($sesion['instructions'], 'Conversación por voz en vivo')
                && $herramientas === ['BuscarEnInternet', 'LeerPagina', 'UltimosVideosYoutube', 'ConsultarReuniones', 'LeerReunion', 'PreguntarAlChat']
                && $sesion['tools'][0]['parameters']['required'] === ['consulta'];
        });
    }

    public function test_the_voice_session_ignores_knocks_and_echo_with_a_volume_threshold()
    {
        config([
            'jarvis.conversacion.microfono' => 'far_field',
            'jarvis.conversacion.deteccion.umbral' => 0.8,
            'jarvis.conversacion.deteccion.interrumpir' => false,
        ]);
        Http::fake(['api.openai.com/v1/realtime/client_secrets' => Http::response(['value' => 'ek_voz_1'])]);

        $this->actingAs(User::factory()->create())
            ->postJson(route('chat.en-vivo.sesion'))
            ->assertOk();

        Http::assertSent(function (PeticionHttp $peticion) {
            $entrada = $peticion['session']['audio']['input'];

            return $entrada['noise_reduction'] === ['type' => 'far_field']
                && $entrada['turn_detection']['type'] === 'server_vad'
                && $entrada['turn_detection']['threshold'] === 0.8
                && $entrada['turn_detection']['interrupt_response'] === false;
        });
    }

    public function test_runs_the_tools_the_voice_model_asks_for()
    {
        $usuario = User::factory()->create();
        Reunion::create([
            'titulo' => 'Comité de logística',
            'estado' => EstadoReunion::Completada,
            'iniciada_en' => now(),
            'creada_por' => $usuario->id,
        ]);
        BuscadorWeb::fake(['El dólar cerró en 3,75 soles. Fuente: BCRP.']);

        $this->actingAs($usuario);

        $this->postJson(route('chat.en-vivo.herramienta'), ['nombre' => 'ConsultarReuniones', 'argumentos' => ['texto' => 'logística']])
            ->assertOk()
            ->assertJsonPath('resultado', fn (string $texto) => str_contains($texto, 'Comité de logística'));

        $this->postJson(route('chat.en-vivo.herramienta'), ['nombre' => 'BuscarEnInternet', 'argumentos' => ['consulta' => 'precio del dólar']])
            ->assertOk()
            ->assertJsonPath('resultado', 'El dólar cerró en 3,75 soles. Fuente: BCRP.');

        BuscadorWeb::assertPrompted(fn ($prompt) => $prompt->contains('precio del dólar'));

        $this->postJson(route('chat.en-vivo.herramienta'), ['nombre' => 'BorrarTodo'])->assertNotFound();
    }

    public function test_the_conversation_is_saved_in_the_chat_and_can_be_continued_by_text()
    {
        Jarvis::fake(['Sí, el dólar subió.']);
        $usuario = User::factory()->create();
        $this->actingAs($usuario);

        $id = $this->postJson(route('chat.en-vivo.guardar'), ['mensajes' => [
            ['rol' => 'usuario', 'contenido' => '¿A cuánto está el dólar?'],
            ['rol' => 'jarvis', 'contenido' => 'Está en 3,75 soles.'],
        ]])->assertOk()->json('conversacion_id');

        $this->getJson(route('chat.mensajes', ['conversacion' => $id]))
            ->assertJsonPath('mensajes.0.contenido', '¿A cuánto está el dólar?')
            ->assertJsonPath('mensajes.1.rol', 'jarvis')
            ->assertJsonPath('mensajes.1.contenido', 'Está en 3,75 soles.');

        // Escribiendo después, el chat sigue en la misma conversación.
        $this->post(route('chat.enviar'), ['mensaje' => '¿Subió?', 'conversacion_id' => $id])
            ->assertHeader('X-Conversacion-Id', $id)
            ->streamedContent();

        $this->getJson(route('chat.mensajes', ['conversacion' => $id]))->assertJsonCount(4, 'mensajes');

        // Otro usuario no puede escribir en esa conversación.
        $this->actingAs(User::factory()->create())
            ->postJson(route('chat.en-vivo.guardar'), ['conversacion_id' => $id, 'mensajes' => [['rol' => 'usuario', 'contenido' => 'Hola']]])
            ->assertNotFound();
    }

    public function test_in_a_meeting_the_conversation_goes_to_the_meeting_chat()
    {
        $usuario = User::factory()->create();
        $reunion = Reunion::create(['estado' => EstadoReunion::Pausada, 'iniciada_en' => now(), 'creada_por' => $usuario->id]);

        $this->actingAs($usuario)
            ->postJson(route('chat.en-vivo.guardar'), [
                'reunion_id' => $reunion->id,
                'mensajes' => [['rol' => 'usuario', 'contenido' => '¿Qué se ha dicho?']],
            ])->assertOk();

        $this->assertNotNull($reunion->refresh()->conversacion_id);
        $this->getJson(route('chat.mensajes', ['reunion' => $reunion->id]))->assertJsonCount(1, 'mensajes');
    }

    public function test_the_voice_starts_with_the_chat_history_including_a_document()
    {
        Storage::fake('local');
        Jarvis::fake(['El informe trata de las ventas de 2025.']);
        Http::fake(['api.openai.com/v1/realtime/client_secrets' => Http::response(['value' => 'ek_voz_1'])]);
        $usuario = User::factory()->create();
        $this->actingAs($usuario);

        $respuesta = $this->post(route('chat.enviar'), [
            'mensaje' => 'Resume este documento.',
            'documento' => UploadedFile::fake()->create('Informe anual.pdf', 120, 'application/pdf'),
        ]);
        $respuesta->streamedContent();
        $id = $respuesta->headers->get('X-Conversacion-Id');

        $this->postJson(route('chat.en-vivo.sesion'), ['conversacion_id' => $id])->assertOk();

        Http::assertSent(fn (PeticionHttp $peticion) => str_contains($peticion['session']['instructions'], 'Lo conversado antes en este chat')
            && str_contains($peticion['session']['instructions'], 'adjuntó el documento «Informe anual.pdf»')
            && str_contains($peticion['session']['instructions'], 'El informe trata de las ventas de 2025.'));

        // La conversación de otro usuario no se puede usar.
        $this->actingAs(User::factory()->create())
            ->postJson(route('chat.en-vivo.sesion'), ['conversacion_id' => $id])
            ->assertNotFound();
    }

    public function test_the_voice_asks_the_written_chat_about_a_document_without_duplicating_messages()
    {
        Storage::fake('local');
        Jarvis::fake(['Es el informe anual.']);
        ConsultorDelChat::fake(['La cláusula 5 fija el pago a 30 días.']);
        $usuario = User::factory()->create();
        $this->actingAs($usuario);

        $respuesta = $this->post(route('chat.enviar'), [
            'mensaje' => 'Resume este documento.',
            'documento' => UploadedFile::fake()->create('Contrato.pdf', 120, 'application/pdf'),
        ]);
        $respuesta->streamedContent();
        $id = $respuesta->headers->get('X-Conversacion-Id');

        $this->postJson(route('chat.en-vivo.herramienta'), [
            'nombre' => 'PreguntarAlChat',
            'argumentos' => ['pregunta' => '¿Qué dice la cláusula 5 del contrato?'],
            'conversacion_id' => $id,
        ])->assertOk()->assertJsonPath('resultado', 'La cláusula 5 fija el pago a 30 días.');

        // Recibe el documento completo desde el historial del chat.
        ConsultorDelChat::assertPrompted(function (AgentPrompt $prompt) {
            $mensajes = collect($prompt->agent->messages());

            return $prompt->contains('cláusula 5')
                && $mensajes->contains(fn ($mensaje) => $mensaje->attachments?->first() instanceof StoredDocument);
        });

        // La consulta no se guarda: lo dicho por voz se guarda al terminar.
        $this->assertSame(2, ConversationMessage::query()->where('conversation_id', $id)->count());

        // Sin conversación todavía, lo dice en vez de consultar.
        $this->postJson(route('chat.en-vivo.herramienta'), ['nombre' => 'PreguntarAlChat', 'argumentos' => ['pregunta' => 'Hola']])
            ->assertJsonPath('resultado', 'Este chat todavía no tiene documentos ni mensajes escritos.');
    }

    public function test_voice_messages_are_saved_at_the_time_they_were_said()
    {
        $this->actingAs(User::factory()->create());

        $id = $this->postJson(route('chat.en-vivo.guardar'), ['mensajes' => [
            ['rol' => 'usuario', 'contenido' => 'Primero', 'creado_en' => now()->subMinutes(5)->toIso8601String()],
            ['rol' => 'jarvis', 'contenido' => 'Segundo', 'creado_en' => now()->subMinutes(4)->toIso8601String()],
        ]])->assertOk()->json('conversacion_id');

        $horas = ConversationMessage::query()->where('conversation_id', $id)->oldest()->pluck('created_at');

        $this->assertEqualsWithDelta(now()->subMinutes(5)->timestamp, $horas[0]->timestamp, 2);
        $this->assertEqualsWithDelta(now()->subMinutes(4)->timestamp, $horas[1]->timestamp, 2);
    }

    public function test_the_voice_is_saved_in_parts_without_duplicating_messages()
    {
        $this->actingAs(User::factory()->create());
        $pregunta = (string) Str::uuid();
        $respuesta = (string) Str::uuid();

        // Mientras se habla: primero la pregunta y una respuesta a medias.
        $id = $this->postJson(route('chat.en-vivo.guardar'), ['mensajes' => [
            ['id' => $pregunta, 'rol' => 'usuario', 'contenido' => '¿Cómo vamos?', 'creado_en' => now()->subMinutes(3)->toIso8601String()],
            ['id' => $respuesta, 'rol' => 'jarvis', 'contenido' => 'Vamos', 'creado_en' => now()->subMinutes(2)->toIso8601String()],
        ]])->assertOk()->json('conversacion_id');

        // Al terminar se reenvía todo: lo ya guardado solo se actualiza.
        $this->postJson(route('chat.en-vivo.guardar'), ['conversacion_id' => $id, 'mensajes' => [
            ['id' => $pregunta, 'rol' => 'usuario', 'contenido' => '¿Cómo vamos?'],
            ['id' => $respuesta, 'rol' => 'jarvis', 'contenido' => 'Vamos bien en tráfico.'],
            ['id' => (string) Str::uuid(), 'rol' => 'usuario', 'contenido' => 'Gracias.'],
        ]])->assertOk()->assertJsonPath('conversacion_id', $id);

        $this->getJson(route('chat.mensajes', ['conversacion' => $id]))
            ->assertJsonCount(3, 'mensajes')
            ->assertJsonPath('mensajes.0.id', $pregunta)
            ->assertJsonPath('mensajes.0.en_vivo', true)
            ->assertJsonPath('mensajes.1.contenido', 'Vamos bien en tráfico.');

        // El ID de un mensaje de otra conversación no se toca.
        $otra = $this->postJson(route('chat.en-vivo.guardar'), ['mensajes' => [
            ['id' => $respuesta, 'rol' => 'jarvis', 'contenido' => 'Pisado'],
        ]])->assertOk()->json('conversacion_id');

        $this->assertNotSame($id, $otra);
        $this->assertSame('Vamos bien en tráfico.', ConversationMessage::query()->find($respuesta)->content);
    }

    public function test_the_voice_gets_the_critical_advisor_rules_without_the_written_format()
    {
        Http::fake(['api.openai.com/v1/realtime/client_secrets' => Http::response(['value' => 'ek_voz_1'])]);

        $this->actingAs(User::factory()->create())
            ->postJson(route('chat.en-vivo.sesion'))
            ->assertOk();

        Http::assertSent(fn (PeticionHttp $peticion) => str_contains($peticion['session']['instructions'], 'Directiva anti-complacencia')
            && str_contains($peticion['session']['instructions'], 'hablar breve no es dar la razón')
            && ! str_contains($peticion['session']['instructions'], 'Formato escrito')
            && ! str_contains($peticion['session']['instructions'], '### Resumen'));

        // El chat escrito sí recibe ambas.
        $escrito = (string) (new Jarvis)->instructions();
        $this->assertStringContainsString('Directiva anti-complacencia', $escrito);
        $this->assertStringContainsString('### Resumen', $escrito);
    }
}
