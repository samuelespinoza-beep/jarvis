import { http } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import type { Ref } from 'vue';
import { estado as estadoReunion } from '@/actions/App/Http/Controllers/ReunionController';
import type { TramoReunion } from '@/types';

const INTERVALO_MS = 5000;

/**
 * Línea de tiempo de la reunión abierta: cada minuto grabado con su tema y
 * resumen corto. Se consulta cada pocos segundos mientras la reunión sigue.
 */
export function useLineaDeTiempo(reunionId: Ref<string | null>) {
    const tramos = ref<TramoReunion[]>([]);

    let temporizador: number | undefined;
    let consultaActual = 0;

    async function consultar(id: string) {
        const consulta = ++consultaActual;

        try {
            const respuesta = await http.getClient().request({
                method: 'get',
                url: estadoReunion.url(id),
                headers: { Accept: 'application/json' },
            });

            if (consulta === consultaActual && id === reunionId.value) {
                tramos.value = (
                    JSON.parse(respuesta.data) as { fragmentos: TramoReunion[] }
                ).fragmentos;
            }
        } catch {
            // Un fallo puntual no detiene la consulta; se reintenta en la próxima vuelta.
        }

        if (id === reunionId.value) {
            temporizador = window.setTimeout(() => consultar(id), INTERVALO_MS);
        }
    }

    watch(
        reunionId,
        (id) => {
            window.clearTimeout(temporizador);
            consultaActual++;
            tramos.value = [];

            if (id) {
                void consultar(id);
            }
        },
        { immediate: true },
    );

    onBeforeUnmount(() => window.clearTimeout(temporizador));

    return { tramos };
}
