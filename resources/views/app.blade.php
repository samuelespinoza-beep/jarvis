<!DOCTYPE html>
{{-- Jarvis usa siempre su tema oscuro con acento naranja (resources/css/app.css, .tema-jarvis.tema-nucleo). --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="tema-jarvis tema-nucleo">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Mismo fondo que el tema, para que no parpadee en blanco al cargar --}}
        <style>
            html {
                background-color: #06080b;
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
