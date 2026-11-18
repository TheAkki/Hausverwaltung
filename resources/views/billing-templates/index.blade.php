<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Betriebskostenabrechnung-Templates') }}</h2>
            <div class="flex items-center gap-4">
                <a href="{{ route('billing-templates.help') }}" class="text-sm text-gray-600 hover:underline">
                    {{ __('Hilfe: Templates schreiben') }}
                </a>
                <a href="{{ route('billing-templates.create') }}" class="px-4 py-2 bg-gray-900 text-white rounded-md text-sm font-medium hover:bg-gray-700">
                    {{ __('Neues Template') }}
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[922px] mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <ul class="divide-y">
                    @forelse ($billingTemplates as $template)
                        <li class="px-6 py-4 flex justify-between items-center">
                            <a href="{{ route('billing-templates.show', $template) }}" class="hover:underline">{{ $template->name }}</a>
                            <span class="flex items-center gap-2">
                                <span class="text-xs text-gray-500">{{ $template->userGroup?->name ?? __('Intern') }}</span>
                                @if ($template->is_default)
                                    <span class="text-xs text-gray-500">{{ __('Standard') }}</span>
                                @endif
                            </span>
                        </li>
                    @empty
                        <li class="px-6 py-4 text-sm text-gray-500">{{ __('Keine Templates vorhanden.') }}</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>
