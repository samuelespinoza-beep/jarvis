<?php

namespace App\Console\Commands;

use App\Models\Reunion;
use App\Services\ReunionDeEjemplo;
use Illuminate\Console\Command;

/**
 * Guarda una reunión como la reunión de ejemplo que trae la versión
 * instalada (database/datos/reunion-ejemplo). Se usa antes de generar los
 * instaladores.
 */
class ExportarReunionEjemplo extends Command
{
    protected $signature = 'jarvis:exportar-ejemplo
        {reunion : ID de la reunión}
        {--titulo= : Título con el que se instala (por defecto, el de la reunión)}';

    protected $description = 'Guarda una reunión como la reunión de ejemplo del instalador';

    public function handle(ReunionDeEjemplo $ejemplo): int
    {
        $reunion = Reunion::query()->find($this->argument('reunion'));

        if (! $reunion?->resumen) {
            $this->error('No existe esa reunión o todavía no tiene acta.');

            return self::FAILURE;
        }

        $ejemplo->exportar($reunion, $this->option('titulo') ?: null);
        $this->info('Reunión de ejemplo guardada en '.ReunionDeEjemplo::carpeta());

        return self::SUCCESS;
    }
}
