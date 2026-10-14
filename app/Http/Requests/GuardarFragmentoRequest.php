<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class GuardarFragmentoRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Chrome y Edge reportan el audio WebM unas veces como audio/webm y otras como video/webm.
            'audio' => [
                'required',
                'file',
                'mimetypes:audio/webm,video/webm,audio/ogg,audio/mp4,video/mp4',
                'max:'.config('jarvis.fragmento_max_kb'),
            ],
            'orden' => ['required', 'integer', 'min:1'],
            'duracion_segundos' => ['required', 'integer', 'min:0', 'max:3600'],
        ];
    }
}
