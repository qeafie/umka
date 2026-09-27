<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Умка — удобный мобильный коммунальный ассистент. Домашние вопросы — проще.">
        <link rel="icon" type="image/svg+xml" href="{{ asset('brand/umka-mark.svg') }}">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <script src="https://st.max.ru/js/max-web-app.js"></script>
        @vite('resources/js/app.js')
        <x-inertia::head>
            <title>{{ config('app.name') }}</title>
        </x-inertia::head>
    </head>
    <body>
        <x-inertia::app />
    </body>
</html>
