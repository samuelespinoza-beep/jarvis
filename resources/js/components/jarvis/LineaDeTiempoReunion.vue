<script setup lang="ts">
import { Clock, LoaderCircle } from '@lucide/vue';
import { computed } from 'vue';
import { formatearTiempo } from '@/lib/formato';
import type { TramoReunion } from '@/types';

const props = defineProps<{
    tramos: TramoReunion[];
    // Mientras se graba, el minuto actual todavía no aparece: se avisa.
    grabando: boolean;
}>();

// Lo más reciente arriba: es lo que se quiere ver durante la reunión.
const recientesPrimero = computed(() => [...props.tramos].reverse());

function rango(tramo: TramoReunion): string {
    return `${formatearTiempo(tramo.inicio_segundos)} – ${formatearTiempo(
        tramo.inicio_segundos + tramo.duracion_segundos,
    )}`;
}
</script>

<template>
    <section class="flex flex-col gap-2">
        <h3
            class="flex items-center gap-2 text-sm font-semibold text-hud-texto"
        >
            <Clock class="size-4 text-hud-acento-texto" />
            Minuto a minuto
        </h3>

        <p v-if="grabando" class="text-xs text-hud-suave">
            Minuto en curso… se resumirá al completarse.
        </p>

        <ol class="flex flex-col gap-2">
            <li
                v-for="tramo in recientesPrimero"
                :key="tramo.orden"
                class="rounded-xl border border-dashed border-hud-acento/25 bg-hud-superficie/40 px-3 py-2 text-sm"
            >
                <p class="font-mono text-[11px] text-hud-acento-texto/60">
                    Minuto {{ rango(tramo) }}
                </p>
                <template v-if="tramo.resumen">
                    <p class="font-medium text-hud-texto">
                        {{ tramo.resumen.tema }}
                    </p>
                    <p class="text-hud-texto/70">
                        {{ tramo.resumen.resumen }}
                    </p>
                    <ul
                        v-if="tramo.resumen.puntos.length"
                        class="mt-1 list-disc pl-5 text-xs text-hud-texto/60"
                    >
                        <li v-for="punto in tramo.resumen.puntos" :key="punto">
                            {{ punto }}
                        </li>
                    </ul>
                </template>
                <p
                    v-else-if="tramo.estado === 'error'"
                    class="text-xs text-rose-600"
                    :title="tramo.error ?? undefined"
                >
                    No se pudo transcribir este minuto.
                </p>
                <p
                    v-else
                    class="flex items-center gap-2 text-xs text-hud-acento-texto/70"
                >
                    <LoaderCircle class="size-3 animate-spin" />
                    {{
                        tramo.estado === 'listo'
                            ? 'Resumiendo…'
                            : 'Transcribiendo…'
                    }}
                </p>
            </li>
        </ol>
    </section>
</template>
