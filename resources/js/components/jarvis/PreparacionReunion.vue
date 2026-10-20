<script setup lang="ts">
import { http } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    CircleAlert,
    ClipboardList,
    LoaderCircle,
    Plus,
    RotateCw,
    Sparkles,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import InformePrevioController from '@/actions/App/Http/Controllers/InformePrevioController';
import AgendaDesdeArchivos from '@/components/jarvis/AgendaDesdeArchivos.vue';
import { Button } from '@/components/ui/button';
import { formatearHora } from '@/lib/formato';
import type { ConfigDocumentos, InformePrevio } from '@/types';

const MAX_PUNTOS = 20;

defineProps<{
    // Con la reunión en curso la agenda se guarda al cambiarla.
    enCurso: boolean;
    documentos: ConfigDocumentos;
}>();

const agenda = defineModel<string[]>('agenda', { required: true });

const nuevoPunto = ref('');
const generandoDesdeArchivos = ref(false);
const informe = ref<InformePrevio | null>(null);
const preparando = ref(false);
const error = ref<string | null>(null);

const sugerenciasPendientes = computed(
    () =>
        informe.value?.informe?.sugerencias_agenda.filter(
            (sugerencia) => !agenda.value.includes(sugerencia),
        ) ?? [],
);

const vencidos = computed(
    () =>
        informe.value?.informe?.compromisos.filter((c) => c.vencido).length ??
        0,
);

function agregar(punto = nuevoPunto.value) {
    const texto = punto.trim().slice(0, 200);

    if (
        !texto ||
        agenda.value.includes(texto) ||
        agenda.value.length >= MAX_PUNTOS
    ) {
        return;
    }

    agenda.value = [...agenda.value, texto];

    if (punto === nuevoPunto.value) {
        nuevoPunto.value = '';
    }
}

// Lo que propone la IA reemplaza la agenda o se suma sin repetir puntos.
function aplicarGenerada(puntos: string[], modo: 'reemplazar' | 'agregar') {
    const base = modo === 'agregar' ? agenda.value : [];
    agenda.value = [
        ...base,
        ...puntos.filter((punto) => !base.includes(punto)),
    ].slice(0, MAX_PUNTOS);
    generandoDesdeArchivos.value = false;
}

function quitar(indice: number) {
    agenda.value = agenda.value.filter((_, i) => i !== indice);
}

function mover(indice: number, paso: -1 | 1) {
    const destino = indice + paso;

    if (destino < 0 || destino >= agenda.value.length) {
        return;
    }

    const lista = [...agenda.value];
    [lista[indice], lista[destino]] = [lista[destino], lista[indice]];
    agenda.value = lista;
}

function alPresionarTecla(evento: KeyboardEvent) {
    if (evento.key === 'Enter') {
        evento.preventDefault();
        agregar();
    }
}

async function preparar() {
    preparando.value = true;
    error.value = null;

    try {
        const respuesta = await http.getClient().request({
            method: 'post',
            url: InformePrevioController.url(),
            data: { agenda: agenda.value },
            headers: { Accept: 'application/json' },
        });
        informe.value = JSON.parse(respuesta.data) as InformePrevio;
    } catch {
        error.value =
            'No se pudo preparar el informe previo. Intenta de nuevo.';
    } finally {
        preparando.value = false;
    }
}
</script>

<template>
    <section class="flex flex-col gap-5">
        <div class="flex flex-col gap-2">
            <div class="flex items-center justify-between">
                <h3
                    class="flex items-center gap-2 text-sm font-semibold text-hud-texto"
                >
                    <ClipboardList class="size-4 text-hud-acento-texto" />
                    Agenda
                </h3>
                <span class="text-xs text-hud-suave">
                    {{ agenda.length }}/{{ MAX_PUNTOS }}
                </span>
            </div>

            <p v-if="!agenda.length" class="text-xs text-hud-suave">
                {{
                    enCurso
                        ? 'Agrega los puntos a tratar: el acta dirá cuáles se trataron.'
                        : 'Prepara los puntos antes de empezar. El acta dirá cuáles se trataron y cuáles quedaron pendientes.'
                }}
            </p>

            <ol v-else class="flex flex-col gap-1.5">
                <li
                    v-for="(punto, indice) in agenda"
                    :key="punto"
                    class="group flex items-center gap-2 rounded-lg border border-hud-acento/20 bg-hud-superficie/30 px-2.5 py-1.5 text-sm"
                >
                    <span
                        class="w-5 shrink-0 font-mono text-xs text-hud-acento-texto"
                    >
                        {{ indice + 1 }}.
                    </span>
                    <span class="min-w-0 flex-1 text-hud-texto">{{
                        punto
                    }}</span>
                    <span
                        class="flex shrink-0 items-center opacity-60 transition-opacity group-hover:opacity-100"
                    >
                        <button
                            type="button"
                            class="rounded p-0.5 text-hud-suave hover:bg-hud-superficie hover:text-hud-texto disabled:opacity-30"
                            aria-label="Subir punto"
                            :disabled="indice === 0"
                            @click="mover(indice, -1)"
                        >
                            <ArrowUp class="size-3.5" />
                        </button>
                        <button
                            type="button"
                            class="rounded p-0.5 text-hud-suave hover:bg-hud-superficie hover:text-hud-texto disabled:opacity-30"
                            aria-label="Bajar punto"
                            :disabled="indice === agenda.length - 1"
                            @click="mover(indice, 1)"
                        >
                            <ArrowDown class="size-3.5" />
                        </button>
                        <button
                            type="button"
                            class="rounded p-0.5 text-hud-suave hover:bg-rose-50 hover:text-rose-600"
                            aria-label="Quitar punto"
                            @click="quitar(indice)"
                        >
                            <X class="size-3.5" />
                        </button>
                    </span>
                </li>
            </ol>

            <div
                v-if="agenda.length < MAX_PUNTOS"
                class="flex items-center gap-2 rounded-lg border border-dashed border-hud-acento/30 px-2.5 py-1 focus-within:border-hud-acento"
            >
                <Plus class="size-4 shrink-0 text-hud-suave" />
                <input
                    v-model="nuevoPunto"
                    type="text"
                    maxlength="200"
                    placeholder="Agregar un punto y presionar Enter"
                    aria-label="Nuevo punto de la agenda"
                    class="min-w-0 flex-1 bg-transparent py-1 text-sm text-hud-texto placeholder:text-hud-suave/70 focus:outline-none"
                    @keydown="alPresionarTecla"
                />
            </div>
            <button
                v-if="!generandoDesdeArchivos"
                type="button"
                class="flex items-center gap-1.5 self-start text-xs text-hud-acento-texto hover:underline"
                @click="generandoDesdeArchivos = true"
            >
                <Sparkles class="size-3.5" />
                Generar desde capturas, PDF o texto
            </button>
        </div>

        <AgendaDesdeArchivos
            v-if="generandoDesdeArchivos"
            :documentos="documentos"
            :puntos-actuales="agenda.length"
            @aplicar="aplicarGenerada"
            @cerrar="generandoDesdeArchivos = false"
        />

        <div
            class="flex flex-col gap-3 rounded-xl border border-hud-acento/25 bg-hud-superficie/20 p-4"
        >
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3
                        class="flex items-center gap-2 text-sm font-semibold text-hud-texto"
                    >
                        <Sparkles class="size-4 text-hud-acento-texto" />
                        Informe previo
                    </h3>
                    <p class="text-xs text-hud-suave">
                        <template v-if="informe">
                            Generado a las
                            {{ formatearHora(informe.generado_en) }} ·
                            {{ informe.reuniones.length }} reunión(es)
                            revisada(s)
                        </template>
                        <template v-else>
                            Compromisos pendientes, temas por retomar y alertas
                            de las últimas reuniones.
                        </template>
                    </p>
                </div>
                <Button
                    size="sm"
                    :variant="informe ? 'outline' : 'default'"
                    :disabled="preparando"
                    :class="
                        informe
                            ? 'shrink-0 border-hud-acento/40 bg-transparent text-hud-texto hover:bg-hud-superficie'
                            : 'shrink-0 bg-hud-acento text-hud-sobre-acento hover:bg-hud-acento-fuerte'
                    "
                    @click="preparar"
                >
                    <LoaderCircle v-if="preparando" class="animate-spin" />
                    <RotateCw v-else-if="informe" />
                    <Sparkles v-else />
                    {{
                        preparando
                            ? 'Preparando…'
                            : informe
                              ? 'Actualizar'
                              : 'Preparar'
                    }}
                </Button>
            </div>

            <p
                v-if="error"
                class="flex items-center gap-2 text-xs text-rose-600"
                role="alert"
            >
                <CircleAlert class="size-3.5 shrink-0" />
                {{ error }}
            </p>

            <p
                v-if="informe && !informe.informe"
                class="text-sm text-hud-suave"
            >
                Todavía no hay reuniones con acta para preparar un informe.
            </p>

            <div
                v-else-if="informe?.informe"
                class="flex flex-col gap-4 text-sm"
            >
                <p class="leading-relaxed text-hud-texto/90">
                    {{ informe.informe.resumen }}
                </p>

                <div
                    v-if="informe.informe.alertas.length"
                    class="flex flex-col gap-1.5"
                >
                    <h4
                        class="text-xs font-semibold tracking-wider text-amber-700 uppercase"
                    >
                        Alertas
                    </h4>
                    <ul
                        class="flex flex-col gap-1 rounded-lg border border-amber-300 bg-amber-50 p-3 text-amber-800"
                    >
                        <li
                            v-for="(alerta, indice) in informe.informe.alertas"
                            :key="indice"
                            class="flex gap-2"
                        >
                            <CircleAlert class="mt-0.5 size-3.5 shrink-0" />
                            {{ alerta }}
                        </li>
                    </ul>
                </div>

                <div
                    v-if="informe.informe.compromisos.length"
                    class="flex flex-col gap-1.5"
                >
                    <h4
                        class="text-xs font-semibold tracking-wider text-hud-acento-texto uppercase"
                    >
                        Compromisos
                        <span
                            v-if="vencidos"
                            class="ml-1 rounded-full bg-rose-100 px-1.5 py-0.5 text-[10px] text-rose-700 normal-case"
                        >
                            {{ vencidos }} vencido(s)
                        </span>
                    </h4>
                    <ul class="flex flex-col gap-1.5">
                        <li
                            v-for="(compromiso, indice) in informe.informe
                                .compromisos"
                            :key="indice"
                            class="rounded-lg border px-3 py-2"
                            :class="
                                compromiso.vencido
                                    ? 'border-rose-300 bg-rose-50/60'
                                    : 'border-hud-acento/20 bg-hud-fondo/50'
                            "
                        >
                            <p class="text-hud-texto">
                                {{ compromiso.descripcion }}
                            </p>
                            <p
                                class="mt-0.5 flex flex-wrap gap-x-3 text-xs text-hud-suave"
                            >
                                <span>
                                    {{
                                        compromiso.responsable ??
                                        'Sin responsable'
                                    }}
                                </span>
                                <span
                                    :class="
                                        compromiso.vencido
                                            ? 'font-medium text-rose-600'
                                            : ''
                                    "
                                >
                                    {{
                                        compromiso.fecha_limite
                                            ? `${compromiso.vencido ? 'Venció' : 'Vence'}: ${compromiso.fecha_limite}`
                                            : 'Sin fecha'
                                    }}
                                </span>
                                <span class="truncate">
                                    {{ compromiso.reunion }}
                                </span>
                            </p>
                        </li>
                    </ul>
                </div>

                <div
                    v-if="informe.informe.retomar.length"
                    class="flex flex-col gap-1.5"
                >
                    <h4
                        class="text-xs font-semibold tracking-wider text-hud-acento-texto uppercase"
                    >
                        Para retomar
                    </h4>
                    <ul class="flex flex-col gap-1.5">
                        <li
                            v-for="(tema, indice) in informe.informe.retomar"
                            :key="indice"
                            class="border-l-2 border-hud-acento/50 pl-3"
                        >
                            <p class="font-medium text-hud-texto">
                                {{ tema.tema }}
                            </p>
                            <p class="text-hud-texto/75">{{ tema.motivo }}</p>
                            <p class="text-xs text-hud-suave">
                                {{ tema.reunion }}
                            </p>
                        </li>
                    </ul>
                </div>

                <div
                    v-if="informe.informe.antecedentes_agenda.length"
                    class="flex flex-col gap-1.5"
                >
                    <h4
                        class="text-xs font-semibold tracking-wider text-hud-acento-texto uppercase"
                    >
                        Antecedentes de la agenda
                    </h4>
                    <dl class="flex flex-col gap-2">
                        <div
                            v-for="(item, indice) in informe.informe
                                .antecedentes_agenda"
                            :key="indice"
                        >
                            <dt class="font-medium text-hud-texto">
                                {{ item.punto }}
                            </dt>
                            <dd class="text-hud-texto/75">
                                {{ item.antecedentes }}
                            </dd>
                        </div>
                    </dl>
                </div>

                <div
                    v-if="sugerenciasPendientes.length"
                    class="flex flex-col gap-1.5"
                >
                    <h4
                        class="text-xs font-semibold tracking-wider text-hud-acento-texto uppercase"
                    >
                        Sugerencias para la agenda
                    </h4>
                    <ul class="flex flex-col gap-1.5">
                        <li
                            v-for="sugerencia in sugerenciasPendientes"
                            :key="sugerencia"
                            class="flex items-center gap-2"
                        >
                            <span class="min-w-0 flex-1 text-hud-texto/90">
                                {{ sugerencia }}
                            </span>
                            <button
                                type="button"
                                class="flex shrink-0 items-center gap-1 rounded-full border border-hud-acento/40 px-2.5 py-0.5 text-xs text-hud-acento-texto hover:bg-hud-acento/10 disabled:opacity-40"
                                :disabled="agenda.length >= MAX_PUNTOS"
                                @click="agregar(sugerencia)"
                            >
                                <Plus class="size-3" />
                                Agregar
                            </button>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </section>
</template>
