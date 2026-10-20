<script setup lang="ts">
import type { ContenidoResumen, PuntoAgendaActa, PuntoResumen } from '@/types';

defineProps<{
    acta: ContenidoResumen;
}>();

const ESTADOS_AGENDA: Record<
    PuntoAgendaActa['estado'],
    { texto: string; clase: string }
> = {
    tratado: {
        texto: 'Tratado',
        clase: 'border-emerald-300 bg-emerald-50 text-emerald-700',
    },
    parcial: {
        texto: 'Parcial',
        clase: 'border-amber-300 bg-amber-50 text-amber-700',
    },
    no_tratado: {
        texto: 'No tratado',
        clase: 'border-rose-300 bg-rose-50 text-rose-700',
    },
};

function minuto(punto: PuntoResumen): string | null {
    return punto.minuto_aprox === null ? null : `min ${punto.minuto_aprox}`;
}
</script>

<template>
    <article class="flex flex-col gap-8 text-sm leading-relaxed">
        <section class="flex flex-col gap-2">
            <h2 class="text-base font-semibold">Resumen ejecutivo</h2>
            <p class="text-foreground/90">{{ acta.resumen_ejecutivo }}</p>
            <ul
                v-if="acta.temas_tratados.length"
                class="mt-1 flex flex-wrap gap-2"
                aria-label="Temas tratados"
            >
                <li
                    v-for="tema in acta.temas_tratados"
                    :key="tema"
                    class="rounded-full border px-3 py-0.5 text-xs text-muted-foreground"
                >
                    {{ tema }}
                </li>
            </ul>
        </section>

        <section v-if="acta.agenda?.length" class="flex flex-col gap-3">
            <h2 class="text-base font-semibold">Agenda</h2>
            <ol class="flex flex-col gap-2">
                <li
                    v-for="(item, indice) in acta.agenda"
                    :key="indice"
                    class="flex gap-3 rounded-lg border px-3 py-2"
                >
                    <span
                        class="mt-0.5 font-mono text-xs text-muted-foreground"
                    >
                        {{ indice + 1 }}.
                    </span>
                    <div class="min-w-0 flex-1">
                        <p
                            class="flex flex-wrap items-center gap-2 font-medium"
                        >
                            {{ item.punto }}
                            <span
                                class="rounded-full border px-2 py-0.5 text-[11px] font-normal"
                                :class="ESTADOS_AGENDA[item.estado].clase"
                            >
                                {{ ESTADOS_AGENDA[item.estado].texto }}
                            </span>
                        </p>
                        <p class="text-foreground/80">{{ item.comentario }}</p>
                    </div>
                </li>
            </ol>
        </section>

        <section
            v-for="seccion in [
                {
                    titulo: 'Puntos principales',
                    puntos: acta.puntos_principales,
                },
                {
                    titulo: 'Puntos secundarios',
                    puntos: acta.puntos_secundarios,
                },
            ]"
            :key="seccion.titulo"
            class="flex flex-col gap-3"
        >
            <h2 class="text-base font-semibold">{{ seccion.titulo }}</h2>
            <p v-if="!seccion.puntos.length" class="text-muted-foreground">
                Ninguno.
            </p>
            <ol v-else class="flex flex-col gap-3">
                <li
                    v-for="(punto, indice) in seccion.puntos"
                    :key="indice"
                    class="border-l-2 border-hud-acento/60 pl-3"
                >
                    <p class="font-medium">
                        {{ punto.tema }}
                        <span
                            v-if="minuto(punto)"
                            class="ml-1 font-mono text-xs font-normal text-muted-foreground"
                        >
                            {{ minuto(punto) }}
                        </span>
                    </p>
                    <p class="text-foreground/80">{{ punto.detalle }}</p>
                </li>
            </ol>
        </section>

        <section class="flex flex-col gap-3">
            <h2 class="text-base font-semibold">Acuerdos</h2>
            <p v-if="!acta.acuerdos.length" class="text-muted-foreground">
                No se registraron acuerdos.
            </p>
            <div v-else class="overflow-x-auto rounded-lg border">
                <table class="w-full text-left">
                    <thead class="bg-muted/50 text-xs text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 font-medium">Acuerdo</th>
                            <th class="px-3 py-2 font-medium">Responsable</th>
                            <th class="px-3 py-2 font-medium">Fecha límite</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(acuerdo, indice) in acta.acuerdos"
                            :key="indice"
                            class="border-t"
                        >
                            <td class="px-3 py-2">{{ acuerdo.descripcion }}</td>
                            <td class="px-3 py-2 text-muted-foreground">
                                {{ acuerdo.responsable ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-muted-foreground">
                                {{ acuerdo.fecha_limite ?? '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section
            v-for="lista in [
                { titulo: 'Pendientes', items: acta.pendientes },
                {
                    titulo: 'Riesgos o puntos críticos',
                    items: acta.riesgos_o_puntos_criticos,
                },
                {
                    titulo: 'Sugerencias de mejora',
                    items: acta.sugerencias_de_mejora,
                },
            ]"
            :key="lista.titulo"
            class="flex flex-col gap-2"
        >
            <h2 class="text-base font-semibold">{{ lista.titulo }}</h2>
            <p v-if="!lista.items.length" class="text-muted-foreground">
                Ninguno.
            </p>
            <ul v-else class="list-disc space-y-1 pl-5 text-foreground/90">
                <li v-for="(item, indice) in lista.items" :key="indice">
                    {{ item }}
                </li>
            </ul>
        </section>
    </article>
</template>
