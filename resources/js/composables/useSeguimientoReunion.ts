import { http } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import type { Ref } from 'vue';
import {
    reprocesar as reprocesarReunion,
    show as verReunion,
} from '@/actions/App/Http/Controllers/ReunionController';
import type {
    FragmentoProcesado,
    Reunion,
    SegmentoTranscripcion,
} from '@/types';

type RespuestaEstado = {
    reunion: Reunion;
    fragmentos: FragmentoProcesado[];
    segmentos: SegmentoTranscripcion[];
};

const INTERVALO_MS = 3000;

/**
 * Consulta el avance de una reunión terminada hasta que su transcripción
 * esté completa o falle.
 */
export function useSeguimientoReunion(reunion: Ref<Reunion | null>) {
    const fragmentos = ref<FragmentoProcesado[]>([]);
    const segmentos = ref<SegmentoTranscripcion[]>([]);
    const reprocesando = ref(false);

    let temporizador: number | undefined;
    let consultaActual = 0;

    async function consultar(id: string) {
        const consulta = ++consultaActual;

        try {
            const respuesta = await http.getClient().request({
                method: 'get',
                url: verReunion.url(id),
                headers: { Accept: 'application/json' },
            });

            // Si mientras tanto se eligió otra reunión, se descarta esta respuesta.
            if (consulta !== consultaActual) {
                return;
            }

            const datos = JSON.parse(respuesta.data) as RespuestaEstado;

            fragmentos.value = datos.fragmentos;
            segmentos.value = datos.segmentos;
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
                segmentos.value = [];

                return;
            }

            // Una reunión completada solo se consulta una vez, para traer la transcripción.
            if (
                estado === 'procesando' ||
                id !== idAnterior ||
                segmentos.value.length === 0
            ) {
                void consultar(id);
            }
        },
        { immediate: true },
    );

    onBeforeUnmount(() => window.clearTimeout(temporizador));

    return { fragmentos, segmentos, reprocesando, reprocesar };
}
