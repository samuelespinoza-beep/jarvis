<script setup lang="ts">
import NucleoJarvis from '@/components/jarvis/NucleoJarvis.vue';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="dark tema-jarvis relative flex min-h-svh flex-col items-center justify-center overflow-hidden bg-background p-6 text-foreground md:p-10"
    >
        <!-- Rejilla y resplandor de fondo estilo HUD -->
        <div
            class="pointer-events-none absolute inset-0 bg-[linear-gradient(rgba(34,211,238,0.05)_1px,transparent_1px),linear-gradient(90deg,rgba(34,211,238,0.05)_1px,transparent_1px)] bg-[size:32px_32px]"
        />
        <div
            class="pointer-events-none absolute top-1/2 left-1/2 size-[60rem] -translate-x-1/2 -translate-y-1/2 rounded-full bg-[radial-gradient(circle,rgba(34,211,238,0.12),transparent_60%)]"
        />

        <div class="relative flex w-full max-w-sm flex-col gap-6">
            <div class="flex flex-col items-center gap-3 text-center">
                <div class="w-40">
                    <NucleoJarvis activo />
                </div>
                <p
                    class="font-mono text-xs tracking-[0.6em] text-primary"
                    aria-hidden="true"
                >
                    J.A.R.V.I.S.
                </p>
                <div class="space-y-1">
                    <h1 class="text-xl font-medium">{{ title }}</h1>
                    <p class="text-sm text-muted-foreground">
                        {{ description }}
                    </p>
                </div>
            </div>

            <div
                class="relative rounded-xl border border-primary/25 bg-card/70 p-6 shadow-[0_0_60px_-20px_rgba(34,211,238,0.5)] backdrop-blur-sm"
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
