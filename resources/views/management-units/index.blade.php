<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Verwaltungseinheiten') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[1229px] mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <ul class="divide-y">
                    @forelse ($managementUnits as $managementUnit)
                        <li class="px-6 py-4">
                            <a href="{{ route('management-units.show', $managementUnit) }}" class="font-medium hover:underline">
                                {{ $managementUnit->street }}, {{ $managementUnit->postal_code }} {{ $managementUnit->city }}
                            </a>
                        </li>
                    @empty
                        <li class="px-6 py-4 text-sm text-gray-500">{{ __('Keine Verwaltungseinheiten vorhanden.') }}</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>
