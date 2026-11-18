<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ $rentalUnit->label() }} &mdash; {{ $rentalUnit->managementUnit->street }}
            </h2>
            <a href="{{ route('rental-units.edit', $rentalUnit) }}" class="text-sm text-gray-600 hover:underline">{{ __('Bearbeiten') }}</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[1229px] mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <dl class="grid grid-cols-4 gap-4 text-sm">
                    <div>
                        <dt class="text-gray-500">{{ __('Größe') }}</dt>
                        <dd class="text-gray-900">{{ $rentalUnit->size ? number_format($rentalUnit->size, 0).' m²' : '–' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('Beschreibung') }}</dt>
                        <dd class="text-gray-900">{{ $rentalUnit->description ?: '–' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('Etage') }}</dt>
                        <dd class="text-gray-900">{{ $rentalUnit->floor ?: '–' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('Abrechnungsrelevant') }}</dt>
                        <dd class="text-gray-900">{{ $rentalUnit->is_relevant_for_billing ? __('Ja') : __('Nein') }}</dd>
                    </div>
                </dl>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-semibold">{{ __('Mietverträge') }}</h3>
                    <a href="{{ route('leases.create', ['rental_unit_id' => $rentalUnit->id]) }}" class="text-sm text-gray-600 hover:underline">
                        {{ __('Neuer Mietvertrag') }}
                    </a>
                </div>
                <div class="border border-gray-200 rounded-lg overflow-hidden">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                            <tr>
                                <th class="px-4 py-3">{{ __('Mieter') }}</th>
                                <th class="px-4 py-3">{{ __('Start') }}</th>
                                <th class="px-4 py-3">{{ __('Ende') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @forelse ($rentalUnit->leases as $lease)
                                <tr class="hover:bg-gray-50 {{ $lease->isExpired() ? 'text-gray-400' : '' }}">
                                    <td class="px-4 py-3">
                                        <a href="{{ route('leases.show', $lease) }}" class="hover:underline">{{ $lease->tenantNames() }}</a>
                                    </td>
                                    <td class="px-4 py-3">{{ $lease->start_date->format('d.m.Y') }}</td>
                                    <td class="px-4 py-3">{{ optional($lease->end_date)->format('d.m.Y') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-4 py-3 text-gray-500">{{ __('Keine Mietverträge vorhanden.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
