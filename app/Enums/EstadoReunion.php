<?php

namespace App\Enums;

enum EstadoReunion: string
{
    case Grabando = 'grabando';
    case Pausada = 'pausada';
    case Procesando = 'procesando';
    case Completada = 'completada';
    case Error = 'error';

    /**
     * Estados en los que la reunión sigue abierta y acepta más audio.
     *
     * @return list<self>
     */
    public static function abiertos(): array
    {
        return [self::Grabando, self::Pausada];
    }

    public function estaAbierta(): bool
    {
        return in_array($this, self::abiertos(), true);
    }
}
