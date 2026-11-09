// Herramientas de la voz en vivo cuyo resultado trae enlaces a sitios externos.
const CON_ENLACES = new Set([
    'BuscarEnInternet',
    'LeerPagina',
    'UltimosVideosYoutube',
]);

const MAX_ENLACES = 8;

const MAX_TITULO = 120;

/**
 * La voz no dice direcciones web, así que los enlaces que trajo una
 * herramienta se muestran escritos en el chat. Reconoce los dos formatos que
 * devuelven las herramientas: "[título](url)" (búsqueda web) y
 * "- título — url" (portadas y YouTube). Devuelve la lista en Markdown, o
 * null si no hay enlaces.
 */
export function enlacesDe(
    herramienta: string | undefined,
    resultado: string,
): string | null {
    if (!herramienta || !CON_ENLACES.has(herramienta)) {
        return null;
    }

    const enlaces = new Map<string, string>();
    const agregar = (titulo: string, url: string) => {
        const limpio = titulo.replace(/[[\]*]/g, '').trim();

        if (!enlaces.has(url) && enlaces.size < MAX_ENLACES) {
            enlaces.set(
                url,
                limpio.length > MAX_TITULO
                    ? `${limpio.slice(0, MAX_TITULO - 1)}…`
                    : limpio || url,
            );
        }
    };

    const lineas = resultado.split('\n');

    // Primero "- titular — url", que trae el titular de la nota; los enlaces
    // Markdown del texto suelen llevar solo el nombre del sitio.
    for (const linea of lineas) {
        const conGuion = linea.match(
            /^\s*-\s+(.+?)\s+—\s+(https?:\/\/[^\s)]+)\s*$/,
        );

        if (conGuion) {
            agregar(conGuion[1], conGuion[2]);
        }
    }

    for (const linea of lineas) {
        for (const [, titulo, url] of linea.matchAll(
            /\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g,
        )) {
            agregar(titulo, url);
        }
    }

    if (!enlaces.size) {
        return null;
    }

    return [
        'Enlaces:',
        ...[...enlaces].map(([url, titulo]) => `- [${titulo}](${url})`),
    ].join('\n');
}
