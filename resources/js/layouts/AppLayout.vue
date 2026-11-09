<script setup lang="ts">
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';

const { breadcrumbs = [], sinEncabezado = false } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
    // La página trae su propia barra superior (con el botón del menú).
    sinEncabezado?: boolean;
}>();
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs" :sin-encabezado="sinEncabezado">
        <slot />
    </AppLayout>
</template>
