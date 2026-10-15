<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * En modo escritorio (versión instalada) entra directo con el usuario local,
 * sin pantalla de inicio de sesión.
 */
class SesionEscritorio
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('jarvis.escritorio') && ! Auth::check()) {
            Auth::login(self::usuarioLocal(), remember: true);
        }

        return $next($request);
    }

    /**
     * El usuario de la PC. Se crea la primera vez, con una contraseña al azar
     * que nadie necesita conocer.
     */
    public static function usuarioLocal(): User
    {
        $usuario = User::query()->oldest('id')->first() ?? User::query()->create([
            'name' => 'Usuario',
            'email' => 'local@jarvis.local',
            'password' => Str::random(40),
        ]);

        if (! $usuario->email_verified_at) {
            $usuario->forceFill(['email_verified_at' => now()])->save();
        }

        return $usuario;
    }
}
