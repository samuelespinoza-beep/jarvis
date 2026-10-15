<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Ai\Prompts\TranscriptionPrompt;
use Laravel\Ai\Transcription;
use Tests\TestCase;

class DictadoTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_dictate()
    {
        $this->postJson(route('chat.dictado'))->assertUnauthorized();
    }

    public function test_dictated_audio_is_transcribed_into_text()
    {
        Transcription::fake(['  ¿Qué acordamos el lunes?  ']);

        $this->actingAs(User::factory()->create())
            ->post(route('chat.dictado'), [
                'audio' => UploadedFile::fake()->createWithContent('dictado.webm', 'audio-de-prueba'),
            ])
            ->assertOk()
            ->assertExactJson(['texto' => '¿Qué acordamos el lunes?']);

        Transcription::assertGenerated(fn (TranscriptionPrompt $prompt) => $prompt->model === 'gpt-4o-transcribe'
            && $prompt->language === 'es'
            && ! $prompt->isDiarized());
    }

    public function test_files_that_are_not_audio_are_rejected()
    {
        Transcription::fake();

        $this->actingAs(User::factory()->create())
            ->post(route('chat.dictado'), [
                'audio' => UploadedFile::fake()->create('nota.pdf', 10, 'application/pdf'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['audio' => 'Formato de audio no admitido.']);

        Transcription::assertNothingGenerated();
    }
}
