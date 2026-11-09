<script setup lang="ts">
import NucleoCircuitos from '@/components/jarvis/NucleoCircuitos.vue';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="fondo-nucleo relative flex min-h-svh flex-col items-center justify-center overflow-hidden bg-hud-fondo p-6 text-hud-texto md:p-10"
    >
        <div class="relative flex w-full max-w-sm flex-col gap-6">
            <div class="flex flex-col items-center gap-3 text-center">
                <div class="size-48 sm:size-56">
                    <NucleoCircuitos estado="reposo" :nivel="() => 0" />
                </div>
                <p
                    class="font-mono text-xs tracking-[0.6em] text-primary"
                    aria-hidden="true"
                >
                    Asistente IA
                </p>
                <div class="space-y-1">
                    <h1 class="text-xl font-medium">{{ title }}</h1>
                    <p class="text-sm text-muted-foreground">
                        {{ description }}
                    </p>
                </div>
            </div>

            <div
                class="relative rounded-xl border border-primary/25 bg-hud-superficie/60 p-6 shadow-[0_0_60px_-20px_rgba(var(--hud-acento-rgb),0.5)] backdrop-blur-sm"
            >
                <!-- Esquinas del marco -->
                <span
                    v-for="esquina in [
                        '-top-px -left-px border-t-2 border-l-2 rounded-tl-xl',
                        '-top-px -right-px border-t-2 border-r-2 rounded-tr-xl',
                        '-bottom-px -left-px border-b-2 border-l-2 rounded-bl-xl',
                        '-bottom-px -right-px border-b-2 border-r-2 rounded-br-xl',
                    ]"
                    :key="esquina"
                    class="pointer-events-none absolute size-5 border-primary"
                    :class="esquina"
                    aria-hidden="true"
                />
                <slot />
            </div>

            <p
                class="text-center font-mono text-[11px] tracking-[0.3em] text-muted-foreground/70 uppercase"
            >
                Acceso restringido
            </p>
        </div>
    </div>
</template>
