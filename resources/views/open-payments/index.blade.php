<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Offene Zahlungen') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[1229px] mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold mb-4">{{ __('Offene Ausgaben') }}</h3>
                <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="text-gray-600">
                        <tr>
                            <th class="py-2">{{ __('Periode') }}</th>
                            <th class="py-2">{{ __('Rechnungssteller') }}</th>
                            <th class="py-2">{{ __('Zuordnung') }}</th>
                            <th class="py-2">{{ __('Kategorie') }}</th>
                            <th class="py-2">{{ __('Betrag') }}</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse ($openCosts as $cost)
                            <tr>
                                <td class="py-2 whitespace-nowrap">{{ $cost->period_date->format('d.m.Y') }}</td>
                                <td class="py-2">{{ $cost->invoice?->invoiceIssuer?->name }}</td>
                                <td class="py-2 text-gray-600">{{ class_basename($cost->costable) }} #{{ $cost->costable_id }}</td>
                                <td class="py-2">{{ $cost->costCategory->name }}</td>
                                <td class="py-2 whitespace-nowrap">{{ euro($cost->amount) }} €</td>
                                <td class="py-2">
                                    @if ($cost->invoice)
                                        <a href="{{ route('invoices.show', $cost->invoice) }}" class="text-gray-600 hover:underline">{{ __('Zur Rechnung') }}</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-2 text-gray-500">{{ __('Keine offenen Ausgaben.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold mb-4">{{ __('Offene Mieteinnahmen') }}</h3>
                <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="text-gray-600">
                        <tr>
                            <th class="py-2">{{ __('Monat') }}</th>
                            <th class="py-2">{{ __('Mieter') }}</th>
                            <th class="py-2">{{ __('Mieteinheit') }}</th>
                            <th class="py-2">{{ __('Betrag') }}</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse ($openRentPayments as $payment)
                            @php($lease = $payment->leaseInterval->lease)
                            <tr>
                                <td class="py-2 whitespace-nowrap">{{ $payment->period_date->locale('de')->translatedFormat('F Y') }}</td>
                                <td class="py-2">{{ $lease->tenantNames() }}</td>
                                <td class="py-2 text-gray-600">{{ $lease->rentalUnit->managementUnit->street }} &mdash; {{ $lease->rentalUnit->label() }}</td>
                                <td class="py-2 whitespace-nowrap">{{ euro($payment->amount) }} €</td>
                                <td class="py-2">
                                    <a href="{{ route('leases.show', $lease) }}#mietzahlungen" class="text-gray-600 hover:underline">{{ __('Zum Mietvertrag') }}</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-2 text-gray-500">{{ __('Keine offenen Mieteinnahmen.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
