<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import NucleoJarvis from '@/components/jarvis/NucleoJarvis.vue';
import type { EstadoNucleo, Nucleo } from '@/lib/nucleoCircuitos';

const props = defineProps<{
    estado: EstadoNucleo;
    // Volumen de la voz (0 a 1): el núcleo respira con él.
    nivel: () => number;
}>();

const lienzo = ref<HTMLCanvasElement | null>(null);
// Sin WebGL se muestra el núcleo plano de siempre.
const sin3d = ref(false);
let nucleo: Nucleo | null = null;

let desmontado = false;

onMounted(async () => {
    // Three.js se carga aparte, así la página se muestra sin esperarlo.
    const { crearNucleo } = await import('@/lib/nucleoCircuitos');

    if (desmontado || !lienzo.value) {
        return;
    }

    nucleo = crearNucleo(lienzo.value, () => props.nivel());

    if (!nucleo) {
        sin3d.value = true;

        return;
    }

    nucleo.cambiarEstado(props.estado);
});

watch(
    () => props.estado,
    (estado) => nucleo?.cambiarEstado(estado),
);

onBeforeUnmount(() => {
    desmontado = true;
    nucleo?.destruir();
});
</script>

<template>
    <div class="relative h-full w-full">
        <NucleoJarvis
            v-if="sin3d"
            class="mx-auto h-full max-w-full"
            :activo="estado !== 'reposo'"
        />
        <!-- El lienzo es negro con luz sumada: con "screen" el negro desaparece sobre el fondo oscuro. -->
        <canvas
            v-else
            ref="lienzo"
            aria-hidden="true"
            class="nucleo-circuitos absolute inset-0 h-full w-full mix-blend-screen"
        />
    </div>
</template>

<style scoped>
/* Bordes del lienzo difuminados, para que no se note dónde termina. */
.nucleo-circuitos {
    mask-image: radial-gradient(closest-side, #000 62%, transparent 100%);
}
</style>
