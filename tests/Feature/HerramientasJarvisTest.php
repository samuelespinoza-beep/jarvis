<?php

namespace Tests\Feature;

use App\Ai\Tools\ConsultarReuniones;
use App\Ai\Tools\LeerPagina;
use App\Ai\Tools\LeerReunion;
use App\Ai\Tools\UltimosVideosYoutube;
use App\Enums\EstadoFragmento;
use App\Enums\EstadoReunion;
use App\Models\Reunion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class HerramientasJarvisTest extends TestCase
{
    use RefreshDatabase;

    // IP pública literal: así la validación de la URL no depende del DNS.
    private const SITIO = 'https://93.184.215.14';

    public function test_reads_headlines_with_absolute_links()
    {
        Http::fake([self::SITIO.'/*' => Http::response(<<<'HTML'
            <html><head><title>Diario de prueba</title><meta name="description" content="Noticias"></head>
            <body>
                <a href="/">Inicio</a>
                <a href="/peru/2026/10/14/nota-uno/"><h2>Gobierno anuncia nuevo plan de inversión en carreteras</h2><p>Bajada</p><span>Por Autor</span></a>
                <a href="https://93.184.215.14/economia/nota-dos/">El dólar cierra a la baja por tercer día consecutivo</a>
                <a href="https://otro-sitio.com/publicidad/">Una publicidad con un texto bastante largo</a>
            </body></html>
            HTML, 200, ['Content-Type' => 'text/html; charset=utf-8'])]);

        $resultado = (string) (new LeerPagina)->handle(new Request(['url' => self::SITIO.'/', 'modo' => 'enlaces']));

        $this->assertStringContainsString('Página: Diario de prueba', $resultado);
        $this->assertStringContainsString('- Gobierno anuncia nuevo plan de inversión en carreteras — '.self::SITIO.'/peru/2026/10/14/nota-uno/', $resultado);
        $this->assertStringContainsString('El dólar cierra a la baja por tercer día consecutivo', $resultado);
        $this->assertStringNotContainsString('Inicio', $resultado);
        $this->assertStringNotContainsString('otro-sitio.com', $resultado);
    }

    public function test_reads_the_text_of_an_article()
    {
        Http::fake([self::SITIO.'/*' => Http::response(
            '<html><body><nav><p>Menú</p></nav><article><h1>Título de la nota</h1><p>Primer párrafo de la nota.</p></article></body></html>',
            200,
            ['Content-Type' => 'text/html'],
        )]);

        $resultado = (string) (new LeerPagina)->handle(new Request(['url' => self::SITIO.'/nota', 'modo' => 'texto']));

        $this->assertStringContainsString("Título de la nota\nPrimer párrafo de la nota.", $resultado);
        $this->assertStringNotContainsString('Menú', $resultado);
    }

    public function test_refuses_local_and_internal_addresses()
    {
        foreach (['http://127.0.0.1:8073/jarvis', 'http://localhost/', 'http://192.168.1.10/admin'] as $url) {
            $resultado = (string) (new LeerPagina)->handle(new Request(['url' => $url, 'modo' => 'enlaces']));

            $this->assertStringContainsString('No se permite visitar direcciones locales', $resultado, $url);
        }
    }

    public function test_lists_the_latest_youtube_videos()
    {
        config(['services.youtube.api_key' => 'clave-de-prueba']);

        Http::fake([
            'www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [[
                'id' => 'UCvsU0EGXN7Su7MfNqcTGNHg',
                'snippet' => ['title' => 'Infobae'],
                'contentDetails' => ['relatedPlaylists' => ['uploads' => 'UUvsU0EGXN7Su7MfNqcTGNHg']],
            ]]]),
            'www.googleapis.com/youtube/v3/playlistItems*' => Http::response(['items' => [[
                'snippet' => [
                    'title' => 'Resumen de noticias',
                    'publishedAt' => '2026-10-14T15:00:00Z',
                    'resourceId' => ['videoId' => 'abc123'],
                ],
            ]]]),
        ]);

        $resultado = (string) (new UltimosVideosYoutube)->handle(new Request(['canal' => '@infobae', 'cantidad' => 5]));

        $this->assertStringContainsString('Canal: Infobae', $resultado);
        $this->assertStringContainsString('- Resumen de noticias (14/10/2026 10:00) — https://www.youtube.com/watch?v=abc123', $resultado);

        Http::assertSent(fn ($peticion) => str_contains($peticion->url(), 'forHandle=infobae')
            && str_contains($peticion->url(), 'key=clave-de-prueba'));
    }

    public function test_youtube_explains_when_the_api_key_is_missing()
    {
        config(['services.youtube.api_key' => null]);

        $resultado = (string) (new UltimosVideosYoutube)->handle(new Request(['canal' => '@infobae', 'cantidad' => 5]));

        $this->assertStringContainsString('falta YOUTUBE_API_KEY', $resultado);
    }

    public function test_finds_meetings_and_reads_their_minutes()
    {
        $reunion = Reunion::create([
            'titulo' => 'Comité comercial',
            'estado' => EstadoReunion::Completada,
            'iniciada_en' => '2026-10-12 10:00:00',
            'duracion_segundos' => 1800,
            'creada_por' => User::factory()->create()->id,
        ]);
        $reunion->resumenes()->create([
            'version' => 1,
            'modelo' => 'gpt-6-luna',
            'contenido' => ['resumen_ejecutivo' => 'Se aprobó el presupuesto de marketing.', 'acuerdos' => []],
        ]);
        $reunion->fragmentos()->create([
            'orden' => 1, 'ruta_archivo' => 'x.webm', 'mime' => 'audio/webm', 'tamano_bytes' => 1,
            'duracion_segundos' => 1800, 'estado' => EstadoFragmento::Listo,
        ])->segmentos()->create([
            'reunion_id' => $reunion->id, 'orden' => 1, 'hablante' => 'A',
            'inicio_segundos' => 30, 'fin_segundos' => 35, 'texto' => 'Aprobamos el presupuesto.',
        ]);

        $encontradas = (string) (new ConsultarReuniones)->handle(new Request(['desde' => '2026-10-12', 'hasta' => null, 'texto' => 'presupuesto']));
        $this->assertStringContainsString("ID: {$reunion->id}", $encontradas);
        $this->assertStringContainsString('Se aprobó el presupuesto de marketing.', $encontradas);

        $ninguna = (string) (new ConsultarReuniones)->handle(new Request(['desde' => '2026-10-13', 'hasta' => null, 'texto' => null]));
        $this->assertSame('No se encontraron reuniones con esos criterios.', $ninguna);

        $acta = (string) (new LeerReunion)->handle(new Request(['id' => $reunion->id, 'incluir_transcripcion' => true]));
        $this->assertStringContainsString('Comité comercial', $acta);
        $this->assertStringContainsString('[00:30] Hablante A: Aprobamos el presupuesto.', $acta);
    }
}
