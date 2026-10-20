<?php

namespace App\Console\Commands;

use App\Http\Middleware\SesionEscritorio;
use App\Services\ReunionDeEjemplo;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;

/**
 * Deja lista la versión instalada en una PC: crea el .env desde la plantilla,
 * genera la llave de la app, guarda la llave de OpenAI, crea la base de datos
 * y el usuario local, y la primera vez carga la reunión de ejemplo. Se puede
 * repetir (al actualizar) sin perder datos.
 */
class InstalarJarvis extends Command
{
    protected $signature = 'jarvis:instalar
        {--llave= : Llave de OpenAI (si se omite, se conserva la que haya)}
        {--env= : Ruta del .env (por defecto, el de la app)}
        {--plantilla= : Plantilla para crear el .env (por defecto, .env.escritorio)}';

    protected $description = 'Configura el Asistente IA en esta PC (versión instalada)';

    public function handle(): int
    {
        $rutaEnv = $this->option('env') ?: base_path('.env');
        $plantilla = $this->option('plantilla') ?: base_path('.env.escritorio');

        if (! file_exists($rutaEnv)) {
            if (! file_exists($plantilla)) {
                $this->error("No existe la plantilla {$plantilla}.");

                return self::FAILURE;
            }

            copy($plantilla, $rutaEnv);
        }

        $env = (string) file_get_contents($rutaEnv);

        if ($this->valor($env, 'APP_KEY') === '') {
            $env = $this->asignar($env, 'APP_KEY', 'base64:'.base64_encode(Encrypter::generateKey(config('app.cipher'))));
        }

        $llave = trim((string) $this->option('llave'));

        if ($llave !== '') {
            // Solo caracteres de una llave real, para no romper el .env.
            if (! preg_match('/^[A-Za-z0-9_\-]+$/', $llave)) {
                $this->error('La llave de OpenAI no tiene un formato válido.');

                return self::FAILURE;
            }

            $env = $this->asignar($env, 'OPENAI_API_KEY', $llave);
        }

        file_put_contents($rutaEnv, $env);

        $baseDatos = config('database.connections.sqlite.database');

        if (config('database.default') === 'sqlite' && $baseDatos !== ':memory:' && ! file_exists($baseDatos)) {
            touch($baseDatos);
        }

        $this->call('migrate', ['--force' => true]);
        $usuario = SesionEscritorio::usuarioLocal();

        if ($ejemplo = app(ReunionDeEjemplo::class)->importarSiEstaVacia($usuario)) {
            $this->info("Se cargó la reunión de ejemplo «{$ejemplo->titulo}».");
        }

        if ($this->valor($env, 'OPENAI_API_KEY') === '') {
            $this->warn('Falta la llave de OpenAI: el Asistente IA no podrá transcribir ni responder hasta que se configure.');
        }

        $this->info('El Asistente IA quedó configurado.');

        return self::SUCCESS;
    }

    private function valor(string $env, string $clave): string
    {
        return preg_match("/^{$clave}=(.*)$/m", $env, $coincidencia)
            ? trim($coincidencia[1], " \t\"'")
            : '';
    }

    private function asignar(string $env, string $clave, string $valor): string
    {
        $linea = "{$clave}={$valor}";

        return preg_match("/^{$clave}=.*$/m", $env)
            ? preg_replace("/^{$clave}=.*$/m", str_replace('$', '\$', $linea), $env)
            : rtrim($env)."\n{$linea}\n";
    }
}
