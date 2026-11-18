<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-gray-100 text-gray-900 antialiased">
        <div class="min-h-screen flex flex-col items-center justify-center px-6">
            <div class="w-full max-w-md text-center">
                <h1 class="text-3xl font-semibold mb-2">{{ config('app.name') }}</h1>
                <p class="text-gray-600 mb-8">{{ __('Verwaltung von Immobilien, Mietverträgen und Betriebskostenabrechnungen.') }}</p>

                <div class="flex items-center justify-center gap-4 mb-8">
                    @auth
                        <a href="{{ route('dashboard') }}" class="px-5 py-2 bg-gray-900 text-white rounded-md text-sm font-medium hover:bg-gray-700">
                            {{ __('Dashboard') }}
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="px-5 py-2 bg-gray-900 text-white rounded-md text-sm font-medium hover:bg-gray-700">
                            {{ __('Anmelden') }}
                        </a>
                        <a href="{{ route('register') }}" class="px-5 py-2 border border-gray-300 rounded-md text-sm font-medium hover:bg-gray-50">
                            {{ __('Registrieren') }}
                        </a>
                    @endauth
                </div>

                <div class="flex items-center justify-center gap-3 text-sm text-gray-500">
                    <a href="{{ route('locale.switch', 'de') }}" class="hover:underline {{ app()->getLocale() === 'de' ? 'font-semibold text-gray-900' : '' }}">Deutsch</a>
                    <span>&middot;</span>
                    <a href="{{ route('locale.switch', 'en') }}" class="hover:underline {{ app()->getLocale() === 'en' ? 'font-semibold text-gray-900' : '' }}">English</a>
                </div>
            </div>
        </div>
    </body>
</html>
