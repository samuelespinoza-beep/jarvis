<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import type { Reunion } from '@/types';

const { reunion } = defineProps<{
    reunion: Pick<Reunion, 'estado' | 'transcripcion_lista'>;
}>();

const etiqueta = computed(() => {
    switch (reunion.estado) {
        case 'grabando':
            return {
                texto: 'Grabando',
                clase: 'border-rose-300 text-rose-600',
            };
        case 'pausada':
            return {
                texto: 'En pausa',
                clase: 'border-amber-300 text-amber-700 dark:text-amber-700',
            };
        case 'procesando':
            return {
                texto: reunion.transcripcion_lista
                    ? 'Generando resumen'
                    : 'Transcribiendo',
                clase: 'border-hud-acento/40 text-hud-acento-texto dark:text-hud-acento-texto',
            };
        case 'completada':
            return {
                texto: 'Completada',
                clase: 'border-emerald-300 text-emerald-600 dark:text-emerald-600',
            };
        default:
            return {
                texto: 'Error',
                clase: 'border-rose-300 text-rose-600',
            };
    }
});
</script>

<template>
    <Badge variant="outline" :class="etiqueta.clase">{{
        etiqueta.texto
    }}</Badge>
</template>
