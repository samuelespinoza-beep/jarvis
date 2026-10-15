import { http } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import type { Ref } from 'vue';
import { estado as estadoReunion } from '@/actions/App/Http/Controllers/ReunionController';
import type { ContenidoResumen, Reunion, TramoReunion } from '@/types';

const INTERVALO_MS = 5000;

// Mientras la reunión esté en alguno de estos estados todavía pueden llegar tramos o el acta.
const EN_CURSO: Reunion['estado'][] = ['grabando', 'pausada', 'procesando'];

/**
 * Línea de tiempo de la reunión del chat: cada minuto grabado con su tema y
 * resumen corto y, al terminar, el acta. Se consulta cada pocos segundos
 * mientras la reunión sigue o mientras se genera el acta.
 */
export function useLineaDeTiempo(reunionId: Ref<string | null>) {
    const tramos = ref<TramoReunion[]>([]);
    const acta = ref<ContenidoResumen | null>(null);
    const reunion = ref<Reunion | null>(null);

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
                const datos = JSON.parse(respuesta.data) as {
                    reunion: Reunion;
                    fragmentos: TramoReunion[];
                    resumen: ContenidoResumen | null;
                };

                tramos.value = datos.fragmentos;
                acta.value = datos.resumen;
                reunion.value = datos.reunion;
            }
        } catch {
            // Un fallo puntual no detiene la consulta; se reintenta en la próxima vuelta.
        }

        const sigue = !reunion.value || EN_CURSO.includes(reunion.value.estado);

        if (id === reunionId.value && sigue) {
            temporizador = window.setTimeout(() => consultar(id), INTERVALO_MS);
        }
    }

    /**
     * Vuelve a consultar ya: al pausar o terminar, para no esperar la próxima vuelta.
     */
    function actualizar() {
        if (reunionId.value) {
            window.clearTimeout(temporizador);
            void consultar(reunionId.value);
        }
    }

    watch(
        reunionId,
        (id) => {
            window.clearTimeout(temporizador);
            consultaActual++;
            tramos.value = [];
            acta.value = null;
            reunion.value = null;

            if (id) {
                void consultar(id);
            }
        },
        { immediate: true },
    );

    onBeforeUnmount(() => window.clearTimeout(temporizador));

    return { tramos, acta, reunion, actualizar };
}
