export type EstadoReunion =
    | 'grabando'
    | 'pausada'
    | 'procesando'
    | 'completada'
    | 'error';

export type Reunion = {
    id: string;
    titulo: string | null;
    // Puntos a tratar, en orden.
    agenda: string[];
    estado: EstadoReunion;
    iniciada_en: string;
    pausada_en: string | null;
    finalizada_en: string | null;
    duracion_segundos: number | null;
    transcripcion_lista: boolean;
    fragmentos_subidos?: number;
    segundos_grabados?: number;
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
    enVivo: boolean;
};

export type ConfigVoces = {
    porDefecto: string;
    lista: { id: string; descripcion: string }[];
};

export type ConfigDocumentos = {
    formatos: string[];
    maxKb: number;
};

export type PuntoResumen = {
    tema: string;
    detalle: string;
    minuto_aprox: number | null;
};

export type AcuerdoResumen = {
    descripcion: string;
    responsable: string | null;
    fecha_limite: string | null;
};

export type ContenidoResumen = {
    titulo_sugerido: string;
    resumen_ejecutivo: string;
    temas_tratados: string[];
    puntos_principales: PuntoResumen[];
    puntos_secundarios: PuntoResumen[];
    acuerdos: AcuerdoResumen[];
    pendientes: string[];
    riesgos_o_puntos_criticos: string[];
    sugerencias_de_mejora: string[];
    // Las actas anteriores a la agenda no lo tienen.
    agenda?: PuntoAgendaActa[];
    // Las actas anteriores a incluir el chat no lo tienen.
    documentos_revisados?: DocumentoRevisado[];
};

export type DocumentoRevisado = {
    nombre: string;
    aporte: string;
};

export type PuntoAgendaActa = {
    punto: string;
    estado: 'tratado' | 'parcial' | 'no_tratado';
    comentario: string;
};

export type InformePrevio = {
    generado_en: string;
    reuniones: { id: string; titulo: string; fecha: string }[];
    informe: {
        resumen: string;
        compromisos: {
            descripcion: string;
            responsable: string | null;
            fecha_limite: string | null;
            fecha_iso: string | null;
            reunion: string;
            vencido: boolean;
        }[];
        retomar: { tema: string; motivo: string; reunion: string }[];
        alertas: string[];
        sugerencias_agenda: string[];
        antecedentes_agenda: { punto: string; antecedentes: string }[];
    } | null;
};

export type TramoReunion = {
    orden: number;
    estado: EstadoFragmento;
    error: string | null;
    inicio_segundos: number;
    duracion_segundos: number;
    resumen: { tema: string; resumen: string; puntos: string[] } | null;
    // Hora en que terminó el minuto (ISO), para ubicarlo en el chat.
    creado_en: string | null;
};
