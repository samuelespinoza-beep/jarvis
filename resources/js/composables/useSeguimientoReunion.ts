import { http } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import type { Ref } from 'vue';
import {
    reprocesar as reprocesarReunion,
    estado as estadoReunion,
} from '@/actions/App/Http/Controllers/ReunionController';
import type { ContenidoResumen, FragmentoProcesado, Reunion } from '@/types';

type RespuestaEstado = {
    reunion: Reunion;
    fragmentos: FragmentoProcesado[];
    resumen: ContenidoResumen | null;
};

const INTERVALO_MS = 3000;

/**
 * Consulta el avance de una reunión terminada hasta que su transcripción y
 * su resumen estén listos o algo falle.
 */
export function useSeguimientoReunion(reunion: Ref<Reunion | null>) {
    const fragmentos = ref<FragmentoProcesado[]>([]);
    const resumen = ref<ContenidoResumen | null>(null);
    const reprocesando = ref(false);

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

            // Si mientras tanto se eligió otra reunión, se descarta esta respuesta.
            if (consulta !== consultaActual) {
                return;
            }

            const datos = JSON.parse(respuesta.data) as RespuestaEstado;

            fragmentos.value = datos.fragmentos;
            resumen.value = datos.resumen;
            reunion.value = datos.reunion;
        } catch {
            // Un fallo puntual de red no detiene el seguimiento; se reintenta en la próxima vuelta.
        }

        if (reunion.value?.id === id && reunion.value.estado === 'procesando') {
            temporizador = window.setTimeout(() => consultar(id), INTERVALO_MS);
        }
    }

    async function reprocesar() {
        if (!reunion.value || reprocesando.value) {
            return;
        }

        reprocesando.value = true;

        try {
            const respuesta = await http.getClient().request({
                method: 'post',
                url: reprocesarReunion.url(reunion.value.id),
                headers: { Accept: 'application/json' },
            });

            reunion.value = (
                JSON.parse(respuesta.data) as { reunion: Reunion }
            ).reunion;
        } finally {
            reprocesando.value = false;
        }
    }

    watch(
        () => [reunion.value?.id, reunion.value?.estado] as const,
        ([id, estado], anterior) => {
            const [idAnterior, estadoAnterior] = anterior ?? [];

            if (id === idAnterior && estado === estadoAnterior) {
                return;
            }

            window.clearTimeout(temporizador);

            if (!id || estado === 'grabando') {
                fragmentos.value = [];
                resumen.value = null;

                return;
            }

            // Una reunión completada solo se consulta una vez, para traer el resumen.
            if (
                estado === 'procesando' ||
                id !== idAnterior ||
                resumen.value === null
            ) {
                void consultar(id);
            }
        },
        { immediate: true },
    );

    onBeforeUnmount(() => window.clearTimeout(temporizador));

    return { fragmentos, resumen, reprocesando, reprocesar };
}
