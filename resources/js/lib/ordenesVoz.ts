export type OrdenVoz =
    | { tipo: 'pausar' }
    | { tipo: 'reanudar' }
    | { tipo: 'terminar' }
    | { tipo: 'pregunta'; texto: string };

// "Asistente" (o "Asistente IA") al inicio de la frase. Como "asistente" es una
// palabra común, solo cuenta si abre la frase; las órdenes además deben ser la
// frase completa y las preguntas esperan el Enter del usuario.
const ACTIVACION =
    /^\s*(?:(?:oye|hey|ok|okay|hola)[\s,]+)?(?:asistente|asistenta)\b(?:\s+(?:de\s+)?(?:ia|i\.\s?a|inteligencia artificial)\b\.?)?[\s,.:;!?-]*/i;

const OBJETO = '(?: (?:la|esta) (?:reunion|grabacion))?';
const CORTESIA = '(?: por favor)?';

// Las órdenes deben ser la frase completa ("Asistente, pausa la reunión"), para
// que una pregunta que empiece con esas palabras no la active por error.
const ORDENES: { tipo: 'pausar' | 'reanudar' | 'terminar'; patron: RegExp }[] =
    [
        {
            tipo: 'pausar',
            patron: new RegExp(
                `^(?:pausa|pausar|pausala|pon(?:er)? (?:en )?pausa|haz una pausa)${OBJETO}${CORTESIA}$`,
            ),
        },
        {
            tipo: 'reanudar',
            patron: new RegExp(
                `^(?:reanuda|reanudar|reanudala|continua|continuar|sigue|seguir|retoma|retomar|quita la pausa)(?: con)?${OBJETO}(?: grabando)?${CORTESIA}$`,
            ),
        },
        {
            tipo: 'terminar',
            patron: new RegExp(
                `^(?:termina|terminar|terminala|finaliza|finalizar|cierra|cerrar|acaba|acabar)${OBJETO}${CORTESIA}$`,
            ),
        },
    ];

function normalizar(texto: string): string {
    return texto
        .toLowerCase()
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9\s]/g, ' ')
        .replace(/\s+/g, ' ')
        .replace(/^por favor /, '')
        .trim();
}

/**
 * Interpreta una frase dicha en la reunión. Devuelve null si no va dirigida al
 * asistente; si va dirigida a él y no es una orden conocida, es una pregunta
 * para el chat.
 */
export function interpretarOrdenVoz(frase: string): OrdenVoz | null {
    const activacion = frase.match(ACTIVACION);

    if (!activacion) {
        return null;
    }

    const resto = frase.slice(activacion[0].length).trim();
    const normalizado = normalizar(resto);

    if (!normalizado) {
        return null;
    }

    const orden = ORDENES.find(({ patron }) => patron.test(normalizado));

    if (orden) {
        return { tipo: orden.tipo };
    }

    // Primera letra en mayúscula, respetando un "¿" o "¡" inicial.
    return {
        tipo: 'pregunta',
        texto: resto.replace(/\p{L}/u, (letra) => letra.toUpperCase()),
    };
}
