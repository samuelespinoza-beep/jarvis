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

/**
 * Bytes a "850 KB" o "2.4 MB".
 */
export function formatearTamano(bytes: number): string {
    const kb = bytes / 1024;

    return kb < 1024
        ? `${Math.max(1, Math.round(kb))} KB`
        : `${(kb / 1024).toFixed(1)} MB`;
}
