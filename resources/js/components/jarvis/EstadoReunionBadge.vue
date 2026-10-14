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
                clase: 'border-rose-500/40 text-rose-500',
            };
        case 'pausada':
            return {
                texto: 'En pausa',
                clase: 'border-amber-500/40 text-amber-600 dark:text-amber-300',
            };
        case 'procesando':
            return {
                texto: reunion.transcripcion_lista
                    ? 'Generando resumen'
                    : 'Transcribiendo',
                clase: 'border-cyan-500/40 text-cyan-600 dark:text-cyan-300',
            };
        case 'completada':
            return {
                texto: 'Completada',
                clase: 'border-emerald-500/40 text-emerald-600 dark:text-emerald-300',
            };
        default:
            return {
                texto: 'Error',
                clase: 'border-rose-500/40 text-rose-500',
            };
    }
});
</script>

<template>
    <Badge variant="outline" :class="etiqueta.clase">{{
        etiqueta.texto
    }}</Badge>
</template>
