const dosDigitos = (n: number) => String(n).padStart(2, '0');

/**
 * Segundos a "mm:ss", o "h:mm:ss" desde una hora.
 */
export function formatearTiempo(total: number): string {
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;

    return h > 0
        ? `${h}:${dosDigitos(m)}:${dosDigitos(s)}`
        : `${dosDigitos(m)}:${dosDigitos(s)}`;
}

export function formatearHora(iso: string): string {
    return new Date(iso).toLocaleTimeString('es-PE', {
        hour: '2-digit',
        minute: '2-digit',
    });
}

export function formatearFecha(iso: string): string {
    return new Date(iso).toLocaleDateString('es-PE', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}
