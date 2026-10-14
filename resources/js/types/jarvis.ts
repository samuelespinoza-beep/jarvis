export type EstadoReunion = 'grabando' | 'procesando' | 'completada' | 'error';

export type Reunion = {
    id: string;
    titulo: string | null;
    estado: EstadoReunion;
    iniciada_en: string;
    finalizada_en: string | null;
    duracion_segundos: number | null;
    fragmentos_subidos?: number;
};

export type ConfigGrabacion = {
    fragmentoSegundos: number;
    audioBitsPorSegundo: number;
};
