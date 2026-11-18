<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Steuerübersicht') }} – {{ $managementGroup->description ?: __('Verwaltungsgruppe') . ' #' . $managementGroup->id }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[1475px] mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold mb-4">{{ __('Mieteinnahmen') }}</h3>

                @include('financials._year-switcher')

                @php($tableId = \Illuminate\Support\Str::random(8))
                <div id="tax-income-{{ $tableId }}" class="border border-gray-200 rounded-lg overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                            <tr>
                                <th class="px-4 py-3"></th>
                                <th class="px-4 py-3 text-right whitespace-nowrap">{{ __('Mieteinnahmen') }}</th>
                                <th class="px-4 py-3 text-right whitespace-nowrap">{{ __('Betriebskostenvorauszahlung') }}</th>
                                <th class="px-4 py-3 text-right whitespace-nowrap">{{ __('Nachzahlung / Rückzahlung') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr class="font-semibold bg-gray-50">
                                <td class="px-4 py-3">{{ __('Summe') }}</td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">{{ euro($income['totals']['rent']) }} €</td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">{{ euro($income['totals']['utility']) }} €</td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">{{ euro($income['totals']['settlement']) }} €</td>
                            </tr>
                            @forelse ($income['floors'] as $floorKey => $floor)
                                @php($detailClass = "tax-floor-{$tableId}-{$floorKey}")
                                <tr class="hover:bg-gray-50 cursor-pointer" data-expense-toggle data-target-class="{{ $detailClass }}">
                                    <td class="px-4 py-3">{{ $floor['label'] }}</td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap">{{ euro($floor['rent']) }} €</td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap">{{ euro($floor['utility']) }} €</td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap">{{ euro($floor['settlement']) }} €</td>
                                </tr>
                                @foreach ($floor['leases'] as $leaseRow)
                                    <tr class="hidden {{ $detailClass }} bg-gray-50">
                                        <td class="pl-8 pr-4 py-2 text-xs text-gray-500">{{ $leaseRow['label'] }}</td>
                                        <td class="px-4 py-2 text-right text-xs text-gray-500 whitespace-nowrap">{{ euro($leaseRow['rent']) }} €</td>
                                        <td class="px-4 py-2 text-right text-xs text-gray-500 whitespace-nowrap">{{ euro($leaseRow['utility']) }} €</td>
                                        <td class="px-4 py-2 text-right text-xs text-gray-500 whitespace-nowrap">{{ euro($leaseRow['settlement']) }} €</td>
                                    </tr>
                                @endforeach
                            @empty
                                <tr><td colspan="4" class="px-4 py-3 text-gray-500">{{ __('Keine Mieteinnahmen in diesem Jahr.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <script>
                    // Scoped to this table's own container, see financials/_expense-table.blade.php's
                    // own comment for why: an unscoped query would also match the Werbungskosten
                    // table further down the page, double-toggling its rows.
                    document.querySelectorAll('#tax-income-{{ $tableId }} [data-expense-toggle]').forEach(function (row) {
                        row.addEventListener('click', function () {
                            document.querySelectorAll('.' + row.dataset.targetClass).forEach(function (el) {
                                el.classList.toggle('hidden');
                            });
                        });
                    });
                </script>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold mb-4">{{ __('Werbungskosten') }}</h3>

                @include('financials._expense-table', ['expenses' => $expenses])
            </div>
        </div>
    </div>
</x-app-layout>
