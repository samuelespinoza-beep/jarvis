<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TextoHablado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Audio;
use Laravel\Ai\Prompts\AudioPrompt;
use Tests\TestCase;

class VozTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_generate_audio()
    {
        $this->postJson(route('chat.voz'), ['texto' => 'Hola'])->assertUnauthorized();
    }

    public function test_it_reads_the_answer_aloud_without_markdown()
    {
        Audio::fake();

        $respuesta = $this->actingAs(User::factory()->create())->post(route('chat.voz'), [
            'texto' => "### Resumen\nLas ventas **crecieron 12%**. Fuente: [Infobae](https://www.infobae.com/nota).\n- Canal digital\n- Riesgo cambiario",
        ]);

        $respuesta->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
        $this->assertSame('fake-audio-content', $respuesta->getContent());

        Audio::assertGenerated(fn (AudioPrompt $prompt) => $prompt->text === "Resumen.\nLas ventas crecieron 12%. Fuente: Infobae.\nCanal digital.\nRiesgo cambiario."
            && $prompt->voice === 'onyx'
            && $prompt->model === 'gpt-4o-mini-tts'
            && str_contains((string) $prompt->instructions, 'español'));
    }

    public function test_a_text_without_anything_to_read_is_rejected()
    {
        Audio::fake();

        $this->actingAs(User::factory()->create())
            ->post(route('chat.voz'), ['texto' => 'https://www.infobae.com/'])
            ->assertUnprocessable();

        Audio::assertNothingGenerated();
    }

    public function test_long_answers_are_cut_at_a_sentence_and_announce_the_rest()
    {
        $texto = TextoHablado::desdeMarkdown(str_repeat('Una oración de prueba. ', 50), 200);

        $this->assertLessThanOrEqual(200, mb_strlen($texto));
        $this->assertStringEndsWith('prueba. El resto de la respuesta está en pantalla.', $texto);
    }
}
