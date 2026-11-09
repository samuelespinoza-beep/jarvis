// Frases que los modelos de transcripción inventan cuando solo reciben ruido o
// silencio (aprendidas de subtítulos de videos). Si el usuario de verdad dice
// "gracias", tampoco hace falta responderle: basta con que se calle.
const INVENTADAS = new Set([
    'gracias',
    'muchas gracias',
    'gracias por ver',
    'gracias por ver el video',
    'gracias por vernos',
    'gracias por su atencion',
    'hasta la proxima',
    'nos vemos',
    'chao',
    'adios',
    'musica',
    'aplausos',
    'risas',
    'silencio',
    'eh',
    'ehm',
    'em',
    'mm',
    'mmm',
    'hmm',
    'ah',
    'oh',
    'uh',
    'um',
]);

const PISTAS_INVENTADAS = [
    'amara org',
    'suscribete',
    'subtitulos',
    'subtitulado',
];

function normalizar(texto: string): string {
    return texto
        .toLowerCase()
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9\s]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

type Probabilidad = { logprob: number };

/**
 * Qué tan seguro estuvo el transcriptor (0 a 1), como media geométrica de la
 * probabilidad de cada pieza. Sin datos devuelve 1, para no descartar nada.
 */
export function confianza(probabilidades?: Probabilidad[] | null): number {
    if (!probabilidades?.length) {
        return 1;
    }

    const media =
        probabilidades.reduce((suma, p) => suma + p.logprob, 0) /
        probabilidades.length;

    return Math.exp(media);
}

/**
 * Si lo transcrito es ruido y no algo que el usuario dijo: vacío, sin
 * letras, una muletilla suelta o una de las frases que el modelo inventa
 * con el silencio, o algo que el transcriptor oyó con muy poca seguridad.
 */
export function esRuido(
    texto: string,
    probabilidades?: Probabilidad[] | null,
    confianzaMinima = 0,
): boolean {
    const limpio = normalizar(texto);

    if (limpio.replace(/\s/g, '').length < 2) {
        return true;
    }

    if (confianza(probabilidades) < confianzaMinima) {
        return true;
    }

    return (
        INVENTADAS.has(limpio) ||
        PISTAS_INVENTADAS.some((pista) => limpio.includes(pista))
    );
}
