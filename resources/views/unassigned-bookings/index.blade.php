<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Nicht zugeordnete Buchungen') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[1229px] mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <p class="text-sm text-gray-600 mb-4">
                    {{ __('Termine bis heute, bei denen die Summe der Kostenzeilen nicht dem Rechnungsbetrag entspricht. Rechnungen mit deaktiviertem Betragsdeckel fehlen hier.') }}
                </p>
                <div class="overflow-x-auto">
                <table class="w-full text-sm text-left" data-unassigned-bookings>
                    <thead class="text-gray-600">
                        <tr>
                            <th class="py-2">{{ __('Termin') }}</th>
                            <th class="py-2">{{ __('Rechnungssteller') }}</th>
                            <th class="py-2">{{ __('Beschreibung') }}</th>
                            <th class="py-2">{{ __('Zuordnung') }}</th>
                            <th class="py-2 text-right">{{ __('Rechnungsbetrag') }}</th>
                            <th class="py-2 text-right">{{ __('Zugeordnet') }}</th>
                            <th class="py-2 text-right">{{ __('Differenz') }}</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse ($rows as $row)
                            <tr>
                                <td class="py-2 whitespace-nowrap">{{ \Carbon\Carbon::parse($row['date'])->format('d.m.Y') }}</td>
                                <td class="py-2">{{ $row['invoice']->invoiceIssuer?->name }}</td>
                                <td class="py-2">{{ $row['invoice']->description }}</td>
                                <td class="py-2 text-gray-600">{{ $row['invoice']->costableLabel() }}</td>
                                <td class="py-2 whitespace-nowrap text-right">{{ euro($row['expected']) }} €</td>
                                <td class="py-2 whitespace-nowrap text-right">{{ euro($row['assigned']) }} €</td>
                                <td class="py-2 whitespace-nowrap text-right {{ $row['difference'] < 0 ? 'text-red-600' : 'text-amber-700' }}">{{ euro($row['difference']) }} €</td>
                                <td class="py-2 pl-4 whitespace-nowrap">
                                    <a href="{{ route('invoices.show', $row['invoice']) }}" class="text-gray-600 hover:underline">{{ __('Zur Rechnung') }}</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="py-2 text-gray-500">{{ __('Alle Buchungen sind vollständig zugeordnet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
