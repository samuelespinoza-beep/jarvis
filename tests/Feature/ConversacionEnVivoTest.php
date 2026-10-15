<?php

namespace Tests\Feature;

use App\Ai\Agents\BuscadorWeb;
use App\Ai\Agents\Jarvis;
use App\Enums\EstadoReunion;
use App\Models\Reunion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\Http;
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
                && $herramientas === ['BuscarEnInternet', 'LeerPagina', 'UltimosVideosYoutube', 'ConsultarReuniones', 'LeerReunion']
                && $sesion['tools'][0]['parameters']['required'] === ['consulta'];
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
}
