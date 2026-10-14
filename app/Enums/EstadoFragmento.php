<?php

namespace App\Enums;

enum EstadoFragmento: string
{
    case Pendiente = 'pendiente';
    case Transcribiendo = 'transcribiendo';
    case Listo = 'listo';
    case Error = 'error';
}
