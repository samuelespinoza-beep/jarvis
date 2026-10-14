<?php

namespace App\Enums;

enum EstadoReunion: string
{
    case Grabando = 'grabando';
    case Procesando = 'procesando';
    case Completada = 'completada';
    case Error = 'error';
}
