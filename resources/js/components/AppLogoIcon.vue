<script setup lang="ts">
import { Radar } from '@lucide/vue';
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!-- Ícono del Asistente IA: el mismo radar del menú lateral y del favicon. -->
    <Radar :class="className" v-bind="$attrs" />
</template>
