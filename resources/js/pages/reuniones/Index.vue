<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import {
    index as historial,
    show as verReunion,
} from '@/actions/App/Http/Controllers/HistorialController';
import EstadoReunionBadge from '@/components/jarvis/EstadoReunionBadge.vue';
import { Button } from '@/components/ui/button';
import { formatearFecha, formatearHora, formatearTiempo } from '@/lib/formato';
import type { Reunion } from '@/types';

type FilaReunion = Reunion & { resumen_ejecutivo: string | null };

defineProps<{
    reuniones: {
        data: FilaReunion[];
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Reuniones', href: historial() }],
    },
});
</script>

<template>
    <Head title="Reuniones" />

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
        <header>
            <h1 class="text-xl font-semibold">Reuniones</h1>
            <p class="text-sm text-muted-foreground">
                Historial de reuniones grabadas con el Asistente IA.
            </p>
        </header>

        <p
            v-if="!reuniones.data.length"
            class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
        >
            Todavía no hay reuniones. Graba la primera desde el Asistente IA.
        </p>

        <ul v-else class="flex flex-col gap-3">
            <li v-for="reunion in reuniones.data" :key="reunion.id">
                <Link
                    :href="verReunion(reunion.id)"
                    class="flex flex-col gap-2 rounded-lg border p-4 transition-colors hover:bg-muted/50"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate font-medium">
                                {{ reunion.titulo || 'Reunión sin título' }}
                            </p>
                            <p
                                class="text-xs text-muted-foreground first-letter:uppercase"
                            >
                                {{ formatearFecha(reunion.iniciada_en) }} ·
                                {{ formatearHora(reunion.iniciada_en) }}
                                <template v-if="reunion.duracion_segundos">
                                    ·
                                    {{
                                        formatearTiempo(
                                            reunion.duracion_segundos,
                                        )
                                    }}
                                </template>
                            </p>
                        </div>
                        <EstadoReunionBadge :reunion="reunion" />
                    </div>
                    <p
                        v-if="reunion.resumen_ejecutivo"
                        class="line-clamp-2 text-sm text-foreground/80"
                    >
                        {{ reunion.resumen_ejecutivo }}
                    </p>
                </Link>
            </li>
        </ul>

        <nav
            v-if="reuniones.last_page > 1"
            class="flex items-center justify-between text-sm"
            aria-label="Paginación"
        >
            <Button
                v-if="reuniones.prev_page_url"
                variant="outline"
                size="sm"
                as-child
            >
                <Link :href="reuniones.prev_page_url">
                    <ChevronLeft /> Anteriores
                </Link>
            </Button>
            <span v-else />
            <span class="text-muted-foreground">
                Página {{ reuniones.current_page }} de
                {{ reuniones.last_page }}
            </span>
            <Button
                v-if="reuniones.next_page_url"
                variant="outline"
                size="sm"
                as-child
            >
                <Link :href="reuniones.next_page_url">
                    Siguientes <ChevronRight />
                </Link>
            </Button>
            <span v-else />
        </nav>
    </div>
</template>
