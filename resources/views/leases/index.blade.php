<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Mietverträge') }}</h2>
            <a href="{{ route('leases.create') }}" class="px-4 py-2 bg-gray-900 text-white rounded-md text-sm font-medium hover:bg-gray-700">
                {{ __('Neuer Mietvertrag') }}
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[1229px] mx-auto sm:px-6 lg:px-8">
            <div class="flex justify-end mb-3">
                <a href="{{ route('leases.index', $showExpired ? [] : ['show_expired' => 1]) }}" class="text-sm text-gray-600 hover:underline">
                    {{ $showExpired ? __('Abgelaufene Mietverträge ausblenden') : __('Abgelaufene Mietverträge einblenden') }}
                </a>
            </div>
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <table class="w-full text-sm text-left">
                    <thead class="bg-gray-50 text-gray-600">
                        <tr>
                            <th class="px-6 py-3">{{ __('Mieter') }}</th>
                            <th class="px-6 py-3">{{ __('Mieteinheit') }}</th>
                            <th class="px-6 py-3">{{ __('Start') }}</th>
                            <th class="px-6 py-3">{{ __('Ende') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @foreach ($leases as $lease)
                            <tr class="{{ $lease->isExpired() ? 'text-gray-400' : '' }}">
                                <td class="px-6 py-3">{{ $lease->tenantNames() }}</td>
                                <td class="px-6 py-3">
                                    <a href="{{ route('leases.show', $lease) }}" class="hover:underline">
                                        {{ $lease->rentalUnit->managementUnit->street }} &mdash; {{ $lease->rentalUnit->label() }}
                                    </a>
                                </td>
                                <td class="px-6 py-3">{{ $lease->start_date->format('d.m.Y') }}</td>
                                <td class="px-6 py-3">
                                    {{ optional($lease->end_date)->format('d.m.Y') }}
                                    @if ($lease->is_pre_contract)
                                        <div class="mt-1 flex items-center gap-1">
                                            <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">{{ __('Vorvertrag') }}</span>
                                            @if ($successor = $lease->successorLease())
                                                <a href="{{ route('leases.show', $successor) }}" class="text-xs text-indigo-600 hover:underline">{{ __('→ Nachfolgevertrag') }}</a>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
