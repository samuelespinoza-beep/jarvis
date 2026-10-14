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

export type EstadoFragmento =
    | 'pendiente'
    | 'transcribiendo'
    | 'listo'
    | 'error';

export type FragmentoProcesado = {
    orden: number;
    estado: EstadoFragmento;
    error: string | null;
};

export type SegmentoTranscripcion = {
    hablante: string | null;
    inicio_segundos: number;
    texto: string;
};

export type ConfigGrabacion = {
    fragmentoSegundos: number;
    audioBitsPorSegundo: number;
};
