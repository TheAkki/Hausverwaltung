<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ $managementUnit->street }}, {{ $managementUnit->postal_code }} {{ $managementUnit->city }}
            </h2>
            <a href="{{ route('management-units.edit', $managementUnit) }}" class="text-sm text-gray-600 hover:underline">{{ __('Bearbeiten') }}</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[1770px] mx-auto sm:px-6 lg:px-8 space-y-6">
            @if ($managementUnit->description)
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <p class="text-sm text-gray-700">{{ $managementUnit->description }}</p>
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-semibold">{{ __('Mieteinheiten') }}</h3>
                    <div class="space-x-3">
                        <a href="{{ route('leases.create', ['management_unit_id' => $managementUnit->id]) }}" class="text-sm text-gray-600 hover:underline">
                            {{ __('Neuer Mietvertrag') }}
                        </a>
                        <a href="{{ route('rental-units.create', ['management_unit_id' => $managementUnit->id]) }}" class="text-sm text-gray-600 hover:underline">
                            {{ __('Neue Mieteinheit') }}
                        </a>
                    </div>
                </div>
                <div class="border border-gray-200 rounded-lg overflow-hidden">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                            <tr>
                                <th class="px-4 py-3">{{ __('Typ') }}</th>
                                <th class="px-4 py-3">{{ __('Beschreibung') }}</th>
                                <th class="px-4 py-3">{{ __('Größe') }}</th>
                                <th class="px-4 py-3">{{ __('Abrechnungsrelevant') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @forelse ($managementUnit->rentalUnits as $rentalUnit)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3">
                                        <a href="{{ route('rental-units.show', $rentalUnit) }}" class="text-gray-900 hover:underline">{{ $rentalUnit->type }}</a>
                                    </td>
                                    <td class="px-4 py-3 text-gray-600">{{ $rentalUnit->description }}</td>
                                    <td class="px-4 py-3 text-gray-600">{{ $rentalUnit->size ? number_format($rentalUnit->size, 2, ',', '.').' m²' : '' }}</td>
                                    <td class="px-4 py-3 text-gray-600">{{ $rentalUnit->is_relevant_for_billing ? __('Ja') : __('Nein') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-4 py-3 text-gray-500">{{ __('Keine Mieteinheiten vorhanden.') }}</td></tr>
                            @endforelse
                        </tbody>
                        @if ($managementUnit->rentalUnits->isNotEmpty())
                            <tfoot class="font-semibold border-t border-gray-200 bg-gray-50">
                                <tr>
                                    <td class="px-4 py-3" colspan="2">{{ __('Gesamtgröße') }}</td>
                                    <td class="px-4 py-3">{{ number_format($managementUnit->rentalUnits->sum('size'), 2, ',', '.') }} m²</td>
                                    <td class="px-4 py-3"></td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>

                <details id="belegungsmatrix" class="mt-4" {{ $occupancyMatrixOpen ? 'open' : '' }}>
                    <summary class="cursor-pointer text-sm font-semibold text-gray-700">{{ __('Belegungsmatrix') }}</summary>
                    <div class="mt-3">
                        @include('financials._year-switcher', ['matrixSwitcher' => true, 'anchor' => 'belegungsmatrix'])
                        @include('financials._occupancy-table')
                    </div>
                </details>

                <details id="belegungsplan" class="mt-4" {{ $occupancyPlanOpen ? 'open' : '' }}>
                    <summary class="cursor-pointer text-sm font-semibold text-gray-700">{{ __('Belegungsplan') }}</summary>
                    <div class="mt-3">
                        @include('financials._year-switcher', ['planSwitcher' => true, 'anchor' => 'belegungsplan'])
                        @include('financials._occupancy-plan-table')
                    </div>
                </details>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                @include('financials._year-switcher')

                <h3 class="font-semibold mb-3">{{ __('Prognostizierte Mieteinnahmen') }}</h3>
                @include('financials._income-view-switcher')
                @if ($incomeView === 'tenant')
                    @include('financials._income-table-by-tenant')
                @elseif ($incomeView === 'tenant_split')
                    @include('financials._income-table-by-tenant-split')
                @else
                    @include('financials._income-table')
                @endif

                <h3 class="font-semibold mb-3 mt-8">{{ __('Gebuchte Mieteinnahmen') }}</h3>
                @if ($incomeView === 'tenant')
                    @include('financials._income-table-by-tenant', ['income' => $bookedIncome])
                @elseif ($incomeView === 'tenant_split')
                    @include('financials._income-table-by-tenant-split', ['income' => $bookedIncome])
                @else
                    @include('financials._income-table', ['income' => $bookedIncome])
                @endif

                <h3 class="font-semibold mb-3 mt-8">{{ __('Prognostizierte Ausgaben') }}</h3>
                @include('financials._expense-table')

                <h3 class="font-semibold mb-3 mt-8">{{ __('Gebuchte Ausgaben') }}</h3>
                @include('financials._expense-table', ['expenses' => $bookedExpenses])
            </div>

            <div id="leerstand" class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold mb-4">{{ __('Betriebskostenabrechnung') }}</h3>
                <div class="divide-y text-sm">
                    @forelse ($billingIntervals as $billingInterval)
                        <details class="py-2">
                            <summary class="cursor-pointer flex justify-between items-center">
                                <span>{{ $billingInterval->start_date->format('d.m.Y') }} &ndash; {{ $billingInterval->end_date->format('d.m.Y') }}</span>
                                <span class="space-x-3 text-gray-600">
                                    <a href="{{ route('billing-statements.show', [$managementUnit, $billingInterval]) }}" class="hover:underline" target="_blank">{{ __('Vorschau aller anzeigen') }}</a>
                                    <a href="{{ route('billing-statements.pdf', [$managementUnit, $billingInterval]) }}" class="hover:underline">{{ __('PDF (alle)') }}</a>
                                    <a href="{{ route('billing-statements.zip', [$managementUnit, $billingInterval]) }}" class="hover:underline">{{ __('ZIP (je Mieter)') }}</a>
                                </span>
                            </summary>

                            <details class="mt-3 ml-4">
                                <summary class="cursor-pointer text-gray-600">{{ __('Übersicht aller Buchungen') }}</summary>
                                @include('financials._bookings-by-category', ['bookings' => $bookingsByInterval[$billingInterval->id]])
                            </details>

                            <details class="mt-3 ml-4">
                                <summary class="cursor-pointer text-gray-600">{{ __('Leerstand') }}</summary>
                                @include('financials._vacancy-overview', ['vacancy' => $vacancyByInterval[$billingInterval->id], 'managementUnit' => $managementUnit, 'billingInterval' => $billingInterval])
                            </details>

                            <ul class="mt-3 pl-4 divide-y">
                                @forelse ($billingStatementLeases[$billingInterval->id] as $entry)
                                    <li class="py-2 flex justify-between items-center">
                                        <span>{{ $entry['tenantNames'] }} &ndash; {{ $entry['rentalUnit']->description ?: $entry['rentalUnit']->type }}</span>
                                        <span class="space-x-3 text-gray-600">
                                            <a href="{{ route('billing-statements.show-lease', [$managementUnit, $billingInterval, $entry['lease']]) }}" class="hover:underline" target="_blank">{{ __('Vorschau') }}</a>
                                            <a href="{{ route('billing-statements.pdf-lease', [$managementUnit, $billingInterval, $entry['lease']]) }}" class="hover:underline">{{ __('PDF') }}</a>
                                        </span>
                                    </li>
                                @empty
                                    <li class="py-2 text-gray-500">{{ __('Keine abrechnungsrelevanten Mieter in diesem Zeitraum.') }}</li>
                                @endforelse
                            </ul>
                        </details>
                    @empty
                        <p class="py-2 text-gray-500">{{ __('Keine Abrechnungsintervalle vorhanden.') }}</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
