<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ __('API-Dokumentation') }} – {{ config('app.name', 'Laravel') }}</title>

        @vite(['resources/js/swagger.js'])
    </head>
    <body>
        <div id="swagger-ui" data-spec-url="{{ route('api-docs.spec') }}"></div>
    </body>
</html>
