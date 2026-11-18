<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Mieteinheiten') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[1229px] mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <ul class="divide-y">
                    @forelse ($rentalUnits as $rentalUnit)
                        <li class="px-6 py-4">
                            <a href="{{ route('rental-units.show', $rentalUnit) }}" class="font-medium hover:underline">
                                {{ $rentalUnit->label() }} &mdash; {{ $rentalUnit->managementUnit->street }}
                            </a>
                        </li>
                    @empty
                        <li class="px-6 py-4 text-sm text-gray-500">{{ __('Keine Mieteinheiten vorhanden.') }}</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>
