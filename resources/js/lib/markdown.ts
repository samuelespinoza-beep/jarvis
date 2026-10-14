/**
 * Convierte el Markdown simple que devuelve Jarvis (párrafos, listas,
 * negritas y enlaces) en HTML seguro: todo el texto se escapa primero y solo
 * se generan las etiquetas permitidas, con enlaces http(s) únicamente.
 */
export function markdownSeguro(texto: string): string {
    const bloques = escapar(texto).split(/\n{2,}/);

    return bloques.map(bloque).join('');
}

function escapar(texto: string): string {
    return texto
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

type Lista = { tipo: 'ul' | 'ol'; items: string[] };

function bloque(contenido: string): string {
    const lineas = contenido.split('\n').filter((linea) => linea.trim());
    const html: string[] = [];
    let lista = null as Lista | null;

    const cerrar = (abierta: Lista | null): null => {
        if (abierta) {
            html.push(
                `<${abierta.tipo}>${abierta.items.map((item) => `<li>${item}</li>`).join('')}</${abierta.tipo}>`,
            );
        }

        return null;
    };

    for (const linea of lineas) {
        const vineta = linea.match(/^\s*[-*•]\s+(.*)$/);
        const numerada = linea.match(/^\s*\d+[.)]\s+(.*)$/);
        const titulo = linea.match(/^#{1,6}\s+(.*)$/);
        const item = vineta ?? numerada;

        if (item) {
            const tipo = vineta ? 'ul' : 'ol';

            if (lista?.tipo !== tipo) {
                cerrar(lista);
                lista = { tipo, items: [] };
            }

            lista.items.push(enLinea(item[1]));
            continue;
        }

        lista = cerrar(lista);
        html.push(
            titulo
                ? `<p class="font-semibold">${enLinea(titulo[1])}</p>`
                : `<p>${enLinea(linea)}</p>`,
        );
    }

    cerrar(lista);

    return html.join('');
}

function enLinea(texto: string): string {
    const enlaces: string[] = [];
    const guardar = (html: string) => `\uE000${enlaces.push(html) - 1}\uE000`;
    const enlace = (url: string, titulo: string) =>
        `<a href="${url}" target="_blank" rel="noopener noreferrer">${titulo}</a>`;

    return texto
        .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, (_, titulo, url) =>
            guardar(enlace(url, titulo)),
        )
        .replace(/https?:\/\/[^\s<]+[^\s<.,;:!?)]/g, (url) =>
            guardar(enlace(url, url)),
        )
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/\uE000(\d+)\uE000/g, (_, indice) => enlaces[Number(indice)]);
}
