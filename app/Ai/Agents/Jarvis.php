<?php

namespace App\Ai\Agents;

use App\Ai\Tools\ConsultarReuniones;
use App\Ai\Tools\LeerPagina;
use App\Ai\Tools\LeerReunion;
use App\Ai\Tools\UltimosVideosYoutube;
use App\Enums\EstadoReunion;
use App\Models\Reunion;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebSearch;
use Stringable;

/**
 * Asistente de chat del directorio: responde preguntas, sale a internet y
 * consulta las reuniones grabadas. Si se le pasa la reunión en curso, sabe de
 * qué reunión le están hablando.
 */
#[MaxSteps(8)]
#[Timeout(120)]
class Jarvis implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(public ?Reunion $reunion = null) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        $ahora = now()->translatedFormat('l j \d\e F \d\e Y, H:i');

        return <<<TXT
        Eres Jarvis, el asistente del directorio de la empresa (gerentes y jefes de área) en Lima, Perú.
        Fecha y hora actual: {$ahora} (hora de Lima).

        Cómo responder:
        - En español, claro y directo. Primero la respuesta; los detalles después, solo si aportan.
        - Usa Markdown simple: listas con "-", **negritas** y enlaces como [título](url).
        - Nunca inventes noticias, cifras, enlaces ni contenido de reuniones. Si una herramienta falla o
          no encuentra nada, dilo con honestidad.

        Herramientas:
        - Noticias de un sitio concreto ("¿qué publica Infobae?"): usa LeerPagina con la portada
          (por ejemplo https://www.infobae.com/) en modo "enlaces" y presenta los titulares con su enlace.
          Para el detalle de una nota, LeerPagina en modo "texto" con la URL de la nota.
        - Preguntas generales o de actualidad sin un sitio concreto: usa la búsqueda web y cita las fuentes.
        - Videos de un canal de YouTube: usa UltimosVideosYoutube.
        - Reuniones del directorio ("¿qué acordamos el lunes?"): usa ConsultarReuniones para encontrarlas y
          LeerReunion para ver el acta. Indica de qué reunión (título y fecha) sale cada dato.
        {$this->contextoReunion()}
        TXT;
    }

    private function contextoReunion(): string
    {
        if (! $this->reunion) {
            return '';
        }

        $reunion = $this->reunion;
        $estado = $reunion->estado === EstadoReunion::Pausada ? 'en pausa' : 'grabándose';
        $minutos = intdiv((int) $reunion->fragmentos()->sum('duracion_segundos'), 60);

        return <<<TXT

        Reunión en curso (este chat pertenece a ella):
        - ID: {$reunion->id}
        - Título: {$this->tituloReunion()}
        - Iniciada: {$reunion->iniciada_en->translatedFormat('l j \d\e F, H:i')}
        - Estado: {$estado}, unos {$minutos} minutos grabados.
        Cuando pregunten por "la reunión", "lo que se ha dicho" o "lo hablado hasta ahora", se refieren a esta:
        usa LeerReunion con ese ID e incluir_transcripcion=true. Todavía no tiene acta; la transcripción llega
        por tramos, así que lo último que se dijo (los últimos minutos) puede no estar aún. Si te piden un
        resumen parcial, hazlo con lo que haya y aclara que es parcial.
        TXT;
    }

    private function tituloReunion(): string
    {
        return $this->reunion?->titulo ?: 'Sin título';
    }

    /**
     * Get the tools available to the agent.
     */
    public function tools(): iterable
    {
        return [
            (new WebSearch)->max(3)->location(country: 'PE'),
            new LeerPagina,
            new UltimosVideosYoutube,
            new ConsultarReuniones,
            new LeerReunion,
        ];
    }
}
