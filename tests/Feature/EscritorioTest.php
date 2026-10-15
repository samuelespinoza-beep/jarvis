<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EscritorioTest extends TestCase
{
    use RefreshDatabase;

    public function test_without_desktop_mode_guests_go_to_the_login()
    {
        $this->get(route('jarvis'))->assertRedirect(route('login'));
    }

    public function test_desktop_mode_enters_directly_with_the_local_user()
    {
        config(['jarvis.escritorio' => true]);

        $this->get(route('jarvis'))->assertOk();
        $this->assertAuthenticated();
        $this->assertSame(1, User::count());
        $this->assertNotNull(User::first()->email_verified_at);

        // La siguiente visita usa el mismo usuario, no crea otro.
        auth()->logout();
        $this->get(route('jarvis'))->assertOk();
        $this->assertSame(1, User::count());
    }

    public function test_the_install_command_creates_the_env_and_keeps_it_on_updates()
    {
        $carpeta = sys_get_temp_dir().'/jarvis-'.uniqid();
        mkdir($carpeta);
        $env = "{$carpeta}/.env";
        $plantilla = base_path('instalador/env.escritorio');

        $this->artisan('jarvis:instalar', ['--env' => $env, '--plantilla' => $plantilla, '--llave' => 'sk-prueba_123'])
            ->assertSuccessful();

        $contenido = file_get_contents($env);
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:.{40,}$/m', $contenido);
        $this->assertStringContainsString("\nOPENAI_API_KEY=sk-prueba_123\n", $contenido);
        $this->assertStringContainsString('JARVIS_ESCRITORIO=true', $contenido);
        $this->assertSame(1, User::count());

        // Al actualizar sin llave se conservan la llave de OpenAI y la de la app.
        preg_match('/^APP_KEY=(.*)$/m', $contenido, $clave);
        $this->artisan('jarvis:instalar', ['--env' => $env, '--plantilla' => $plantilla])->assertSuccessful();

        $actualizado = file_get_contents($env);
        $this->assertStringContainsString("APP_KEY={$clave[1]}", $actualizado);
        $this->assertStringContainsString('OPENAI_API_KEY=sk-prueba_123', $actualizado);
        $this->assertSame(1, User::count());

        unlink($env);
        rmdir($carpeta);
    }

    public function test_an_invalid_openai_key_is_rejected()
    {
        $carpeta = sys_get_temp_dir().'/jarvis-'.uniqid();
        mkdir($carpeta);

        $this->artisan('jarvis:instalar', [
            '--env' => "{$carpeta}/.env",
            '--plantilla' => base_path('instalador/env.escritorio'),
            '--llave' => "sk-1\nAPP_DEBUG=true",
        ])->assertFailed();

        $this->assertStringNotContainsString('APP_DEBUG=true', file_get_contents("{$carpeta}/.env"));

        unlink("{$carpeta}/.env");
        rmdir($carpeta);
    }
}
