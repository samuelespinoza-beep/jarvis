<?php

namespace Tests\Feature;

use App\Ai\Agents\Jarvis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->getJson(route('chat.mensajes'))
            ->assertOk()
            ->assertJsonPath('conversacion_id', $id)
            ->assertJsonPath('mensajes.0.rol', 'usuario')
            ->assertJsonPath('mensajes.0.contenido', '¿Quién eres?')
            ->assertJsonPath('mensajes.1.rol', 'jarvis')
            ->assertJsonPath('mensajes.1.contenido', 'Hola, soy Jarvis.');
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
}
