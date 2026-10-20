<?php

namespace Tests\Feature;

use App\Ai\Agents\GeneradorAgenda;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Prompts\AgentPrompt;
use RuntimeException;
use Tests\TestCase;

class GenerarAgendaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->actingAs(User::factory()->create());
    }

    public function test_generates_the_agenda_from_screenshots_and_documents_without_storing_them()
    {
        GeneradorAgenda::fake([[
            'puntos' => [
                '13:00–13:15 · Performance Digital y Audiencias. Lidera: Gerente Digital. Temas: PVs, usuarios únicos, KPIs.',
                '13:15–13:30 · Conversión Comercial. Lidera: Gerenta Comercial.',
            ],
            'aviso' => null,
        ]]);

        $this->post(route('reuniones.agenda.generar'), [
            'archivos' => [
                UploadedFile::fake()->image('Bloque I.png', 60, 40),
                UploadedFile::fake()->createWithContent('Bloque II.pdf', '%PDF-1.4 agenda'),
            ],
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('puntos.0', '13:00–13:15 · Performance Digital y Audiencias. Lidera: Gerente Digital. Temas: PVs, usuarios únicos, KPIs.')
            ->assertJsonCount(2, 'puntos')
            ->assertJsonPath('aviso', null);

        GeneradorAgenda::assertPrompted(fn (AgentPrompt $prompt) => $prompt->attachments->count() === 2
            && $prompt->attachments[0] instanceof Base64Image
            && $prompt->attachments[0]->name() === 'Bloque I.png'
            && $prompt->attachments[1] instanceof Base64Document);

        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_generates_the_agenda_from_pasted_text_and_trims_long_points()
    {
        GeneradorAgenda::fake([[
            'puntos' => [str_repeat('Punto muy largo ', 30), 'Cierre', 'Cierre'],
            'aviso' => 'La última línea no se leía bien.',
        ]]);

        $respuesta = $this->postJson(route('reuniones.agenda.generar'), ['texto' => "1. Ventas\n2. Cierre"])
            ->assertOk()
            ->assertJsonCount(2, 'puntos')
            ->assertJsonPath('puntos.1', 'Cierre')
            ->assertJsonPath('aviso', 'La última línea no se leía bien.');

        $this->assertLessThanOrEqual(200, Str::length($respuesta->json('puntos.0')));
        GeneradorAgenda::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains("1. Ventas\n2. Cierre"));
    }

    public function test_needs_a_file_or_text_and_rejects_unsupported_files()
    {
        GeneradorAgenda::fake()->preventStrayPrompts();

        $this->postJson(route('reuniones.agenda.generar'), ['texto' => '  '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['archivos' => 'Adjunta una imagen o un documento']);

        $this->post(route('reuniones.agenda.generar'), [
            'archivos' => [UploadedFile::fake()->create('agenda.exe', 10, 'application/x-msdownload')],
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['archivos.0' => 'Formato no admitido']);

        $this->post(route('reuniones.agenda.generar'), [
            'archivos' => array_map(fn (int $i) => UploadedFile::fake()->image("{$i}.png"), range(1, 6)),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['archivos' => 'hasta 5 archivos']);

        GeneradorAgenda::assertNeverPrompted();
    }

    public function test_reports_a_friendly_error_when_the_ai_fails()
    {
        GeneradorAgenda::fake(fn () => throw new RuntimeException('Sin conexión.'));

        $this->postJson(route('reuniones.agenda.generar'), ['texto' => 'Ventas'])
            ->assertStatus(502)
            ->assertJsonPath('message', 'No se pudo generar la agenda. Intenta de nuevo.');
    }
}
